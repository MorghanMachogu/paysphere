<?php
/**
 * Employees (Module 2) and employee self-service profile (Module 6).
 *   GET    /employees.php                       list (filters: search, status, department_id)
 *   GET    /employees.php?id=5                  one employee with salary history and payslips
 *   POST   /employees.php                       onboard: employee + salary structure + login account
 *   PUT    /employees.php?id=5                  update (salary change creates a new salary structure)
 *   DELETE /employees.php?id=5                  deactivate (records are kept for payroll history)
 *   POST   /employees.php?action=reset_password&id=5
 *   GET    /employees.php?action=me             employee: own profile
 *   PUT    /employees.php?action=me             employee: update own contact details
 */
require_once __DIR__ . '/../lib/bootstrap.php';

const EMPLOYEE_FIELDS = ['first_name', 'last_name', 'national_id', 'kra_pin', 'nssf_number', 'shif_number',
    'date_of_birth', 'gender', 'phone_number', 'work_email', 'personal_email', 'department_id', 'position_id',
    'employment_type', 'hire_date', 'payment_method', 'mpesa_number', 'bank_name', 'bank_account'];
const SALARY_FIELDS = ['basic_salary', 'housing_allowance', 'transport_allowance', 'medical_allowance',
    'other_allowances', 'other_deductions'];

