<?php
/**
 * Organisation settings and security administration (super_admin).
 *   GET|PUT ?action=organization
 *   GET     ?action=subscription
 *   GET|POST ?action=users           HR users and their roles (RBAC)
 *   PUT     ?action=users&id=3      {role, is_active} or {reset_password: true}
 *   GET|PUT ?action=leave_types[&id=]
 *   GET     ?action=login_logs
 *   GET     ?action=audit
 */
require_once __DIR__ . '/../lib/bootstrap.php';

const ROLES = ['super_admin', 'hr_manager', 'payroll_officer', 'manager'];

run(function () {
    $action = action();
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

    if ($action === 'organization') {
        $user = method() === 'GET' ? Auth::user() : Auth::requirePermission('settings.manage');
        if (method() === 'PUT') {
            $in = input();
            require_fields($in, ['organization_name', 'email']);
            $pin = strtoupper((string)clean($in['kra_pin'] ?? null, 11));
            if (!valid_kra_pin($pin)) {
                fail_field('kra_pin', 'KRA PIN must be a letter, 9 digits and a letter.');
            }
            if (!filter_var($in['email'], FILTER_VALIDATE_EMAIL)) {
                fail_field('email', 'Enter a valid email address.');
            }
            q('UPDATE organizations SET organization_name = ?, kra_pin = ?, email = ?, phone = ?, address = ?, county = ?
               WHERE id = ?', [clean($in['organization_name'], 150), $pin ?: null, $in['email'], clean($in['phone'] ?? null, 20),
                clean($in['address'] ?? null), clean($in['county'] ?? null, 60), $user['organization_id']]);
            Audit::log($user, 'organization.updated', 'organization', $user['organization_id']);
        }
        ok(q('SELECT * FROM organizations WHERE id = ?', [$user['organization_id']])->fetch());
    }

    if ($action === 'subscription') {
        $user = Auth::requirePermission('employees.view');
        ok(q('SELECT s.*, (SELECT COUNT(*) FROM employees e WHERE e.organization_id = s.organization_id AND e.status = \'active\')
                     AS active_employees
              FROM subscriptions s WHERE s.organization_id = ? ORDER BY s.id DESC LIMIT 1', [$user['organization_id']])->fetch());
    }

    if ($action === 'leave_types') {
        $user = method() === 'GET' ? Auth::user() : Auth::requirePermission('settings.manage');
        if (method() === 'PUT' && $id) {
            $in = input();
            $days = (int)($in['days_per_year'] ?? -1);
            if ($days < 0 || $days > 365) {
                fail_field('days_per_year', 'Enter between 0 and 365 days.');
            }
            q('UPDATE leave_types SET days_per_year = ?, is_active = ? WHERE id = ? AND organization_id = ?',
                [$days, isset($in['is_active']) ? (int)(bool)$in['is_active'] : 1, $id, $user['organization_id']]);
            Audit::log($user, 'leave_type.updated', 'leave_type', $id, ['days_per_year' => $days]);
        }
        ok(q('SELECT * FROM leave_types WHERE organization_id = ? ORDER BY id', [$user['organization_id']])->fetchAll());
    }

    $user = Auth::requirePermission('settings.manage');

    if ($action === 'users') {
        if (method() === 'POST') {
            $in = input();
            require_fields($in, ['first_name', 'last_name', 'email', 'username', 'password', 'role']);
            if (!in_array($in['role'], ROLES, true)) {
                fail_field('role', 'Choose a valid role.');
            }
            if (!filter_var($in['email'], FILTER_VALIDATE_EMAIL)) {
                fail_field('email', 'Enter a valid email address.');
            }
            $username = strtolower(trim((string)$in['username']));
            if (!preg_match('/^[a-z0-9._-]{4,60}$/', $username)) {
                fail_field('username', 'Username must be 4-60 characters: letters, numbers, dot, dash or underscore.');
            }
            if (Auth::usernameTaken($username)) {
                fail_field('username', 'That username is already taken.');
            }
            Auth::validatePassword((string)$in['password']);
            q('INSERT INTO employer_users (organization_id, username, email, password_hash, first_name, last_name, phone_number, role)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$user['organization_id'], $username, $in['email'],
                password_hash((string)$in['password'], PASSWORD_DEFAULT), clean($in['first_name'], 60),
                clean($in['last_name'], 60), clean($in['phone_number'] ?? null, 20), $in['role']]);
            $newId = (int)db()->lastInsertId();
            Audit::log($user, 'user.created', 'employer_user', $newId, ['role' => $in['role']]);
        }
        if (method() === 'PUT' && $id) {
            $in = input();
            $target = q('SELECT * FROM employer_users WHERE id = ? AND organization_id = ?', [$id, $user['organization_id']])->fetch();
            if (!$target) {
                throw new ApiError('User not found.', 404);
            }
            if ($id === $user['id'] && (isset($in['is_active']) && !$in['is_active'] || (isset($in['role']) && $in['role'] !== 'super_admin'))) {
                throw new ApiError('You cannot deactivate yourself or remove your own administrator role.', 409);
            }
            if (isset($in['role']) && !in_array($in['role'], ROLES, true)) {
                fail_field('role', 'Choose a valid role.');
            }
            q('UPDATE employer_users SET role = COALESCE(?, role), is_active = COALESCE(?, is_active) WHERE id = ?',
                [$in['role'] ?? null, isset($in['is_active']) ? (int)(bool)$in['is_active'] : null, $id]);
            if (isset($in['is_active']) && !$in['is_active']) {
                q("UPDATE user_sessions SET is_active = 0 WHERE user_type = 'employer' AND user_id = ?", [$id]);
            }
            if (!empty($in['password'])) {
                Auth::validatePassword((string)$in['password']);
                q('UPDATE employer_users SET password_hash = ?, failed_login_attempts = 0, locked_until = NULL WHERE id = ?',
                    [password_hash((string)$in['password'], PASSWORD_DEFAULT), $id]);
            }
            Audit::log($user, 'user.updated', 'employer_user', $id, array_intersect_key($in, ['role' => 1, 'is_active' => 1]));
        }
        ok(q('SELECT id, username, email, first_name, last_name, phone_number, role, is_active, last_login, locked_until, created_at
              FROM employer_users WHERE organization_id = ? ORDER BY id', [$user['organization_id']])->fetchAll());
    }

    if ($action === 'login_logs') {
        ok(q('SELECT * FROM login_logs WHERE organization_id = ? ORDER BY id DESC LIMIT 200', [$user['organization_id']])->fetchAll());
    }

    if ($action === 'audit') {
        ok(q("SELECT a.*, COALESCE(CONCAT(eu.first_name, ' ', eu.last_name), CONCAT(e.first_name, ' ', e.last_name)) AS actor
              FROM audit_log a
              LEFT JOIN employer_users eu ON a.user_type = 'employer' AND eu.id = a.user_id
              LEFT JOIN employee_users us ON a.user_type = 'employee' AND us.id = a.user_id
              LEFT JOIN employees e ON e.id = us.employee_id
              WHERE a.organization_id = ? ORDER BY a.id DESC LIMIT 300", [$user['organization_id']])->fetchAll());
    }

    throw new ApiError('Unknown action.', 404);
});
