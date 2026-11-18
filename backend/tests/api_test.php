<?php
/**
 * End-to-end API tests for the functional test cases in Chapter Four (Table 2).
 *
 * Prerequisites: database loaded, demo data seeded, API running:
 *   php backend/scripts/seed_demo.php
 *   php -S 127.0.0.1:8000 -t backend
 *   php backend/tests/api_test.php [http://127.0.0.1:8000/api]
 *
 * The test re-seeds nothing itself; re-run seed_demo.php before each full run.
 */
$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000/api', '/');
$passed = 0;
$failed = 0;

function call(string $method, string $path, ?array $body = null, ?string $token = null): array
{
    global $base;
    $headers = "Content-Type: application/json\r\n";
    if ($token) {
        $headers .= "Authorization: Bearer $token\r\n";
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => $headers, 'ignore_errors' => true,
        'content' => $body === null ? '' : json_encode($body)]]);
    $raw = file_get_contents("$base/$path", false, $ctx);
    preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0] ?? '', $m);
    return ['status' => (int)($m[1] ?? 0), 'json' => json_decode((string)$raw, true), 'raw' => (string)$raw];
}

function tc(string $id, string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed++;
    printf("%-6s %-4s %s%s\n", $id, $ok ? 'PASS' : 'FAIL', $name, $ok || !$detail ? '' : "  -> $detail");
}

function login(string $u, string $p = 'PaySphere2026'): ?string
{
    return call('POST', 'auth.php?action=login', ['username' => $u, 'password' => $p])['json']['data']['token'] ?? null;
}

$month = (int)date('n', strtotime('first day of last month'));
$year = (int)date('Y', strtotime('first day of last month'));
$suffix = substr((string)time(), -6);

// TC-01 Organisation sign-up
$r = call('POST', 'organization_signup.php', [
    'organization_name' => "Test Traders $suffix", 'kra_pin' => 'P051111111A', 'organization_email' => "t$suffix@example.com",
    'admin_first_name' => 'Test', 'admin_last_name' => 'Admin', 'admin_email' => "a$suffix@example.com",
    'username' => "testadmin$suffix", 'password' => 'Secret2026']);
tc('TC-01', 'Organisation sign-up creates organisation and admin', $r['status'] === 201, $r['raw']);

// TC-02 / TC-03 Logins
$admin = login('admin');
$me = call('GET', 'auth.php?action=me', null, $admin)['json']['data'] ?? [];
tc('TC-02', 'HR login returns token and employer profile', $admin && ($me['user_type'] ?? '') === 'employer');
$jane = login('jane.wanjiku');
$meJ = call('GET', 'auth.php?action=me', null, $jane)['json']['data'] ?? [];
tc('TC-03', 'Employee login is routed as an employee', $jane && ($meJ['user_type'] ?? '') === 'employee');

// TC-04 Wrong password
$r = call('POST', 'auth.php?action=login', ['username' => 'payroll', 'password' => 'wrong-password']);
tc('TC-04', 'Wrong password is rejected with no session', $r['status'] === 401 && empty($r['json']['data']['token']));

// TC-05 Lockout after five failures
for ($i = 0; $i < 5; $i++) {
    $r = call('POST', 'auth.php?action=login', ['username' => "testadmin$suffix", 'password' => 'nope']);
}
$after = call('POST', 'auth.php?action=login', ['username' => "testadmin$suffix", 'password' => 'Secret2026']);
tc('TC-05', 'Five failed attempts lock the account (correct password refused)', $r['status'] === 423 && $after['status'] === 423);

// TC-06 Unauthenticated access
$r = call('GET', 'payroll.php?action=runs');
tc('TC-06', 'Unauthenticated request is refused (401)', $r['status'] === 401);

// TC-07 Employee calling an HR action
$r = call('POST', 'payroll.php?action=generate', ['month' => $month, 'year' => $year], $jane);
tc('TC-07', 'Employee cannot generate payroll (403)', $r['status'] === 403);

