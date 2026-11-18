<?php
/**
 * PaySphere statutory payroll configuration (Kenya).
 *
 * Every rate the payroll engine uses lives in this file, so a change in the law
 * is a configuration change, not a code change. Rules that change over time are
 * listed with an `effective_from` date; the engine picks the newest rule that is
 * in force on the first day of the payroll month.
 *
 * WHEN THE LAW CHANGES: add a new entry with its effective date (never edit an
 * old one, or historical payslips can no longer be reproduced), then run
 *   php backend/tests/run_tests.php
 * and update the expected values in the statutory test cases.
 */

return [

    // -----------------------------------------------------------------
    // PAYE (Income Tax Act, as amended by the Finance Act 2023).
    // Monthly bands: `upto` is the cumulative upper limit of the band.
    // -----------------------------------------------------------------
    'paye' => [
        [
            'effective_from'  => '2023-07-01',
            'personal_relief' => 2400.00,
            'bands' => [
                ['upto' => 24000.00,  'rate' => 0.10],
                ['upto' => 32333.00,  'rate' => 0.25],
                ['upto' => 500000.00, 'rate' => 0.30],
                ['upto' => 800000.00, 'rate' => 0.325],
                ['upto' => null,      'rate' => 0.35],   // null = no upper limit
            ],
        ],
    ],

    // -----------------------------------------------------------------
    // NSSF (NSSF Act 2013, Third Schedule). 6% employee + 6% employer.
    // Tier I  = earnings up to the Lower Earnings Limit (LEL)
    // Tier II = earnings between the LEL and the Upper Earnings Limit (UEL)
    // -----------------------------------------------------------------
    'nssf' => [
        ['effective_from' => '2024-02-01', 'rate' => 0.06, 'lel' => 7000.00, 'uel' => 36000.00],  // Year 2
        ['effective_from' => '2025-02-01', 'rate' => 0.06, 'lel' => 8000.00, 'uel' => 72000.00],  // Year 3
        // Year 4. VERIFY against the current NSSF notice before go-live.
        ['effective_from' => '2026-02-01', 'rate' => 0.06, 'lel' => 9000.00, 'uel' => 108000.00],
    ],

    // -----------------------------------------------------------------
    // SHIF (Social Health Insurance Act 2023): 2.75% of gross, minimum KES 300.
    // -----------------------------------------------------------------
    'shif' => [
        ['effective_from' => '2024-10-01', 'rate' => 0.0275, 'minimum' => 300.00],
    ],

    // -----------------------------------------------------------------
    // Affordable Housing Levy (Affordable Housing Act 2024):
    // 1.5% of gross from the employee, matched 1.5% by the employer.
    // -----------------------------------------------------------------
    'housing_levy' => [
        ['effective_from' => '2024-03-19', 'employee_rate' => 0.015, 'employer_rate' => 0.015],
    ],

    // -----------------------------------------------------------------
    // What is deducted from gross pay before PAYE bands are applied.
    // The Tax Laws (Amendment) Act 2024 made SHIF and the Housing Levy
    // allowable deductions from 27 December 2024 (first full month: January 2025).
    // -----------------------------------------------------------------
    'tax_deductible' => [
        ['effective_from' => '2023-07-01', 'nssf' => true, 'shif' => false, 'housing_levy' => false],
        ['effective_from' => '2025-01-01', 'nssf' => true, 'shif' => true,  'housing_levy' => true],
    ],

    // -----------------------------------------------------------------
    // Working-time assumptions used for overtime and unpaid absence.
    // Adjust to match the organisation's employment contracts.
    // -----------------------------------------------------------------
    'working_time' => [
        'hours_per_month' => 225,   // 52-hour week x 52 weeks / 12, rounded
        'days_per_month'  => 22,
        'overtime_rate'   => 1.5,   // weekday overtime multiplier
        'start_time'      => '08:00', // clock-ins after start_time + grace are marked late
        'grace_minutes'   => 15,
    ],

    'payment_methods' => [
        'mpesa'         => 'M-Pesa',
        'bank_transfer' => 'Bank transfer',
        'cash'          => 'Cash',
        'cheque'        => 'Cheque',
    ],

    // Default leave entitlements created for every new organisation
    // (Employment Act 2007: 21 working days annual leave, 3 months maternity, 2 weeks paternity).
    'default_leave_types' => [
        ['code' => 'ANNUAL',    'name' => 'Annual leave',        'days' => 21, 'paid' => 1],
        ['code' => 'SICK',      'name' => 'Sick leave',          'days' => 14, 'paid' => 1],
        ['code' => 'MATERNITY', 'name' => 'Maternity leave',     'days' => 90, 'paid' => 1],
        ['code' => 'PATERNITY', 'name' => 'Paternity leave',     'days' => 14, 'paid' => 1],
        ['code' => 'COMPASS',   'name' => 'Compassionate leave', 'days' => 5,  'paid' => 1],
    ],
];
