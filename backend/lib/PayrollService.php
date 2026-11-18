<?php
/**
 * PayrollService: runs the PayrollCalculator for an organisation's month and
 * manages the payroll lifecycle  draft -> approved -> paid.
 */
class PayrollService
{
    /**
     * Generate (or regenerate) the payroll run for a month.
     * A draft run can be regenerated to pick up corrections; approved or paid runs are locked.
     */
    public static function generate(array $user, int $month, int $year): array
    {
        if ($month < 1 || $month > 12 || $year < 2024 || $year > 2100) {
            throw new ApiError('Choose a valid payroll month.', 422);
        }
        $periodStart = sprintf('%04d-%02d-01', $year, $month);
        $periodEnd = date('Y-m-t', strtotime($periodStart));
        if ($periodStart > date('Y-m-01', strtotime('+1 month'))) {
            throw new ApiError('Payroll cannot be generated more than one month ahead.', 422);
        }

        $orgId = $user['organization_id'];
        $existing = q('SELECT * FROM payroll_runs WHERE organization_id = ? AND period_month = ? AND period_year = ?',
            [$orgId, $month, $year])->fetch();
        if ($existing && $existing['status'] !== 'draft') {
            throw new ApiError("Payroll for this month is already {$existing['status']} and cannot be regenerated.", 409);
        }

        // Active employees employed by the end of the month, with the salary in force at month end.
        $employees = q("SELECT e.id, e.payment_method,
                               s.basic_salary, s.housing_allowance, s.transport_allowance, s.medical_allowance,
                               s.other_allowances, s.other_deductions
                        FROM employees e
                        JOIN salary_structures s ON s.id = (
                            SELECT s2.id FROM salary_structures s2
                            WHERE s2.employee_id = e.id AND s2.is_active = 1 AND s2.effective_date <= ?
                            ORDER BY s2.effective_date DESC, s2.id DESC LIMIT 1)
                        WHERE e.organization_id = ? AND e.status = 'active' AND e.hire_date <= ?
                        ORDER BY e.employee_number", [$periodEnd, $orgId, $periodEnd])->fetchAll();

        if (!$employees) {
            throw new ApiError('No active employees with a salary structure were found for this month.', 422);
        }

        // Attendance totals for the month, one query for everybody.
        $attendance = [];
        foreach (q("SELECT a.employee_id,
                           SUM(a.overtime_hours) AS overtime_hours,
                           SUM(CASE a.status WHEN 'absent' THEN 1 WHEN 'half_day' THEN 0.5 ELSE 0 END) AS absent_days
                    FROM attendance a JOIN employees e ON e.id = a.employee_id
                    WHERE e.organization_id = ? AND a.attendance_date BETWEEN ? AND ?
                    GROUP BY a.employee_id", [$orgId, $periodStart, $periodEnd])->fetchAll() as $row) {
            $attendance[(int)$row['employee_id']] = $row;
        }

        $started = microtime(true);
        $calc = new PayrollCalculator();
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($existing) {
                $runId = (int)$existing['id'];
                q('DELETE FROM payslips WHERE payroll_run_id = ?', [$runId]);
            } else {
                q('INSERT INTO payroll_runs (organization_id, period_month, period_year, status) VALUES (?, ?, ?, ?)',
                    [$orgId, $month, $year, 'draft']);
                $runId = (int)$pdo->lastInsertId();
            }

            $insert = $pdo->prepare('INSERT INTO payslips
                (payroll_run_id, employee_id, basic_salary, housing_allowance, transport_allowance, medical_allowance,
                 other_allowances, overtime_hours, overtime_pay, absent_days, absence_deduction, gross_pay,
                 nssf_tier1, nssf_tier2, nssf_employee, shif, housing_levy, taxable_pay, paye_before_relief,
                 personal_relief, paye, other_deductions, total_deductions, net_pay, employer_nssf,
                 employer_housing_levy, payment_method)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

            $totals = ['gross' => 0.0, 'deductions' => 0.0, 'net' => 0.0];
            foreach ($employees as $emp) {
                $r = $calc->calculate($emp, $attendance[(int)$emp['id']] ?? [], $month, $year);
                $insert->execute([
                    $runId, $emp['id'], $r['basic_salary'], $r['housing_allowance'], $r['transport_allowance'],
                    $r['medical_allowance'], $r['other_allowances'], $r['overtime_hours'], $r['overtime_pay'],
                    $r['absent_days'], $r['absence_deduction'], $r['gross_pay'], $r['nssf_tier1'], $r['nssf_tier2'],
                    $r['nssf_employee'], $r['shif'], $r['housing_levy'], $r['taxable_pay'], $r['paye_before_relief'],
                    $r['personal_relief'], $r['paye'], $r['other_deductions'], $r['total_deductions'], $r['net_pay'],
                    $r['employer_nssf'], $r['employer_housing_levy'], $emp['payment_method'],
                ]);
                $totals['gross'] += $r['gross_pay'];
                $totals['deductions'] += $r['total_deductions'];
                $totals['net'] += $r['net_pay'];
            }

            $ms = (int)round((microtime(true) - $started) * 1000);
            q('UPDATE payroll_runs SET employee_count = ?, total_gross = ?, total_deductions = ?, total_net = ?,
                   generated_by = ?, generated_at = NOW(), processing_ms = ? WHERE id = ?',
                [count($employees), round($totals['gross'], 2), round($totals['deductions'], 2),
                 round($totals['net'], 2), $user['id'], $ms, $runId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Audit::log($user, $existing ? 'payroll.regenerated' : 'payroll.generated', 'payroll_run', $runId,
            ['month' => $month, 'year' => $year, 'employees' => count($employees), 'total_net' => round($totals['net'], 2)]);

        return self::run($user, $runId);
    }

    /** A run with its payslips (HR view). */
    public static function run(array $user, int $runId): array
    {
        $run = self::findRun($user, $runId);
        $run['payslips'] = q("SELECT p.*, e.employee_number, CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
                                     d.name AS department, e.kra_pin, e.mpesa_number
                              FROM payslips p
                              JOIN employees e ON e.id = p.employee_id
                              LEFT JOIN departments d ON d.id = e.department_id
                              WHERE p.payroll_run_id = ? ORDER BY e.employee_number", [$runId])->fetchAll();
        $run['totals'] = q('SELECT SUM(gross_pay) gross, SUM(nssf_employee) nssf, SUM(shif) shif,
                                   SUM(housing_levy) housing_levy, SUM(paye) paye, SUM(other_deductions) other_deductions,
                                   SUM(total_deductions) deductions, SUM(net_pay) net,
                                   SUM(employer_nssf) employer_nssf, SUM(employer_housing_levy) employer_housing_levy
                            FROM payslips WHERE payroll_run_id = ?', [$runId])->fetch();
        return $run;
    }

    public static function findRun(array $user, int $runId): array
    {
        $run = q('SELECT * FROM payroll_runs WHERE id = ? AND organization_id = ?', [$runId, $user['organization_id']])->fetch();
        if (!$run) {
            throw new ApiError('Payroll run not found.', 404);
        }
        return $run;
    }

    public static function approve(array $user, int $runId): array
    {
        $run = self::findRun($user, $runId);
        if ($run['status'] !== 'draft') {
            throw new ApiError("Only a draft payroll can be approved; this one is {$run['status']}.", 409);
        }
        q("UPDATE payroll_runs SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?", [$user['id'], $runId]);
        Audit::log($user, 'payroll.approved', 'payroll_run', $runId, ['month' => $run['period_month'], 'year' => $run['period_year']]);
        return self::run($user, $runId);
    }

    /**
     * Record that salaries were paid. Payments are made through the organisation's
     * own M-Pesa business account or bank, then recorded here with a reference.
     */
    public static function markPaid(array $user, int $runId, array $references = []): array
    {
        $run = self::findRun($user, $runId);
        if ($run['status'] !== 'approved') {
            throw new ApiError('Payroll must be approved before payment is recorded.', 409);
        }
        $pdo = db();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE payslips SET payment_status = 'paid', paid_at = NOW(), payment_reference = ?
                               WHERE payroll_run_id = ? AND id = ?");
        foreach (q('SELECT id FROM payslips WHERE payroll_run_id = ?', [$runId])->fetchAll() as $p) {
            $ref = clean($references[$p['id']] ?? null, 60);
            $stmt->execute([$ref, $runId, $p['id']]);
        }
        q("UPDATE payroll_runs SET status = 'paid', paid_by = ?, paid_at = NOW() WHERE id = ?", [$user['id'], $runId]);
        $pdo->commit();
        Audit::log($user, 'payroll.paid', 'payroll_run', $runId, ['month' => $run['period_month'], 'year' => $run['period_year']]);
        return self::run($user, $runId);
    }

    /** One payslip with employee and organisation details, checked against the viewer. */
    public static function payslip(array $user, int $payslipId): array
    {
        $p = q("SELECT p.*, r.period_month, r.period_year, r.status AS run_status, r.organization_id,
                       e.employee_number, e.first_name, e.last_name, e.kra_pin, e.nssf_number, e.shif_number,
                       e.national_id, COALESCE(e.work_email, e.personal_email) AS email, e.mpesa_number,
                       e.bank_name, e.bank_account,
                       d.name AS department, pos.title AS position,
                       o.organization_name, o.kra_pin AS org_kra_pin, o.address AS org_address, o.phone AS org_phone,
                       o.email AS org_email
                FROM payslips p
                JOIN payroll_runs r ON r.id = p.payroll_run_id
                JOIN employees e ON e.id = p.employee_id
                JOIN organizations o ON o.id = r.organization_id
                LEFT JOIN departments d ON d.id = e.department_id
                LEFT JOIN positions pos ON pos.id = e.position_id
                WHERE p.id = ?", [$payslipId])->fetch();

        $allowed = $p && (int)$p['organization_id'] === $user['organization_id'] && (
            ($user['user_type'] === 'employer' && Auth::can($user, 'payroll.view')) ||
            ($user['user_type'] === 'employee' && (int)$p['employee_id'] === $user['employee_id'] && $p['run_status'] !== 'draft')
        );
        if (!$allowed) {
            throw new ApiError('Payslip not found.', 404);
        }
        return $p;
    }

    /** Email every payslip in an approved or paid run to the employees. */
    public static function emailPayslips(array $user, int $runId): array
    {
        $run = self::findRun($user, $runId);
        if ($run['status'] === 'draft') {
            throw new ApiError('Approve the payroll before sending payslips.', 409);
        }
        require_once __DIR__ . '/Renderers.php';
        $sent = 0;
        $skipped = 0;
        foreach (q('SELECT id FROM payslips WHERE payroll_run_id = ?', [$runId])->fetchAll() as $row) {
            $p = self::payslip($user, (int)$row['id']);
            if (!$p['email']) {
                $skipped++;
                continue;
            }
            $period = date('F Y', mktime(0, 0, 0, (int)$p['period_month'], 1, (int)$p['period_year']));
            $body = Mailer::layout("Your payslip for $period",
                '<p>Dear ' . htmlspecialchars($p['first_name']) . ',</p>'
                . '<p>Your payslip for ' . $period . ' is attached. Net pay: <strong>KES '
                . number_format((float)$p['net_pay'], 2) . '</strong>.</p>'
                . '<p>You can also view all your payslips in the PaySphere employee portal.</p>');
            $html = Renderers::payslip($p);
            if (Mailer::send($p['email'], "Payslip - $period", $body, ["Payslip-$period.html" => $html])) {
                q('UPDATE payslips SET emailed_at = NOW() WHERE id = ?', [$p['id']]);
                $sent++;
            } else {
                $skipped++;
            }
        }
        Audit::log($user, 'payroll.payslips_emailed', 'payroll_run', $runId, ['sent' => $sent, 'skipped' => $skipped]);
        return ['sent' => $sent, 'skipped' => $skipped];
    }
}
