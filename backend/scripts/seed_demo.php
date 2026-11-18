<?php
/**
 * Creates a demo organisation with users, employees, attendance and leave.
 *
 *   php backend/scripts/seed_demo.php                 12 employees
 *   php backend/scripts/seed_demo.php --employees=100 adds synthetic staff up to 100 (performance test, TC-18)
 *
 * Re-running it deletes and recreates the demo organisation (code DEMO001) only.
 *
 * Demo logins (password for all: PaySphere2026)
 *   admin     super_admin       hr        hr_manager
 *   payroll   payroll_officer   manager   manager
 *   jane.wanjiku   employee (no forced password change)
 */
if (PHP_SAPI !== 'cli') {
    exit('Run from the command line.');
}
require_once __DIR__ . '/../lib/bootstrap.php';

$target = 12;
foreach ($argv as $arg) {
    if (preg_match('/^--employees=(\d+)$/', $arg, $m)) {
        $target = max(12, (int)$m[1]);
    }
}
$password = password_hash('PaySphere2026', PASSWORD_DEFAULT);
$pdo = db();

$old = q("SELECT id FROM organizations WHERE organization_code = 'DEMO001'")->fetchColumn();
if ($old) {
    q('DELETE FROM user_sessions WHERE (user_type = \'employer\' AND user_id IN (SELECT id FROM employer_users WHERE organization_id = ?))
       OR (user_type = \'employee\' AND user_id IN (SELECT us.id FROM employee_users us JOIN employees e ON e.id = us.employee_id WHERE e.organization_id = ?))',
        [$old, $old]);
    q('DELETE FROM payslips WHERE payroll_run_id IN (SELECT id FROM payroll_runs WHERE organization_id = ?)', [$old]);
    q('DELETE FROM leave_requests WHERE employee_id IN (SELECT id FROM employees WHERE organization_id = ?)', [$old]);
    q('DELETE FROM leave_types WHERE organization_id = ?', [$old]);
    q('DELETE FROM audit_log WHERE organization_id = ?', [$old]);
    q('DELETE FROM login_logs WHERE organization_id = ?', [$old]);
    q('DELETE FROM organizations WHERE id = ?', [$old]);
}

$pdo->beginTransaction();
q("INSERT INTO organizations (organization_name, organization_code, kra_pin, email, phone, address, county)
   VALUES ('Jitegemee Hardware Ltd', 'DEMO001', 'P051234567X', 'info@jitegemee.co.ke', '0722000111',
           'Kenyatta Avenue, Nakuru', 'Nakuru')");
$org = (int)$pdo->lastInsertId();
q("INSERT INTO subscriptions (organization_id, plan, max_employees, start_date) VALUES (?, 'growth', 250, CURDATE())", [$org]);

$users = [['admin', 'Morghan', 'Atandi', 'super_admin'], ['hr', 'Grace', 'Achieng', 'hr_manager'],
          ['payroll', 'Peter', 'Kamau', 'payroll_officer'], ['manager', 'Hassan', 'Abdi', 'manager']];
foreach ($users as [$u, $f, $l, $role]) {
    q('INSERT INTO employer_users (organization_id, username, email, password_hash, first_name, last_name, role)
       VALUES (?, ?, ?, ?, ?, ?, ?)', [$org, $u, "$u@jitegemee.co.ke", $password, $f, $l, $role]);
}

$dept = [];
foreach ([['ADM', 'Administration'], ['FIN', 'Finance'], ['OPS', 'Operations'], ['SAL', 'Sales'], ['STR', 'Stores']] as [$c, $n]) {
    q('INSERT INTO departments (organization_id, code, name) VALUES (?, ?, ?)', [$org, $c, $n]);
    $dept[$c] = (int)$pdo->lastInsertId();
}
$pos = [];
foreach ([['MGR', 'Manager'], ['ACC', 'Accountant'], ['SUP', 'Supervisor'], ['SLS', 'Sales Associate'],
          ['STK', 'Storekeeper'], ['DRV', 'Driver'], ['CLK', 'Clerk']] as [$c, $t]) {
    q('INSERT INTO positions (organization_id, code, title) VALUES (?, ?, ?)', [$org, $c, $t]);
    $pos[$c] = (int)$pdo->lastInsertId();
}
$config = require __DIR__ . '/../config/payroll_config.php';
$leaveType = [];
foreach ($config['default_leave_types'] as $lt) {
    q('INSERT INTO leave_types (organization_id, code, name, days_per_year, is_paid) VALUES (?, ?, ?, ?, ?)',
        [$org, $lt['code'], $lt['name'], $lt['days'], $lt['paid']]);
    $leaveType[$lt['code']] = (int)$pdo->lastInsertId();
}

// name, gender, dept, position, basic, housing, transport, medical
$people = [
    ['Jane', 'Wanjiku', 'female', 'FIN', 'ACC', 62000, 8000, 4000, 2000],
    ['Brian', 'Otieno', 'male', 'OPS', 'MGR', 140000, 20000, 10000, 5000],
    ['Mercy', 'Chebet', 'female', 'SAL', 'SUP', 45000, 6000, 3000, 0],
    ['Kevin', 'Mutua', 'male', 'SAL', 'SLS', 28000, 3000, 2000, 0],
    ['Faith', 'Njeri', 'female', 'SAL', 'SLS', 26000, 3000, 2000, 0],
    ['Daniel', 'Kiprop', 'male', 'STR', 'STK', 24000, 2500, 1500, 0],
    ['Aisha', 'Mohamed', 'female', 'ADM', 'CLK', 22000, 2000, 1500, 0],
    ['Samuel', 'Omondi', 'male', 'OPS', 'DRV', 21000, 2000, 1000, 0],
    ['Lucy', 'Wambui', 'female', 'FIN', 'CLK', 32000, 4000, 2000, 0],
    ['Joseph', 'Kariuki', 'male', 'ADM', 'MGR', 260000, 40000, 15000, 10000],
    ['Esther', 'Akinyi', 'female', 'STR', 'CLK', 16000, 0, 1000, 0],
    ['Collins', 'Barasa', 'male', 'OPS', 'SUP', 52000, 7000, 3000, 0],
];
$first = ['John', 'Mary', 'Paul', 'Ann', 'James', 'Ruth', 'David', 'Joy', 'Peter', 'Rose', 'Mark', 'Eunice'];
$last = ['Mwangi', 'Ochieng', 'Kibet', 'Wekesa', 'Nyambura', 'Kilonzo', 'Langat', 'Odhiambo', 'Muthoni', 'Juma'];
$depts = array_keys($dept);
for ($i = count($people); $i < $target; $i++) {
    $people[] = [$first[$i % 12], $last[$i % 10], $i % 2 ? 'female' : 'male', $depts[$i % 5], 'CLK',
        18000 + ($i * 1373) % 90000, 2000, 1500, 0];
}

$hire = date('Y-m-d', strtotime('-2 years'));
foreach ($people as $i => [$fn, $ln, $g, $d, $p, $basic, $house, $trans, $med]) {
    $n = $i + 1;
    q("INSERT INTO employees (organization_id, employee_number, first_name, last_name, national_id, kra_pin, nssf_number,
         shif_number, gender, phone_number, work_email, department_id, position_id, employment_type, hire_date,
         payment_method, mpesa_number, bank_name, bank_account)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'permanent', ?, ?, ?, ?, ?)", [
        $org, sprintf('EMP%03d', $n), $fn, $ln, (string)(30000000 + $n * 7919), sprintf('A%09dZ', 100000000 + $n * 37),
        sprintf('NSSF%06d', 200000 + $n), sprintf('SHA%07d', 5000000 + $n), $g, sprintf('2547%08d', 11000000 + $n),
        strtolower("$fn.$ln") . ($n > 12 ? $n : '') . '@jitegemee.co.ke', $dept[$d], $pos[$p], $hire,
        $basic >= 100000 ? 'bank_transfer' : 'mpesa', sprintf('2547%08d', 11000000 + $n),
        $basic >= 100000 ? 'KCB Bank' : null, $basic >= 100000 ? sprintf('11%08d', $n) : null,
    ]);
    $emp = (int)$pdo->lastInsertId();
    q('INSERT INTO salary_structures (employee_id, basic_salary, housing_allowance, transport_allowance, medical_allowance,
         effective_date) VALUES (?, ?, ?, ?, ?, ?)', [$emp, $basic, $house, $trans, $med, $hire]);
    $username = $n === 1 ? 'jane.wanjiku' : strtolower("$fn.$ln") . ($n > 12 ? $n : '');
    q('INSERT INTO employee_users (employee_id, username, password_hash, force_password_change) VALUES (?, ?, ?, ?)',
        [$emp, $username, $password, $n === 1 ? 0 : 1]);
    $ids[$n] = $emp;
}