// TC-08 Cross-organisation isolation (second org, unlocked via a fresh signup)
call('POST', 'organization_signup.php', ['organization_name' => "Other Co $suffix", 'organization_email' => "o$suffix@example.com",
    'admin_first_name' => 'Other', 'admin_last_name' => 'Admin', 'admin_email' => "oa$suffix@example.com",
    'username' => "otheradmin$suffix", 'password' => 'Secret2026']);
$other = login("otheradmin$suffix", 'Secret2026');
$demoEmployees = call('GET', 'employees.php', null, $admin)['json']['data'];
$probe = call('GET', 'employees.php?id=' . $demoEmployees[0]['id'], null, $other);
$list = call('GET', 'employees.php', null, $other)['json']['data'];
tc('TC-08', 'Another organisation cannot see these employees', $probe['status'] === 404 && count($list) === 0);

// TC-09 Add employee
$r = call('POST', 'employees.php', ['first_name' => 'Wairimu', 'last_name' => 'Gitau', 'national_id' => '2' . $suffix,
    'kra_pin' => 'A012345678Z', 'hire_date' => date('Y-m-d'), 'basic_salary' => 38000, 'housing_allowance' => 4000,
    'payment_method' => 'mpesa', 'mpesa_number' => '0712345678', 'work_email' => "w$suffix@example.com"], $admin);
$temp = $r['json']['data']['temporary_password'] ?? '';
$newUser = $r['json']['data']['username'] ?? '';
$newTok = $temp ? login($newUser, $temp) : null;
$newMe = $newTok ? call('GET', 'auth.php?action=me', null, $newTok)['json']['data'] : [];
tc('TC-09', 'Employee, salary and login created; first login forces password change',
    $r['status'] === 201 && ($newMe['force_password_change'] ?? false) === true, $r['raw']);

// TC-10 Invalid KRA PIN
$r = call('POST', 'employees.php', ['first_name' => 'Bad', 'last_name' => 'Pin', 'national_id' => '3' . $suffix,
    'kra_pin' => '12345', 'hire_date' => date('Y-m-d'), 'basic_salary' => 20000, 'mpesa_number' => '0712345679'], $admin);
tc('TC-10', 'Invalid KRA PIN rejected, nothing created', $r['status'] === 422 && isset($r['json']['errors']['kra_pin']));

