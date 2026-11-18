<?php
/**
 * Printable HTML for payslips and payroll reports, plus CSV exports.
 * Each document has a "Print / Save as PDF" button, so any browser produces a PDF.
 */
class Renderers
{
    private static function e($v): string
    {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    }

    private static function kes($v): string
    {
        return number_format((float)$v, 2);
    }

    private static function period(int $m, int $y): string
    {
        return date('F Y', mktime(0, 0, 0, $m, 1, $y));
    }

    private static function page(string $title, string $body, bool $landscape = false): string
    {
        $size = $landscape ? 'A4 landscape' : 'A4';
        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . self::e($title) . '</title><style>'
            . "@page{size:$size;margin:14mm}"
            . 'body{font-family:"Segoe UI",Arial,sans-serif;color:#1f2937;margin:0;background:#f3f4f6}'
            . '.doc{max-width:' . ($landscape ? '1100px' : '780px') . ';margin:24px auto;background:#fff;padding:32px;box-shadow:0 1px 4px rgba(0,0,0,.08)}'
            . '.head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:3px solid #0f3d5e;padding-bottom:14px;margin-bottom:18px}'
            . '.brand{font-size:22px;font-weight:700;color:#0f3d5e}.muted{color:#6b7280;font-size:12px}'
            . 'h2{font-size:16px;margin:0 0 4px}h3{font-size:13px;text-transform:uppercase;letter-spacing:.04em;color:#0f3d5e;margin:18px 0 6px}'
            . 'table{width:100%;border-collapse:collapse;font-size:12.5px}td,th{padding:6px 8px;border-bottom:1px solid #e5e7eb;text-align:left}'
            . 'th{background:#f1f5f9;font-weight:600}.r{text-align:right;font-variant-numeric:tabular-nums}'
            . '.total td{font-weight:700;border-top:2px solid #0f3d5e}.grid{display:grid;grid-template-columns:1fr 1fr;gap:4px 24px;font-size:12.5px}'
            . '.net{margin-top:18px;background:#0f3d5e;color:#fff;padding:14px 18px;display:flex;justify-content:space-between;font-size:18px;font-weight:700;border-radius:6px}'
            . '.bar{max-width:' . ($landscape ? '1100px' : '780px') . ';margin:16px auto 0;text-align:right}'
            . 'button{background:#0f3d5e;color:#fff;border:0;padding:9px 16px;border-radius:6px;cursor:pointer;font-size:14px}'
            . '@media print{body{background:#fff}.doc{box-shadow:none;margin:0;padding:0;max-width:none}.bar{display:none}}'
            . '</style></head><body><div class="bar"><button onclick="window.print()">Print / Save as PDF</button></div>'
            . '<div class="doc">' . $body . '</div></body></html>';
    }

