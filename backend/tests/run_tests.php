<?php
/**
 * PaySphere payroll engine tests.  Run:  php backend/tests/run_tests.php
 *
 * Every expected value below was computed by hand from the statutory rates in
 * config/payroll_config.php, so these tests are the evidence for test cases
 * TC-11 to TC-17 in Chapter Four (Table 2). Re-run them whenever a rate changes.
 */

require_once __DIR__ . '/../lib/PayrollCalculator.php';

$calc = new PayrollCalculator();
$passed = 0;
$failed = 0;

function check(string $name, $expected, $actual): void
{
    global $passed, $failed;
    if (abs((float)$expected - (float)$actual) < 0.005) {
        $passed++;
        echo "  PASS  $name\n";
    } else {
        $failed++;
        echo "  FAIL  $name: expected $expected, got $actual\n";
    }
}

function pay(float $basic, int $month = 3, int $year = 2026, array $extra = [], array $att = []): array
{
    global $calc;
    return $calc->calculate(array_merge(['basic_salary' => $basic], $extra), $att, $month, $year);
}

echo "TC-11  Gross pay = basic + allowances\n";
$p = pay(40000, 3, 2026, ['housing_allowance' => 6000, 'transport_allowance' => 3000, 'medical_allowance' => 1000]);
check('gross 40,000 + 10,000 allowances', 50000.00, $p['gross_pay']);

echo "TC-12  Overtime at 1.5x and unpaid absence\n";
$p = pay(45000, 3, 2026, [], ['overtime_hours' => 10, 'absent_days' => 2]);
check('overtime pay (10 h x 45,000/225 x 1.5)', 3000.00, $p['overtime_pay']);
check('absence deduction (2 x 45,000/22)', 4090.91, $p['absence_deduction']);
check('gross pay', 43909.09, $p['gross_pay']);

echo "TC-13  PAYE across the five bands (March 2026)\n";
$p = pay(50000);
check('50,000: NSSF', 3000.00, $p['nssf_employee']);
check('50,000: SHIF', 1375.00, $p['shif']);
check('50,000: Housing levy', 750.00, $p['housing_levy']);
check('50,000: taxable pay', 44875.00, $p['taxable_pay']);
check('50,000: PAYE', 5845.85, $p['paye']);
check('50,000: net pay', 39029.15, $p['net_pay']);

$p = pay(200000);
check('200,000: PAYE', 47889.35, $p['paye']);
check('200,000: net pay', 137130.65, $p['net_pay']);

$p = pay(900000);
check('900,000: taxable pay', 855270.00, $p['taxable_pay']);
check('900,000: PAYE (32.5% and 35% bands)', 259227.85, $p['paye']);

check('band tax at 24,000 (top of band 1)', 2400.00, $calc->bandTax(24000, $calc->ruleFor('paye', '2026-03-01')['bands']));
check('band tax at 32,333 (top of band 2)', 4483.25, $calc->bandTax(32333, $calc->ruleFor('paye', '2026-03-01')['bands']));

echo "TC-14  PAYE is never negative when band tax is below relief\n";
$p = pay(20000);
check('20,000: PAYE', 0.00, $p['paye']);
check('20,000: relief applied only up to tax due', 1795.00, $p['personal_relief']);
check('20,000: net pay', 17950.00, $p['net_pay']);

echo "TC-15  SHIF minimum of KES 300\n";
$p = pay(8000);
check('8,000: SHIF (2.75% = 220, raised to 300)', 300.00, $p['shif']);
check('8,000: net pay', 7100.00, $p['net_pay']);

echo "TC-16  NSSF tiers, cap and date-effective schedule\n";
$p = pay(8000);
check('below LEL: Tier I only', 480.00, $p['nssf_tier1']);
check('below LEL: no Tier II', 0.00, $p['nssf_tier2']);
$p = pay(200000);
check('above UEL (2026): capped at 6,480', 6480.00, $p['nssf_employee']);
$p = pay(100000, 3, 2025);
check('100,000 in March 2025 (UEL 72,000)', 4320.00, $p['nssf_employee']);
$p = pay(100000, 3, 2026);
check('100,000 in March 2026 (UEL 108,000)', 6000.00, $p['nssf_employee']);

echo "TC-17  Taxable pay deducts NSSF, SHIF and AHL (from January 2025)\n";
$p = pay(50000, 11, 2024);
check('Nov 2024: only NSSF deductible, taxable', 47840.00, $p['taxable_pay']);
check('Nov 2024: PAYE', 6735.35, $p['paye']);
$p = pay(50000, 3, 2026);
check('Mar 2026: taxable = 50,000 - 3,000 - 1,375 - 750', 44875.00, $p['taxable_pay']);

echo "       Other deductions and employer cost\n";
$p = pay(50000, 3, 2026, ['other_deductions' => 2000]);
check('other deductions reduce net pay', 37029.15, $p['net_pay']);
check('employer NSSF matches employee', 3000.00, $p['employer_nssf']);
check('employer housing levy', 750.00, $p['employer_housing_levy']);

echo "       Edge cases\n";
$p = pay(10000, 3, 2026, [], ['absent_days' => 40]);
check('absence never exceeds basic salary', 10000.00, $p['absence_deduction']);
check('zero gross: no SHIF charged', 0.00, $p['shif']);
check('zero gross: net pay', 0.00, $p['net_pay']);

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
