<?php
/**
 * Authentication, sessions and role-based access control.
 *
 * Login issues a random 64-character token stored in user_sessions; every
 * protected request sends it as "Authorization: Bearer <token>". Each request is
 * checked against the database, so logging out or deactivating a user takes
 * effect immediately.
 */
class Auth
{
    public const MAX_ATTEMPTS = 5;
    public const LOCK_MINUTES = 30;

    /** Which employer roles may perform each action. Employees use requireEmployee(). */
    public const PERMISSIONS = [
        'employees.view'    => ['super_admin', 'hr_manager', 'payroll_officer', 'manager'],
        'employees.manage'  => ['super_admin', 'hr_manager'],
        'payroll.view'      => ['super_admin', 'hr_manager', 'payroll_officer'],
        'payroll.manage'    => ['super_admin', 'hr_manager', 'payroll_officer'],
        'payroll.approve'   => ['super_admin', 'hr_manager'],
        'leave.review'      => ['super_admin', 'hr_manager', 'manager'],
        'attendance.manage' => ['super_admin', 'hr_manager', 'manager'],
        'reports.view'      => ['super_admin', 'hr_manager', 'payroll_officer'],
        'settings.manage'   => ['super_admin'],
    ];

    private static ?array $current = null;

    // -----------------------------------------------------------------
    // Login / logout
    // -----------------------------------------------------------------
    public static function login(string $username, string $password): array
    {
        $username = trim($username);
        $user = self::findForLogin($username);

        if (!$user) {
            self::logAttempt('unknown', null, null, $username, false, 'Unknown username');
            throw new ApiError('Invalid username or password.', 401);
        }
        $table = $user['user_type'] === 'employer' ? 'employer_users' : 'employee_users';

        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            self::logAttempt($user['user_type'], (int)$user['id'], (int)$user['organization_id'], $username, false, 'Account locked');
            $mins = (int)ceil((strtotime($user['locked_until']) - time()) / 60);
            throw new ApiError("Account locked after too many failed attempts. Try again in $mins minute(s).", 423);
        }

