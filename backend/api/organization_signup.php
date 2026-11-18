<?php
/**
 * POST /api/organization_signup.php
 * Registers a new SME: organisation, trial subscription, first super-admin user,
 * default departments, positions and leave types, all in one transaction.
 */
require_once __DIR__ . '/../lib/bootstrap.php';

run(function () {
    if (method() !== 'POST') {
        throw new ApiError('Method not allowed.', 405);
    }
    rate_limit('signup', 10, 3600);
    $in = input();
    require_fields($in, ['organization_name', 'organization_email', 'admin_first_name', 'admin_last_name',
        'admin_email', 'username', 'password']);

    $orgName = clean($in['organization_name'], 150);
    $kraPin = strtoupper((string)clean($in['kra_pin'] ?? null, 11));
    if (!valid_kra_pin($kraPin)) {
        fail_field('kra_pin', 'KRA PIN must be a letter, 9 digits and a letter, e.g. P051234567X.');
    }
    foreach (['organization_email', 'admin_email'] as $f) {
        if (!filter_var($in[$f], FILTER_VALIDATE_EMAIL)) {
            fail_field($f, 'Enter a valid email address.');
        }
    }
    $username = strtolower(trim((string)$in['username']));
    if (!preg_match('/^[a-z0-9._-]{4,60}$/', $username)) {
        fail_field('username', 'Username must be 4-60 characters: letters, numbers, dot, dash or underscore.');
    }
    if (Auth::usernameTaken($username)) {
        fail_field('username', 'That username is already taken.');
    }
    Auth::validatePassword((string)$in['password']);

    // Organisation code: up to 6 letters from the name + 3 digits, unique.
    $base = strtoupper(substr((string)preg_replace('/[^A-Za-z]/', '', $orgName), 0, 6)) ?: 'ORG';
    do {
        $code = $base . random_int(100, 999);
    } while (q('SELECT 1 FROM organizations WHERE organization_code = ?', [$code])->fetch());

    $config = require __DIR__ . '/../config/payroll_config.php';
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO organizations (organization_name, organization_code, kra_pin, email, phone, address, county)
           VALUES (?, ?, ?, ?, ?, ?, ?)', [$orgName, $code, $kraPin ?: null, $in['organization_email'],
            clean($in['phone'] ?? null, 20), clean($in['address'] ?? null), clean($in['county'] ?? null, 60)]);
        $orgId = (int)$pdo->lastInsertId();

        q("INSERT INTO subscriptions (organization_id, plan, max_employees, start_date, end_date)
           VALUES (?, 'trial', 25, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY))", [$orgId]);

        q("INSERT INTO employer_users (organization_id, username, email, password_hash, first_name, last_name, phone_number, role)
           VALUES (?, ?, ?, ?, ?, ?, ?, 'super_admin')", [$orgId, $username, $in['admin_email'],
            password_hash((string)$in['password'], PASSWORD_DEFAULT), clean($in['admin_first_name'], 60),
            clean($in['admin_last_name'], 60), clean($in['admin_phone'] ?? null, 20)]);
        $adminId = (int)$pdo->lastInsertId();

        foreach ([['ADM', 'Administration'], ['FIN', 'Finance'], ['OPS', 'Operations'], ['SAL', 'Sales']] as [$c, $n]) {
            q('INSERT INTO departments (organization_id, code, name) VALUES (?, ?, ?)', [$orgId, $c, $n]);
        }
        foreach ([['MGR', 'Manager'], ['OFF', 'Officer'], ['ASST', 'Assistant']] as [$c, $t]) {
            q('INSERT INTO positions (organization_id, code, title) VALUES (?, ?, ?)', [$orgId, $c, $t]);
        }
        foreach ($config['default_leave_types'] as $lt) {
            q('INSERT INTO leave_types (organization_id, code, name, days_per_year, is_paid) VALUES (?, ?, ?, ?, ?)',
                [$orgId, $lt['code'], $lt['name'], $lt['days'], $lt['paid']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    Audit::log(['organization_id' => $orgId, 'user_type' => 'employer', 'id' => $adminId],
        'organization.created', 'organization', $orgId);
    Mailer::send($in['admin_email'], 'Welcome to PaySphere', Mailer::layout('Welcome to PaySphere',
        '<p>Your organisation <b>' . htmlspecialchars($orgName) . '</b> is ready (code ' . $code . ').</p>'
        . '<p>Log in with your username <b>' . htmlspecialchars($username) . '</b> to add employees and run payroll.</p>'));

    ok(['organization_id' => $orgId, 'organization_code' => $code, 'username' => $username],
        'Organisation registered. You can now log in.', 201);
});
