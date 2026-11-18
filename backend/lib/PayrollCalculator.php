<?php
/**
 * PayrollCalculator: the PaySphere payroll engine.
 *
 * Pure computation: no database, no HTTP. Given one employee's salary structure,
 * that month's attendance and the payroll period, it returns every line of the
 * payslip. Because it has no side effects, it is unit-tested directly by
 * tests/run_tests.php against hand-computed statutory values.
 *
 * Order of computation (Chapter Three, Section 3.4.2, algorithm design):
 *   1. gross     = basic + allowances + overtime - unpaid absence
 *   2. NSSF      = 6% of Tier I + 6% of Tier II pensionable pay
 *   3. SHIF      = 2.75% of gross, minimum KES 300
 *   4. AHL       = 1.5% of gross
 *   5. taxable   = gross - NSSF - SHIF - AHL (whichever are tax-deductible in the period)
 *   6. PAYE      = max(0, band tax on taxable pay - personal relief)
 *   7. net       = gross - (NSSF + SHIF + AHL + PAYE + other deductions)
 */
class PayrollCalculator
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? require __DIR__ . '/../config/payroll_config.php';
    }

    /**
     * @param array $salary     basic_salary, housing_allowance, transport_allowance,
     *                          medical_allowance, other_allowances, other_deductions
     * @param array $attendance overtime_hours, absent_days
     * @param int   $month      1-12
     * @param int   $year       e.g. 2026
     */
    public function calculate(array $salary, array $attendance, int $month, int $year): array
    {
        $period = sprintf('%04d-%02d-01', $year, $month);
        $time   = $this->config['working_time'];

        // 1. Earnings
        $basic     = $this->money($salary['basic_salary'] ?? 0);
        $housing   = $this->money($salary['housing_allowance'] ?? 0);
        $transport = $this->money($salary['transport_allowance'] ?? 0);
        $medical   = $this->money($salary['medical_allowance'] ?? 0);
        $other     = $this->money($salary['other_allowances'] ?? 0);

        $overtimeHours = max(0.0, (float)($attendance['overtime_hours'] ?? 0));
        $absentDays    = max(0.0, (float)($attendance['absent_days'] ?? 0));

        $hourlyRate  = $basic / $time['hours_per_month'];
        $dailyRate   = $basic / $time['days_per_month'];
        $overtimePay = $this->round($overtimeHours * $hourlyRate * $time['overtime_rate']);
        // An employee can never lose more than their basic salary for absence.
        $absenceDeduction = $this->round(min($basic, $absentDays * $dailyRate));

        $gross = $this->round(max(0.0,
            $basic + $housing + $transport + $medical + $other + $overtimePay - $absenceDeduction));

        // 2. NSSF
        $nssfRule = $this->ruleFor('nssf', $period);
        $tier1Pay = min($gross, $nssfRule['lel']);
        $tier2Pay = max(0.0, min($gross, $nssfRule['uel']) - $nssfRule['lel']);
        $nssfTier1 = $this->round($tier1Pay * $nssfRule['rate']);
        $nssfTier2 = $this->round($tier2Pay * $nssfRule['rate']);
        $nssf = $this->round($nssfTier1 + $nssfTier2);

        // 3. SHIF (only charged when there is pay to charge it on)
        $shifRule = $this->ruleFor('shif', $period);
        $shif = $gross > 0 ? $this->round(max($gross * $shifRule['rate'], $shifRule['minimum'])) : 0.0;

        // 4. Affordable Housing Levy
        $ahlRule = $this->ruleFor('housing_levy', $period);
        $housingLevy = $this->round($gross * $ahlRule['employee_rate']);

        // 5. Taxable pay
        $deductible = $this->ruleFor('tax_deductible', $period);
        $taxable = $gross
            - ($deductible['nssf'] ? $nssf : 0)
            - ($deductible['shif'] ? $shif : 0)
            - ($deductible['housing_levy'] ? $housingLevy : 0);
        $taxable = $this->round(max(0.0, $taxable));

        // 6. PAYE
        $payeRule = $this->ruleFor('paye', $period);
        $payeBeforeRelief = $this->bandTax($taxable, $payeRule['bands']);
        $relief = $payeRule['personal_relief'];
        $paye = $this->round(max(0.0, $payeBeforeRelief - $relief));
        $reliefApplied = $this->round(min($relief, $payeBeforeRelief));

        // 7. Net pay
        $otherDeductions = $this->money($salary['other_deductions'] ?? 0);
        $totalDeductions = $this->round($nssf + $shif + $housingLevy + $paye + $otherDeductions);
        $net = $this->round($gross - $totalDeductions);

        return [
            'basic_salary'          => $basic,
            'housing_allowance'     => $housing,
            'transport_allowance'   => $transport,
            'medical_allowance'     => $medical,
            'other_allowances'      => $other,
            'overtime_hours'        => $overtimeHours,
            'overtime_pay'          => $overtimePay,
            'absent_days'           => $absentDays,
            'absence_deduction'     => $absenceDeduction,
            'gross_pay'             => $gross,
            'nssf_tier1'            => $nssfTier1,
            'nssf_tier2'            => $nssfTier2,
            'nssf_employee'         => $nssf,
            'shif'                  => $shif,
            'housing_levy'          => $housingLevy,
            'taxable_pay'           => $taxable,
            'paye_before_relief'    => $payeBeforeRelief,
            'personal_relief'       => $reliefApplied,
            'paye'                  => $paye,
            'other_deductions'      => $otherDeductions,
            'total_deductions'      => $totalDeductions,
            'net_pay'               => $net,
            'employer_nssf'         => $nssf, // employer matches the employee contribution
            'employer_housing_levy' => $this->round($gross * $ahlRule['employer_rate']),
        ];
    }

    /** Progressive tax across cumulative bands. */
    public function bandTax(float $income, array $bands): float
    {
        $tax = 0.0;
        $lower = 0.0;
        foreach ($bands as $band) {
            if ($income <= $lower) {
                break;
            }
            $upper = $band['upto'] ?? INF;
            $tax += (min($income, $upper) - $lower) * $band['rate'];
            $lower = $upper;
        }
        return $this->round($tax);
    }

    /** The newest rule of a kind that is in force on $date (Y-m-d). */
    public function ruleFor(string $kind, string $date): array
    {
        $chosen = null;
        foreach ($this->config[$kind] as $rule) {
            if ($rule['effective_from'] <= $date &&
                ($chosen === null || $rule['effective_from'] > $chosen['effective_from'])) {
                $chosen = $rule;
            }
        }
        if ($chosen === null) {
            throw new InvalidArgumentException("No $kind rule is configured for $date.");
        }
        return $chosen;
    }

    /** Rates in force for a period, for display on the Settings page. */
    public function rulesFor(int $month, int $year): array
    {
        $period = sprintf('%04d-%02d-01', $year, $month);
        $out = [];
        foreach (['paye', 'nssf', 'shif', 'housing_levy', 'tax_deductible'] as $kind) {
            $out[$kind] = $this->ruleFor($kind, $period);
        }
        $out['working_time'] = $this->config['working_time'];
        return $out;
    }

    private function money($value): float
    {
        return $this->round(max(0.0, (float)$value));
    }

    private function round(float $value): float
    {
        return round($value + 0.0, 2);
    }
}