// Attendance for last month: everyone present on weekdays, with a few absences and overtime.
$from = new DateTime('first day of last month');
$to = new DateTime('last day of last month');
$stmt = $pdo->prepare("INSERT INTO attendance (employee_id, attendance_date, clock_in, clock_out, status, overtime_hours)
                       VALUES (?, ?, ?, ?, ?, ?)");
foreach ($ids as $n => $emp) {
    $day = clone $from;
    $k = 0;
    while ($day <= $to) {
        if ((int)$day->format('N') < 6) {
            $k++;
            $absent = ($n === 4 && in_array($k, [3, 11], true)) || ($n === 8 && $k === 7);
            $late = $n === 5 && $k % 6 === 0;
            $ot = ($n === 6 && $k <= 5) ? 2 : (($n === 8 && $k % 5 === 0) ? 1.5 : 0);
            $stmt->execute([$emp, $day->format('Y-m-d'), $absent ? null : ($late ? '08:41:00' : '07:52:00'),
                $absent ? null : '17:05:00', $absent ? 'absent' : ($late ? 'late' : 'present'), $ot]);
        }
        $day->modify('+1 day');
    }
}

// Leave: one approved (last month), one pending (next week).
q("INSERT INTO leave_requests (employee_id, leave_type_id, start_date, end_date, days, reason, status, reviewed_by, reviewed_at)
   VALUES (?, ?, ?, ?, 3, 'Family function', 'approved', (SELECT id FROM employer_users WHERE username = 'hr'), NOW())",
    [$ids[3], $leaveType['ANNUAL'], date('Y-m-d', strtotime('monday this week -3 weeks')),
     date('Y-m-d', strtotime('monday this week -3 weeks +2 days'))]);
q("INSERT INTO leave_requests (employee_id, leave_type_id, start_date, end_date, days, reason)
   VALUES (?, ?, ?, ?, 2, 'Medical appointment')",
    [$ids[1], $leaveType['SICK'], date('Y-m-d', strtotime('monday next week')), date('Y-m-d', strtotime('monday next week +1 day'))]);
$pdo->commit();

echo 'Demo organisation created: Jitegemee Hardware Ltd (DEMO001) with ' . count($people) . " employees.\n";
echo "Logins (password PaySphere2026): admin, hr, payroll, manager, jane.wanjiku\n";