    // -----------------------------------------------------------------
    public static function payslip(array $p): string
    {
        $period = self::period((int)$p['period_month'], (int)$p['period_year']);
        $row = fn($label, $amount, $show = true) => $show
            ? '<tr><td>' . self::e($label) . '</td><td class="r">' . self::kes($amount) . '</td></tr>' : '';

        $earnings = $row('Basic salary', $p['basic_salary'])
            . $row('Housing allowance', $p['housing_allowance'], $p['housing_allowance'] > 0)
            . $row('Transport allowance', $p['transport_allowance'], $p['transport_allowance'] > 0)
            . $row('Medical allowance', $p['medical_allowance'], $p['medical_allowance'] > 0)
            . $row('Other allowances', $p['other_allowances'], $p['other_allowances'] > 0)
            . $row('Overtime (' . (float)$p['overtime_hours'] . ' h)', $p['overtime_pay'], $p['overtime_pay'] > 0)
            . ($p['absence_deduction'] > 0 ? '<tr><td>Unpaid absence (' . (float)$p['absent_days'] . ' days)</td><td class="r">-'
                . self::kes($p['absence_deduction']) . '</td></tr>' : '')
            . '<tr class="total"><td>Gross pay</td><td class="r">' . self::kes($p['gross_pay']) . '</td></tr>';

        $deductions = $row('NSSF (Tier I ' . self::kes($p['nssf_tier1']) . ' + Tier II ' . self::kes($p['nssf_tier2']) . ')', $p['nssf_employee'])
            . $row('SHIF', $p['shif'])
            . $row('Affordable Housing Levy', $p['housing_levy'])
            . $row('PAYE', $p['paye'])
            . $row('Other deductions', $p['other_deductions'], $p['other_deductions'] > 0)
            . '<tr class="total"><td>Total deductions</td><td class="r">' . self::kes($p['total_deductions']) . '</td></tr>';

        $tax = $row('Gross pay', $p['gross_pay'])
            . $row('Less allowable deductions', (float)$p['gross_pay'] - (float)$p['taxable_pay'])
            . $row('Taxable pay', $p['taxable_pay'])
            . $row('Tax charged', $p['paye_before_relief'])
            . $row('Less personal relief', $p['personal_relief'])
            . $row('PAYE', $p['paye']);

        $payTo = $p['payment_method'] === 'mpesa' ? 'M-Pesa ' . self::e($p['mpesa_number'])
            : ($p['payment_method'] === 'bank_transfer' ? self::e(trim($p['bank_name'] . ' ' . $p['bank_account'])) : ucfirst($p['payment_method']));

        $body = '<div class="head"><div><div class="brand">' . self::e($p['organization_name']) . '</div>'
            . '<div class="muted">' . self::e($p['org_address']) . ' &middot; KRA PIN ' . self::e($p['org_kra_pin']) . '</div></div>'
            . '<div style="text-align:right"><h2>PAYSLIP</h2><div class="muted">' . $period . '</div></div></div>'
            . '<div class="grid">'
            . '<div><b>Employee:</b> ' . self::e($p['first_name'] . ' ' . $p['last_name']) . '</div>'
            . '<div><b>Employee No:</b> ' . self::e($p['employee_number']) . '</div>'
            . '<div><b>Department:</b> ' . self::e($p['department']) . '</div>'
            . '<div><b>Position:</b> ' . self::e($p['position']) . '</div>'
            . '<div><b>KRA PIN:</b> ' . self::e($p['kra_pin']) . '</div>'
            . '<div><b>NSSF No:</b> ' . self::e($p['nssf_number']) . '</div>'
            . '<div><b>SHIF No:</b> ' . self::e($p['shif_number']) . '</div>'
            . '<div><b>Paid to:</b> ' . $payTo . '</div></div>'
            . '<h3>Earnings</h3><table><tr><th>Item</th><th class="r">KES</th></tr>' . $earnings . '</table>'
            . '<h3>Deductions</h3><table><tr><th>Item</th><th class="r">KES</th></tr>' . $deductions . '</table>'
            . '<h3>PAYE computation</h3><table>' . $tax . '</table>'
            . '<div class="net"><span>NET PAY</span><span>KES ' . self::kes($p['net_pay']) . '</span></div>'
            . '<p class="muted" style="margin-top:16px">Employer contributions (not deducted from your pay): NSSF KES '
            . self::kes($p['employer_nssf']) . ', Affordable Housing Levy KES ' . self::kes($p['employer_housing_levy'])
            . '.<br>Generated by PaySphere on ' . date('j M Y, H:i') . '.</p>';

        return self::page("Payslip $period - " . $p['first_name'] . ' ' . $p['last_name'], $body);
    }

    // -----------------------------------------------------------------
    private static function reportHead(array $org, string $title, array $run): string
    {
        return '<div class="head"><div><div class="brand">' . self::e($org['organization_name']) . '</div>'
            . '<div class="muted">KRA PIN ' . self::e($org['kra_pin']) . '</div></div>'
            . '<div style="text-align:right"><h2>' . self::e($title) . '</h2><div class="muted">'
            . self::period((int)$run['period_month'], (int)$run['period_year']) . ' &middot; status: '
            . self::e(ucfirst($run['status'])) . '</div></div></div>';
    }

    public static function summaryReport(array $org, array $run): string
    {
        $t = $run['totals'];
        $line = fn($l, $v) => '<tr><td>' . $l . '</td><td class="r">' . self::kes($v) . '</td></tr>';
        $body = self::reportHead($org, 'Payroll summary', $run)
            . '<p>Employees paid: <b>' . (int)$run['employee_count'] . '</b></p>'
            . '<h3>Employee pay</h3><table>'
            . $line('Total gross pay', $t['gross']) . $line('NSSF (employee)', $t['nssf']) . $line('SHIF', $t['shif'])
            . $line('Affordable Housing Levy (employee)', $t['housing_levy']) . $line('PAYE', $t['paye'])
            . $line('Other deductions', $t['other_deductions'])
            . '<tr class="total"><td>Total net pay</td><td class="r">' . self::kes($t['net']) . '</td></tr></table>'
            . '<h3>Employer cost</h3><table>'
            . $line('Gross pay', $t['gross']) . $line('NSSF (employer)', $t['employer_nssf'])
            . $line('Affordable Housing Levy (employer)', $t['employer_housing_levy'])
            . '<tr class="total"><td>Total cost to company</td><td class="r">'
            . self::kes($t['gross'] + $t['employer_nssf'] + $t['employer_housing_levy']) . '</td></tr></table>';
        return self::page('Payroll summary', $body);
    }