        if (!password_verify($password, $user['password_hash'])) {
            $attempts = (int)$user['failed_login_attempts'] + 1;
            $lockUntil = $attempts >= self::MAX_ATTEMPTS
                ? date('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60) : null;
            q("UPDATE $table SET failed_login_attempts = ?, locked_until = ? WHERE id = ?",
                [$lockUntil ? 0 : $attempts, $lockUntil, $user['id']]);
            self::logAttempt($user['user_type'], (int)$user['id'], (int)$user['organization_id'], $username, false,
                $lockUntil ? 'Wrong password - account locked' : 'Wrong password');
            if ($lockUntil) {
                throw new ApiError('Too many failed attempts. Your account is locked for ' . self::LOCK_MINUTES . ' minutes.', 423);
            }
            $left = self::MAX_ATTEMPTS - $attempts;
            throw new ApiError("Invalid username or password. $left attempt(s) left before lockout.", 401);
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            q("UPDATE $table SET password_hash = ? WHERE id = ?", [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        q("UPDATE $table SET failed_login_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?", [$user['id']]);

        $token = bin2hex(random_bytes(32));
        $lifetime = (int)env('SESSION_LIFETIME', 480);
        q('INSERT INTO user_sessions (user_type, user_id, session_token, ip_address, user_agent, expires_at)
           VALUES (?, ?, ?, ?, ?, ?)', [
            $user['user_type'], $user['id'], $token, client_ip(),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            date('Y-m-d H:i:s', time() + $lifetime * 60),
        ]);
        self::logAttempt($user['user_type'], (int)$user['id'], (int)$user['organization_id'], $username, true, null);

        return ['token' => $token, 'expires_in' => $lifetime * 60, 'user' => self::profile($user['user_type'], (int)$user['id'])];
    }

    public static function logout(): void
    {
        $token = self::bearerToken();
        if ($token) {
            q('UPDATE user_sessions SET is_active = 0 WHERE session_token = ?', [$token]);
        }
    }

    // -----------------------------------------------------------------
    // Request guards
    // -----------------------------------------------------------------
    /** The logged-in user, or a 401 error. */
    public static function user(): array
    {
        if (self::$current !== null) {
            return self::$current;
        }
        $token = self::bearerToken();
        if (!$token || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new ApiError('Please log in to continue.', 401);
        }
        $session = q('SELECT user_type, user_id FROM user_sessions
                      WHERE session_token = ? AND is_active = 1 AND expires_at > NOW()', [$token])->fetch();
        if (!$session) {
            throw new ApiError('Your session has expired. Please log in again.', 401);
        }
        $profile = self::profile($session['user_type'], (int)$session['user_id']);
        if (!$profile || !$profile['is_active']) {
            throw new ApiError('This account has been deactivated.', 401);
        }
        return self::$current = $profile;
    }

    /** Any employer (HR-side) user with the given permission. */
    public static function requirePermission(string $permission): array
    {
        $u = self::user();
        if ($u['user_type'] !== 'employer' || !in_array($u['role'], self::PERMISSIONS[$permission] ?? [], true)) {
            throw new ApiError('You do not have permission to do this.', 403);
        }
        return $u;
    }

    public static function requireEmployee(): array
    {
        $u = self::user();
        if ($u['user_type'] !== 'employee') {
            throw new ApiError('This action is only available to employees.', 403);
        }
        return $u;
    }

    public static function can(array $user, string $permission): bool
    {
        return $user['user_type'] === 'employer' && in_array($user['role'], self::PERMISSIONS[$permission] ?? [], true);
    }

    /** Throws 404 unless the employee belongs to the user's organisation. */
    public static function employeeInOrg(int $employeeId, array $user): array
    {
        $emp = q('SELECT * FROM employees WHERE id = ? AND organization_id = ?', [$employeeId, $user['organization_id']])->fetch();
        if (!$emp) {
            throw new ApiError('Employee not found.', 404);
        }
        return $emp;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------
    public static function profile(string $type, int $id): ?array
    {
        if ($type === 'employer') {
            $u = q('SELECT eu.id, eu.username, eu.email, eu.first_name, eu.last_name, eu.phone_number, eu.role,
                           eu.is_active, eu.organization_id, o.organization_name, o.organization_code
                    FROM employer_users eu JOIN organizations o ON o.id = eu.organization_id
                    WHERE eu.id = ? AND o.is_active = 1', [$id])->fetch();
            if (!$u) {
                return null;
            }
            $u['user_type'] = 'employer';
            $u['employee_id'] = null;
            $u['force_password_change'] = false;
            $u['permissions'] = array_keys(array_filter(self::PERMISSIONS, fn($roles) => in_array($u['role'], $roles, true)));
        } else {
            $u = q("SELECT us.id, us.username, us.is_active, us.force_password_change, us.employee_id,
                           e.first_name, e.last_name, COALESCE(e.work_email, e.personal_email) AS email,
                           e.phone_number, e.organization_id, e.employee_number, e.status AS employee_status,
                           o.organization_name, o.organization_code
                    FROM employee_users us
                    JOIN employees e ON e.id = us.employee_id
                    JOIN organizations o ON o.id = e.organization_id
                    WHERE us.id = ? AND o.is_active = 1", [$id])->fetch();
            if (!$u) {
                return null;
            }
            $u['user_type'] = 'employee';
            $u['role'] = 'employee';
            $u['is_active'] = $u['is_active'] && $u['employee_status'] === 'active';
            $u['force_password_change'] = (bool)$u['force_password_change'];
            $u['permissions'] = [];
            unset($u['employee_status']);
        }
        foreach (['id', 'organization_id', 'employee_id'] as $k) {
            $u[$k] = $u[$k] === null ? null : (int)$u[$k];
        }
        $u['is_active'] = (bool)$u['is_active'];
        return $u;
    }

    private static function findForLogin(string $username): ?array
    {
        $u = q("SELECT eu.id, eu.password_hash, eu.failed_login_attempts, eu.locked_until, eu.organization_id,
                       'employer' AS user_type
                FROM employer_users eu JOIN organizations o ON o.id = eu.organization_id
                WHERE eu.username = ? AND eu.is_active = 1 AND o.is_active = 1", [$username])->fetch();
        if ($u) {
            return $u;
        }
        $u = q("SELECT us.id, us.password_hash, us.failed_login_attempts, us.locked_until, e.organization_id,
                       'employee' AS user_type
                FROM employee_users us
                JOIN employees e ON e.id = us.employee_id
                JOIN organizations o ON o.id = e.organization_id
                WHERE us.username = ? AND us.is_active = 1 AND e.status = 'active' AND o.is_active = 1", [$username])->fetch();
        return $u ?: null;
    }

    public static function usernameTaken(string $username): bool
    {
        return (bool)q('SELECT 1 FROM employer_users WHERE username = ? UNION SELECT 1 FROM employee_users WHERE username = ?',
            [$username, $username])->fetch();
    }

    public static function validatePassword(string $password): void
    {
        if (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
            fail_field('password', 'Password must be at least 8 characters and contain both letters and numbers.');
        }
    }

    private static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) {
                    $header = $v;
                }
            }
        }
        return preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : null;
    }

    private static function logAttempt(string $type, ?int $userId, ?int $orgId, string $username, bool $success, ?string $reason): void
    {
        q('INSERT INTO login_logs (user_type, user_id, organization_id, username, success, reason, ip_address, user_agent)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
            $type, $userId, $orgId, mb_substr($username, 0, 60), $success ? 1 : 0, $reason, client_ip(),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    }
}