run(function () {
    $action = action();
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

    // ----- Employee self-service -----
    if ($action === 'me') {
        $user = Auth::requireEmployee();
        if (method() === 'PUT') {
            $in = input();
            $phone = array_key_exists('phone_number', $in) ? normalise_phone($in['phone_number']) : false;
            if ($phone === null && !empty($in['phone_number'])) {
                fail_field('phone_number', 'Enter a valid Kenyan mobile number, e.g. 0712345678.');
            }
            $email = clean($in['personal_email'] ?? null, 150);
            if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                fail_field('personal_email', 'Enter a valid email address.');
            }
            q('UPDATE employees SET phone_number = COALESCE(?, phone_number), personal_email = ? WHERE id = ?',
                [$phone ?: null, $email, $user['employee_id']]);
            Audit::log($user, 'employee.self_updated', 'employee', $user['employee_id']);
        }
        ok(employee_detail($user['employee_id'], $user, false));
    }

    // ----- HR -----
    if ($action === 'reset_password' && method() === 'POST' && $id) {
        $user = Auth::requirePermission('employees.manage');
        $emp = Auth::employeeInOrg($id, $user);
        $temp = temp_password();
        q('UPDATE employee_users SET password_hash = ?, force_password_change = 1, failed_login_attempts = 0,
              locked_until = NULL WHERE employee_id = ?', [password_hash($temp, PASSWORD_DEFAULT), $id]);
        Audit::log($user, 'employee.password_reset', 'employee', $id);
        $username = q('SELECT username FROM employee_users WHERE employee_id = ?', [$id])->fetchColumn();
        send_login_email($emp, (string)$username, $temp, $user['organization_name'], true);
        ok(['username' => $username, 'temporary_password' => $temp], 'Password reset. Share the temporary password with the employee.');
    }

    if (method() === 'GET' && $id) {
        $user = Auth::requirePermission('employees.view');
        Auth::employeeInOrg($id, $user);
        ok(employee_detail($id, $user, Auth::can($user, 'payroll.view')));
    }

    if (method() === 'GET') {
        $user = Auth::requirePermission('employees.view');
        $where = ['e.organization_id = ?'];
        $params = [$user['organization_id']];
        if (!empty($_GET['status'])) {
            $where[] = 'e.status = ?';
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['department_id'])) {
            $where[] = 'e.department_id = ?';
            $params[] = (int)$_GET['department_id'];
        }
        if (!empty($_GET['search'])) {
            $where[] = "(CONCAT(e.first_name, ' ', e.last_name) LIKE ? OR e.employee_number LIKE ? OR e.national_id LIKE ?)";
            $s = '%' . $_GET['search'] . '%';
            array_push($params, $s, $s, $s);
        }
        $showPay = Auth::can($user, 'payroll.view');
        $rows = q("SELECT e.id, e.employee_number, e.first_name, e.last_name, e.national_id, e.phone_number,
                          COALESCE(e.work_email, e.personal_email) AS email, e.employment_type, e.hire_date, e.status,
                          e.department_id, d.name AS department, e.position_id, p.title AS position, e.payment_method,
                          s.basic_salary, (us.id IS NOT NULL) AS has_login
                   FROM employees e
                   LEFT JOIN departments d ON d.id = e.department_id
                   LEFT JOIN positions p ON p.id = e.position_id
                   LEFT JOIN employee_users us ON us.employee_id = e.id
                   LEFT JOIN salary_structures s ON s.id = (
                        SELECT s2.id FROM salary_structures s2 WHERE s2.employee_id = e.id AND s2.is_active = 1
                        AND s2.effective_date <= CURDATE() ORDER BY s2.effective_date DESC, s2.id DESC LIMIT 1)
                   WHERE " . implode(' AND ', $where) . ' ORDER BY e.employee_number', $params)->fetchAll();
        if (!$showPay) {
            foreach ($rows as &$r) {
                unset($r['basic_salary']);
            }
        }
        ok($rows);
    }

    if (method() === 'POST') {
        $user = Auth::requirePermission('employees.manage');
        $in = input();
        require_fields($in, ['first_name', 'last_name', 'national_id', 'hire_date', 'basic_salary']);
        $data = validate_employee($in, $user, null);
        $salary = validate_salary($in);

        $limit = q("SELECT max_employees FROM subscriptions WHERE organization_id = ? AND status = 'active'
                    ORDER BY id DESC LIMIT 1", [$user['organization_id']])->fetchColumn();
        $count = q("SELECT COUNT(*) FROM employees WHERE organization_id = ? AND status = 'active'",
            [$user['organization_id']])->fetchColumn();
        if ($limit && $count >= $limit) {
            throw new ApiError("Your plan allows $limit active employees. Upgrade the subscription to add more.", 402);
        }

        $number = clean($in['employee_number'] ?? null, 20) ?: next_employee_number($user['organization_id']);
        $username = strtolower((string)clean($in['username'] ?? null, 60)) ?: suggest_username($data['first_name'], $data['last_name']);
        if (!preg_match('/^[a-z0-9._-]{4,60}$/', $username)) {
            fail_field('username', 'Username must be 4-60 characters: letters, numbers, dot, dash or underscore.');
        }
        if (Auth::usernameTaken($username)) {
            fail_field('username', 'That username is already taken.');
        }
        $temp = temp_password();

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $cols = array_merge(['organization_id', 'employee_number'], array_keys($data));
            q('INSERT INTO employees (' . implode(', ', $cols) . ') VALUES (' . rtrim(str_repeat('?, ', count($cols)), ', ') . ')',
                array_merge([$user['organization_id'], $number], array_values($data)));
            $empId = (int)$pdo->lastInsertId();
            insert_salary($empId, $salary, $data['hire_date']);
            q('INSERT INTO employee_users (employee_id, username, password_hash, force_password_change) VALUES (?, ?, ?, 1)',
                [$empId, $username, password_hash($temp, PASSWORD_DEFAULT)]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        Audit::log($user, 'employee.created', 'employee', $empId, ['employee_number' => $number, 'basic_salary' => $salary['basic_salary']]);
        $emp = q('SELECT * FROM employees WHERE id = ?', [$empId])->fetch();
        $emailed = send_login_email($emp, $username, $temp, $user['organization_name'], false);
        ok(['employee' => employee_detail($empId, $user, true), 'username' => $username,
            'temporary_password' => $temp, 'login_emailed' => $emailed], 'Employee added', 201);
    }

    if (method() === 'PUT' && $id) {
        $user = Auth::requirePermission('employees.manage');
        $current = Auth::employeeInOrg($id, $user);
        $in = input();
        $data = validate_employee(array_merge($current, $in), $user, $id);
        if (isset($in['status'])) {
            if (!in_array($in['status'], ['active', 'inactive', 'terminated'], true)) {
                fail_field('status', 'Invalid status.');
            }
            $data['status'] = $in['status'];
        }
        $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($data)));
        q("UPDATE employees SET $set WHERE id = ?", array_merge(array_values($data), [$id]));
        if (isset($data['status'])) {
            q('UPDATE employee_users SET is_active = ? WHERE employee_id = ?', [$data['status'] === 'active' ? 1 : 0, $id]);
        }

        // A salary change becomes a new salary structure, keeping history for past payrolls.
        if (array_intersect(SALARY_FIELDS, array_keys($in))) {
            $old = current_salary($id) ?: [];
            $salary = validate_salary(array_merge($old, $in));
            $changed = false;
            foreach (SALARY_FIELDS as $f) {
                if (abs((float)($old[$f] ?? 0) - $salary[$f]) > 0.001) {
                    $changed = true;
                }
            }
            if ($changed) {
                $effective = $in['salary_effective_date'] ?? date('Y-m-d');
                if (!valid_date($effective)) {
                    fail_field('salary_effective_date', 'Enter a valid date.');
                }
                insert_salary($id, $salary, $effective);
                Audit::log($user, 'employee.salary_changed', 'employee', $id,
                    ['from' => $old['basic_salary'] ?? null, 'to' => $salary['basic_salary'], 'effective' => $effective]);
            }
        }
        Audit::log($user, 'employee.updated', 'employee', $id, ['fields' => array_keys($in)]);
        ok(employee_detail($id, $user, true), 'Employee updated');
    }

    if (method() === 'DELETE' && $id) {
        $user = Auth::requirePermission('employees.manage');
        Auth::employeeInOrg($id, $user);
        q("UPDATE employees SET status = 'inactive' WHERE id = ?", [$id]);
        q('UPDATE employee_users SET is_active = 0 WHERE employee_id = ?', [$id]);
        q("UPDATE user_sessions s JOIN employee_users u ON u.id = s.user_id AND s.user_type = 'employee'
           SET s.is_active = 0 WHERE u.employee_id = ?", [$id]);
        Audit::log($user, 'employee.deactivated', 'employee', $id);
        ok(null, 'Employee deactivated');
    }

    throw new ApiError('Unknown request.', 404);
});

// ---------------------------------------------------------------------

function employee_detail(int $id, array $user, bool $includePay): array
{
    $e = q('SELECT e.*, d.name AS department, p.title AS position, us.username, us.last_login,
                   us.is_active AS login_active
            FROM employees e
            LEFT JOIN departments d ON d.id = e.department_id
            LEFT JOIN positions p ON p.id = e.position_id
            LEFT JOIN employee_users us ON us.employee_id = e.id
            WHERE e.id = ?', [$id])->fetch();
    $e['salary'] = current_salary($id);
    if ($includePay) {
        $e['salary_history'] = q('SELECT * FROM salary_structures WHERE employee_id = ? ORDER BY effective_date DESC, id DESC',
            [$id])->fetchAll();
        $e['payslips'] = q('SELECT p.id, r.period_month, r.period_year, r.status AS run_status, p.gross_pay,
                                   p.total_deductions, p.net_pay, p.payment_status
                            FROM payslips p JOIN payroll_runs r ON r.id = p.payroll_run_id
                            WHERE p.employee_id = ? ORDER BY r.period_year DESC, r.period_month DESC LIMIT 24', [$id])->fetchAll();
    }
    return $e;
}

function current_salary(int $employeeId): ?array
{
    $s = q('SELECT * FROM salary_structures WHERE employee_id = ? AND is_active = 1 AND effective_date <= CURDATE()
            ORDER BY effective_date DESC, id DESC LIMIT 1', [$employeeId])->fetch();
    if (!$s) { // salary that starts in the future (new hire)
        $s = q('SELECT * FROM salary_structures WHERE employee_id = ? AND is_active = 1
                ORDER BY effective_date ASC LIMIT 1', [$employeeId])->fetch();
    }
    return $s ?: null;
}

function validate_employee(array $in, array $user, ?int $id): array
{
    $d = [];
    foreach (EMPLOYEE_FIELDS as $f) {
        if (array_key_exists($f, $in)) {
            $d[$f] = is_string($in[$f]) ? clean($in[$f]) : $in[$f];
        }
    }
    foreach (['first_name', 'last_name'] as $f) {
        if (isset($d[$f]) && !preg_match("/^[\\p{L} .'-]{2,60}$/u", (string)$d[$f])) {
            fail_field($f, 'Enter a valid name.');
        }
    }
    if (isset($d['national_id']) && !preg_match('/^\d{6,10}$/', (string)$d['national_id'])) {
        fail_field('national_id', 'National ID must be 6 to 10 digits.');
    }
    if (isset($d['national_id'])) {
        $dup = q('SELECT id FROM employees WHERE organization_id = ? AND national_id = ? AND id <> ?',
            [$user['organization_id'], $d['national_id'], $id ?? 0])->fetch();
        if ($dup) {
            fail_field('national_id', 'Another employee already has this national ID.');
        }
    }
    if (isset($d['kra_pin'])) {
        $d['kra_pin'] = $d['kra_pin'] ? strtoupper((string)$d['kra_pin']) : null;
        if (!valid_kra_pin($d['kra_pin'])) {
            fail_field('kra_pin', 'KRA PIN must be a letter, 9 digits and a letter, e.g. A012345678Z.');
        }
    }
    foreach (['phone_number', 'mpesa_number'] as $f) {
        if (!empty($d[$f])) {
            $n = normalise_phone((string)$d[$f]);
            if (!$n) {
                fail_field($f, 'Enter a valid Kenyan mobile number, e.g. 0712345678.');
            }
            $d[$f] = $n;
        }
    }
    foreach (['work_email', 'personal_email'] as $f) {
        if (!empty($d[$f]) && !filter_var($d[$f], FILTER_VALIDATE_EMAIL)) {
            fail_field($f, 'Enter a valid email address.');
        }
    }
    foreach (['hire_date', 'date_of_birth'] as $f) {
        if (!empty($d[$f]) && !valid_date((string)$d[$f])) {
            fail_field($f, 'Enter a valid date (YYYY-MM-DD).');
        }
        if (array_key_exists($f, $d) && empty($d[$f])) {
            $d[$f] = null;
        }
    }
    if (!empty($d['date_of_birth']) && $d['date_of_birth'] > date('Y-m-d', strtotime('-16 years'))) {
        fail_field('date_of_birth', 'Employees must be at least 16 years old.');
    }
    $enums = ['gender' => ['male', 'female', 'other'], 'employment_type' => ['permanent', 'contract', 'casual', 'intern'],
        'payment_method' => ['mpesa', 'bank_transfer', 'cash', 'cheque']];
    foreach ($enums as $f => $allowed) {
        if (!empty($d[$f]) && !in_array($d[$f], $allowed, true)) {
            fail_field($f, 'Invalid value.');
        }
        if (array_key_exists($f, $d) && empty($d[$f])) {
            unset($d[$f]);
        }
    }
    $method = $d['payment_method'] ?? $in['payment_method'] ?? 'mpesa';
    if ($method === 'mpesa' && empty($d['mpesa_number']) && empty($in['mpesa_number'])) {
        fail_field('mpesa_number', 'An M-Pesa number is required for M-Pesa payment.');
    }
    if ($method === 'bank_transfer' && (empty($in['bank_name']) || empty($in['bank_account']))) {
        fail_field('bank_account', 'Bank name and account number are required for bank transfer.');
    }
    foreach (['department_id' => 'departments', 'position_id' => 'positions'] as $f => $table) {
        if (array_key_exists($f, $d)) {
            $d[$f] = $d[$f] ? (int)$d[$f] : null;
            if ($d[$f] && !q("SELECT 1 FROM $table WHERE id = ? AND organization_id = ?", [$d[$f], $user['organization_id']])->fetch()) {
                fail_field($f, 'Choose a valid option.');
            }
        }
    }
    unset($d['id'], $d['organization_id']);
    return $d;
}

function validate_salary(array $in): array
{
    $s = [];
    foreach (SALARY_FIELDS as $f) {
        $v = $in[$f] ?? 0;
        if ($v === '' || $v === null) {
            $v = 0;
        }
        if (!is_numeric($v) || $v < 0 || $v > 100000000) {
            fail_field($f, 'Enter a valid amount.');
        }
        $s[$f] = round((float)$v, 2);
    }
    if ($s['basic_salary'] <= 0) {
        fail_field('basic_salary', 'Basic salary must be greater than zero.');
    }
    return $s;
}

function insert_salary(int $employeeId, array $s, string $effective): void
{
    q('INSERT INTO salary_structures (employee_id, basic_salary, housing_allowance, transport_allowance, medical_allowance,
         other_allowances, other_deductions, effective_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$employeeId, $s['basic_salary'], $s['housing_allowance'], $s['transport_allowance'], $s['medical_allowance'],
         $s['other_allowances'], $s['other_deductions'], $effective]);
}

function next_employee_number(int $orgId): string
{
    $max = q("SELECT MAX(CAST(SUBSTRING(employee_number, 4) AS UNSIGNED)) FROM employees
              WHERE organization_id = ? AND employee_number REGEXP '^EMP[0-9]+$'", [$orgId])->fetchColumn();
    return sprintf('EMP%03d', (int)$max + 1);
}

function suggest_username(string $first, string $last): string
{
    $base = strtolower(preg_replace('/[^a-z]/i', '', $first) . '.' . preg_replace('/[^a-z]/i', '', $last));
    $base = substr($base, 0, 50) ?: 'employee';
    $candidate = $base;
    $i = 1;
    while (Auth::usernameTaken($candidate)) {
        $candidate = $base . (++$i);
    }
    return $candidate;
}

function temp_password(): string
{
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz';
    $p = '';
    for ($i = 0; $i < 6; $i++) {
        $p .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $p . random_int(10, 99);
}

function send_login_email(array $emp, string $username, string $temp, string $orgName, bool $reset): bool
{
    $to = $emp['work_email'] ?: $emp['personal_email'];
    if (!$to) {
        return false;
    }
    $intro = $reset ? 'Your PaySphere password has been reset.' : "Welcome to $orgName. Your PaySphere employee account is ready.";
    return Mailer::send($to, $reset ? 'Your PaySphere password was reset' : 'Your PaySphere account',
        Mailer::layout($reset ? 'Password reset' : 'Your employee account',
            '<p>Dear ' . htmlspecialchars($emp['first_name']) . ',</p><p>' . htmlspecialchars($intro) . '</p>'
            . '<p>Username: <b>' . htmlspecialchars($username) . '</b><br>Temporary password: <b>' . htmlspecialchars($temp) . '</b></p>'
            . '<p>You will be asked to choose a new password when you first log in. Use the portal to view payslips and apply for leave.</p>'));
}
