<?php
/**
 * Authentication (one login form for HR users and employees).
 *   POST ?action=login            {username, password}
 *   POST ?action=logout
 *   GET  ?action=me
 *   POST ?action=change_password  {current_password, new_password}
 */
require_once __DIR__ . '/../lib/bootstrap.php';

run(function () {
    $action = action() ?: 'login';

    if ($action === 'login' && method() === 'POST') {
        rate_limit('login', 20, 300);
        $in = input();
        require_fields($in, ['username', 'password']);
        ok(Auth::login((string)$in['username'], (string)$in['password']), 'Login successful');
    }

    if ($action === 'logout' && method() === 'POST') {
        Auth::logout();
        ok(null, 'Logged out');
    }

    if ($action === 'me' && method() === 'GET') {
        ok(Auth::user());
    }

    if ($action === 'change_password' && method() === 'POST') {
        $user = Auth::user();
        $in = input();
        require_fields($in, ['current_password', 'new_password']);
        $table = $user['user_type'] === 'employer' ? 'employer_users' : 'employee_users';
        $hash = q("SELECT password_hash FROM $table WHERE id = ?", [$user['id']])->fetchColumn();
        if (!password_verify((string)$in['current_password'], (string)$hash)) {
            fail_field('current_password', 'Your current password is incorrect.');
        }
        Auth::validatePassword((string)$in['new_password']);
        if ($in['new_password'] === $in['current_password']) {
            fail_field('new_password', 'Choose a password different from your current one.');
        }
        $extra = $user['user_type'] === 'employee' ? ', force_password_change = 0' : '';
        q("UPDATE $table SET password_hash = ? $extra WHERE id = ?",
            [password_hash((string)$in['new_password'], PASSWORD_DEFAULT), $user['id']]);
        Audit::log($user, 'auth.password_changed', $table, $user['id']);
        ok(Auth::profile($user['user_type'], $user['id']), 'Password changed');
    }

    throw new ApiError('Unknown action.', 404);
});