// TC-11..17 engine
exec(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/run_tests.php'), $out, $code);
tc('TC-11-17', 'Payroll engine statutory tests (' . trim(end($out)) . ')', $code === 0);

// TC-18 Bulk generation
$payrollTok = login('payroll');
$r = call('POST', 'payroll.php?action=generate', ['month' => $month, 'year' => $year], $payrollTok);
$run = $r['json']['data'] ?? [];
$active = count(array_filter($demoEmployees, fn($e) => $e['status'] === 'active'));
tc('TC-18', "Bulk payroll for {$active} employees in " . ($run['processing_ms'] ?? '?') . ' ms',
    $r['status'] === 200 && (int)$run['employee_count'] === $active, $r['raw']);

// TC-19 Re-run same period
$r2 = call('POST', 'payroll.php?action=generate', ['month' => $month, 'year' => $year], $payrollTok);
tc('TC-19', 'Regenerating a draft updates it without duplicates',
    $r2['status'] === 200 && $r2['json']['data']['id'] === $run['id'] && count($r2['json']['data']['payslips']) === $active);

// TC-20 Approve and pay
$r = call('POST', 'payroll.php?action=approve', ['run_id' => $run['id']], $payrollTok);
$denied = $r['status'] === 403; // payroll officer cannot approve
$hr = login('hr');
$a = call('POST', 'payroll.php?action=approve', ['run_id' => $run['id']], $hr);
$p = call('POST', 'payroll.php?action=pay', ['run_id' => $run['id']], $payrollTok);
$regen = call('POST', 'payroll.php?action=generate', ['month' => $month, 'year' => $year], $payrollTok);
tc('TC-20', 'draft -> approved -> paid; officer cannot approve; paid run is locked',
    $denied && ($a['json']['data']['status'] ?? '') === 'approved' && ($p['json']['data']['status'] ?? '') === 'paid'
    && $regen['status'] === 409);

// TC-21 Payslip and reports
$slips = $p['json']['data']['payslips'];
$slip = call('GET', 'payroll.php?action=payslip&id=' . $slips[0]['id'], null, $payrollTok);
$report = call('GET', "payroll.php?action=report&type=statutory&run_id={$run['id']}", null, $payrollTok);
$csv = call('GET', "payroll.php?action=report&type=statutory&format=csv&run_id={$run['id']}", null, $payrollTok);
$sumPaye = array_sum(array_column($slips, 'paye'));
tc('TC-21', 'Payslip, statutory report and CSV generated; totals match payslips',
    strpos($slip['raw'], 'NET PAY') !== false && strpos($report['raw'], number_format($sumPaye, 2)) !== false
    && substr_count(trim($csv['raw']), "\n") === count($slips));

// Attendance lock after approval
$r = call('POST', 'attendance.php?action=record', ['employee_id' => $demoEmployees[1]['id'],
    'attendance_date' => sprintf('%04d-%02d-05', $year, $month), 'status' => 'absent'], $hr);
tc('extra', 'Attendance for a paid month is locked', $r['status'] === 409);

// TC-22 Apply and approve leave
$start = date('Y-m-d', strtotime('monday next week +14 days'));
$end = date('Y-m-d', strtotime("$start +1 day"));
$types = call('GET', 'leave.php?action=types', null, $jane)['json']['data'];
$annual = array_values(array_filter($types, fn($t) => $t['code'] === 'ANNUAL'))[0]['id'];
$before = array_values(array_filter(call('GET', 'leave.php?action=balance', null, $jane)['json']['data'],
    fn($b) => $b['code'] === 'ANNUAL'))[0];
$ap = call('POST', 'leave.php?action=apply', ['leave_type_id' => $annual, 'start_date' => $start, 'end_date' => $end,
    'reason' => 'Test'], $jane);
$rv = call('POST', 'leave.php?action=review', ['id' => $ap['json']['data']['id'] ?? 0, 'status' => 'approved'],
    login('manager'));
$afterB = array_values(array_filter(call('GET', 'leave.php?action=balance', null, $jane)['json']['data'],
    fn($b) => $b['code'] === 'ANNUAL'))[0];
tc('TC-22', 'Leave applied and approved; balance reduced by 2 days',
    $ap['status'] === 201 && $rv['status'] === 200 && (float)$before['remaining'] - (float)$afterB['remaining'] === 2.0,
    $ap['raw'] . ' ' . $rv['raw']);

// TC-23 Exceeding balance
$r = call('POST', 'leave.php?action=apply', ['leave_type_id' => $annual,
    'start_date' => (date('Y') + 1) . '-01-05', 'end_date' => (date('Y') + 1) . '-02-27'], $jane); // ~40 working days > 21
tc('TC-23', 'Leave beyond the remaining balance is rejected', $r['status'] === 422 && stripos($r['raw'], 'available') !== false, $r['raw']);

// TC-24 Self-service payslips
$mine = call('GET', 'payroll.php?action=my_payslips', null, $jane)['json']['data'];
$janeId = $meJ['employee_id'];
$ownOnly = count($mine) > 0 && count(array_filter($slips, fn($s) => (int)$s['employee_id'] === $janeId
    && in_array($s['id'], array_column($mine, 'id')))) === count($mine);
$othersSlip = array_values(array_filter($slips, fn($s) => (int)$s['employee_id'] !== $janeId))[0];
$peek = call('GET', 'payroll.php?action=payslip&id=' . $othersSlip['id'], null, $jane);
tc('TC-24', "Employee sees only own payslips; another employee's payslip returns 404", $ownOnly && $peek['status'] === 404);

// Employee clock-in
$c = call('POST', 'attendance.php?action=clock_in', [], $jane);
$c2 = call('POST', 'attendance.php?action=clock_in', [], $jane);
tc('extra', 'Employee clock-in works once per day', $c['status'] === 200 && $c2['status'] === 409, $c['raw']);

// Logout
call('POST', 'auth.php?action=logout', [], $jane);
tc('extra', 'Logged-out token is rejected', call('GET', 'auth.php?action=me', null, $jane)['status'] === 401);

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
