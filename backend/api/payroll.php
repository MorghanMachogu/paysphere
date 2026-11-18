<?php
/**
 * Payroll processing (Module 3), payslips (Module 6) and compliance reports (Module 4).
 *   GET  ?action=runs                                  list payroll runs
 *   GET  ?action=run&id=3   or  &month=3&year=2026      one run with its payslips
 *   POST ?action=generate        {month, year}          generate / regenerate a draft run
 *   POST ?action=approve         {run_id}               draft -> approved
 *   POST ?action=pay             {run_id, references}   approved -> paid (records payment)
 *   POST ?action=email_payslips  {run_id}
 *   GET  ?action=payslip&id=17                         printable payslip (HTML)
 *   GET  ?action=my_payslips                           employee: own payslips
 *   GET  ?action=report&type=summary|detailed|statutory&run_id=3[&format=csv]
 *   GET  ?action=rates&month=3&year=2026               statutory rates in force for a month
 */
require_once __DIR__ . '/../lib/bootstrap.php';

run(function () {
    $action = action();
    $in = method() === 'POST' ? input() : [];

    switch ($action) {
        case 'runs':
            $user = Auth::requirePermission('payroll.view');
            ok(q('SELECT * FROM payroll_runs WHERE organization_id = ? ORDER BY period_year DESC, period_month DESC',
                [$user['organization_id']])->fetchAll());

        case 'run':
            $user = Auth::requirePermission('payroll.view');
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) {
                $id = (int)q('SELECT id FROM payroll_runs WHERE organization_id = ? AND period_month = ? AND period_year = ?',
                    [$user['organization_id'], (int)($_GET['month'] ?? 0), (int)($_GET['year'] ?? 0)])->fetchColumn();
                if (!$id) {
                    ok(null, 'No payroll has been generated for this month yet.');
                }
            }
            ok(PayrollService::run($user, $id));

        case 'generate':
            $user = Auth::requirePermission('payroll.manage');
            require_fields($in, ['month', 'year']);
            $run = PayrollService::generate($user, (int)$in['month'], (int)$in['year']);
            ok($run, "Payroll generated for {$run['employee_count']} employees in {$run['processing_ms']} ms.");

        case 'approve':
            $user = Auth::requirePermission('payroll.approve');
            require_fields($in, ['run_id']);
            ok(PayrollService::approve($user, (int)$in['run_id']), 'Payroll approved');

        case 'pay':
            $user = Auth::requirePermission('payroll.manage');
            require_fields($in, ['run_id']);
            ok(PayrollService::markPaid($user, (int)$in['run_id'], (array)($in['references'] ?? [])), 'Payment recorded');

        case 'email_payslips':
            $user = Auth::requirePermission('payroll.manage');
            require_fields($in, ['run_id']);
            $r = PayrollService::emailPayslips($user, (int)$in['run_id']);
            ok($r, "{$r['sent']} payslip(s) emailed" . ($r['skipped'] ? ", {$r['skipped']} skipped (no email address)" : ''));

        case 'payslip':
            $user = Auth::user();
            html_out(Renderers::payslip(PayrollService::payslip($user, (int)($_GET['id'] ?? 0))));

        case 'my_payslips':
            $user = Auth::requireEmployee();
            ok(q("SELECT p.id, r.period_month, r.period_year, p.gross_pay, p.total_deductions, p.paye, p.nssf_employee,
                         p.shif, p.housing_levy, p.net_pay, p.payment_status, p.payment_method, p.paid_at
                  FROM payslips p JOIN payroll_runs r ON r.id = p.payroll_run_id
                  WHERE p.employee_id = ? AND r.status <> 'draft'
                  ORDER BY r.period_year DESC, r.period_month DESC", [$user['employee_id']])->fetchAll());

        case 'report':
            $user = Auth::requirePermission('reports.view');
            $run = PayrollService::run($user, (int)($_GET['run_id'] ?? 0));
            $org = q('SELECT * FROM organizations WHERE id = ?', [$user['organization_id']])->fetch();
            $type = $_GET['type'] ?? 'summary';
            if (($_GET['format'] ?? '') === 'csv') {
                $name = sprintf('statutory-%04d-%02d.csv', $run['period_year'], $run['period_month']);
                header('Content-Type: text/csv; charset=utf-8');
                header("Content-Disposition: attachment; filename=\"$name\"");
                echo Renderers::statutoryCsv($run);
                exit;
            }
            if ($type === 'detailed') {
                html_out(Renderers::detailedReport($org, $run));
            }
            if ($type === 'statutory') {
                html_out(Renderers::statutoryReport($org, $run));
            }
            html_out(Renderers::summaryReport($org, $run));

        case 'rates':
            Auth::user();
            $month = (int)($_GET['month'] ?? date('n'));
            $year = (int)($_GET['year'] ?? date('Y'));
            ok((new PayrollCalculator())->rulesFor($month, $year));
    }

    throw new ApiError('Unknown action.', 404);
});