    public static function detailedReport(array $org, array $run): string
    {
        $rows = '';
        foreach ($run['payslips'] as $p) {
            $rows .= '<tr><td>' . self::e($p['employee_number']) . '</td><td>' . self::e($p['employee_name']) . '</td>'
                . '<td>' . self::e($p['department']) . '</td>';
            foreach (['basic_salary', 'gross_pay', 'nssf_employee', 'shif', 'housing_levy', 'paye', 'other_deductions', 'net_pay'] as $k) {
                $rows .= '<td class="r">' . self::kes($p[$k]) . '</td>';
            }
            $rows .= '</tr>';
        }
        $t = $run['totals'];
        $rows .= '<tr class="total"><td colspan="3">Totals</td><td></td>';
        foreach (['gross', 'nssf', 'shif', 'housing_levy', 'paye', 'other_deductions', 'net'] as $k) {
            $rows .= '<td class="r">' . self::kes($t[$k]) . '</td>';
        }
        $rows .= '</tr>';
        $body = self::reportHead($org, 'Payroll register', $run)
            . '<table><tr><th>No.</th><th>Employee</th><th>Department</th><th class="r">Basic</th><th class="r">Gross</th>'
            . '<th class="r">NSSF</th><th class="r">SHIF</th><th class="r">AHL</th><th class="r">PAYE</th>'
            . '<th class="r">Other</th><th class="r">Net pay</th></tr>' . $rows . '</table>';
        return self::page('Payroll register', $body, true);
    }

    public static function statutoryReport(array $org, array $run): string
    {
        $rows = '';
        foreach ($run['payslips'] as $p) {
            $rows .= '<tr><td>' . self::e($p['employee_name']) . '</td><td>' . self::e($p['kra_pin']) . '</td>'
                . '<td class="r">' . self::kes($p['taxable_pay']) . '</td><td class="r">' . self::kes($p['paye']) . '</td>'
                . '<td class="r">' . self::kes($p['nssf_employee']) . '</td><td class="r">' . self::kes($p['employer_nssf']) . '</td>'
                . '<td class="r">' . self::kes($p['shif']) . '</td>'
                . '<td class="r">' . self::kes($p['housing_levy']) . '</td><td class="r">' . self::kes($p['employer_housing_levy']) . '</td></tr>';
        }
        $t = $run['totals'];
        $rows .= '<tr class="total"><td colspan="3">Totals</td><td class="r">' . self::kes($t['paye']) . '</td>'
            . '<td class="r">' . self::kes($t['nssf']) . '</td><td class="r">' . self::kes($t['employer_nssf']) . '</td>'
            . '<td class="r">' . self::kes($t['shif']) . '</td><td class="r">' . self::kes($t['housing_levy']) . '</td>'
            . '<td class="r">' . self::kes($t['employer_housing_levy']) . '</td></tr>';

        $line = fn($l, $v, $to) => '<tr><td>' . $l . '</td><td>' . $to . '</td><td class="r">' . self::kes($v) . '</td></tr>';
        $body = self::reportHead($org, 'Statutory deductions', $run)
            . '<h3>Amounts to remit (due by the 9th of the following month)</h3><table>'
            . '<tr><th>Deduction</th><th>Remit to</th><th class="r">KES</th></tr>'
            . $line('PAYE', $t['paye'], 'KRA (iTax P10)')
            . $line('NSSF (employee + employer)', $t['nssf'] + $t['employer_nssf'], 'NSSF')
            . $line('SHIF', $t['shif'], 'Social Health Authority')
            . $line('Affordable Housing Levy (employee + employer)', $t['housing_levy'] + $t['employer_housing_levy'], 'KRA (iTax)')
            . '</table><h3>Per employee</h3><table><tr><th>Employee</th><th>KRA PIN</th><th class="r">Taxable</th>'
            . '<th class="r">PAYE</th><th class="r">NSSF ee</th><th class="r">NSSF er</th><th class="r">SHIF</th>'
            . '<th class="r">AHL ee</th><th class="r">AHL er</th></tr>' . $rows . '</table>'
            . '<p class="muted">Check the remittance deadlines against current KRA, NSSF and SHA notices.</p>';
        return self::page('Statutory deductions', $body, true);
    }

    public static function statutoryCsv(array $run): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Employee No', 'Employee', 'KRA PIN', 'Gross pay', 'Taxable pay', 'PAYE', 'NSSF employee',
            'NSSF employer', 'SHIF', 'Housing levy employee', 'Housing levy employer', 'Net pay']);
        foreach ($run['payslips'] as $p) {
            fputcsv($out, [$p['employee_number'], $p['employee_name'], $p['kra_pin'], $p['gross_pay'], $p['taxable_pay'],
                $p['paye'], $p['nssf_employee'], $p['employer_nssf'], $p['shif'], $p['housing_levy'],
                $p['employer_housing_levy'], $p['net_pay']]);
        }
        rewind($out);
        return (string)stream_get_contents($out);
    }
}
