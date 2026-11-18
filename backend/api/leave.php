<?php
/**
 * Leave management (Module 5) and employee leave self-service (Module 6).
 *   GET  ?action=types
 *   GET  ?action=balance[&employee_id=5][&year=2026]
 *   GET  (list)  employee: own requests; HR: organisation requests (filter: status, employee_id)
 *   POST ?action=apply   {leave_type_id, start_date, end_date, reason[, employee_id for HR]}
 *   POST ?action=review  {id, status: approved|rejected, comment}
 *   POST ?action=cancel  {id}   employee cancels own pending request
 */
require_once __DIR__ . '/../lib/bootstrap.php';

run(function () {
    $action = action();
    $user = Auth::user();
    $isEmployee = $user['user_type'] === 'employee';

    if ($action === 'types') {
        ok(q('SELECT * FROM leave_types WHERE organization_id = ? AND is_active = 1 ORDER BY id',
            [$user['organization_id']])->fetchAll());
    }

    if ($action === 'balance') {
        $empId = $isEmployee ? $user['employee_id'] : (int)($_GET['employee_id'] ?? 0);
        if (!$isEmployee) {
            Auth::requirePermission('employees.view');
            Auth::employeeInOrg($empId, $user);
        }
        ok(leave_balances($empId, $user['organization_id'], (int)($_GET['year'] ?? date('Y'))));
    }

    if ($action === 'apply' && method() === 'POST') {
        $in = input();
        require_fields($in, ['leave_type_id', 'start_date', 'end_date']);
        if ($isEmployee) {
            $empId = $user['employee_id'];
        } else {
            Auth::requirePermission('leave.review');
            require_fields($in, ['employee_id']);
            $empId = (int)$in['employee_id'];
            Auth::employeeInOrg($empId, $user);
        }
        $type = q('SELECT * FROM leave_types WHERE id = ? AND organization_id = ? AND is_active = 1',
            [(int)$in['leave_type_id'], $user['organization_id']])->fetch();
        if (!$type) {
            fail_field('leave_type_id', 'Choose a leave type.');
        }
        [$start, $end] = [(string)$in['start_date'], (string)$in['end_date']];
        if (!valid_date($start) || !valid_date($end)) {
            fail_field('start_date', 'Enter valid dates.');
        }
        if ($end < $start) {
            fail_field('end_date', 'The end date must be on or after the start date.');
        }
        if (substr($start, 0, 4) !== substr($end, 0, 4)) {
            fail_field('end_date', 'A leave request cannot span two calendar years; split it into two requests.');
        }
        $days = working_days($start, $end);
        if ($days <= 0) {
            fail_field('end_date', 'The selected dates contain no working days.');
        }
        assert_no_overlap($empId, $start, $end, null);
        assert_balance($empId, $user['organization_id'], $type, (int)substr($start, 0, 4), $days, true);

        q('INSERT INTO leave_requests (employee_id, leave_type_id, start_date, end_date, days, reason) VALUES (?, ?, ?, ?, ?, ?)',
            [$empId, $type['id'], $start, $end, $days, clean($in['reason'] ?? null, 500)]);
        $id = (int)db()->lastInsertId();
        Audit::log($user, 'leave.applied', 'leave_request', $id, ['days' => $days, 'type' => $type['code']]);
        ok(leave_row($id), "Leave request submitted for $days working day(s).", 201);
    }

    if ($action === 'review' && method() === 'POST') {
        $user = Auth::requirePermission('leave.review');
        $in = input();
        require_fields($in, ['id', 'status']);
        if (!in_array($in['status'], ['approved', 'rejected'], true)) {
            fail_field('status', 'Status must be approved or rejected.');
        }
        $req = q('SELECT lr.*, e.organization_id FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id
                  WHERE lr.id = ? AND e.organization_id = ?', [(int)$in['id'], $user['organization_id']])->fetch();
        if (!$req) {
            throw new ApiError('Leave request not found.', 404);
        }
        if ($req['status'] !== 'pending') {
            throw new ApiError("This request is already {$req['status']}.", 409);
        }
        if ($in['status'] === 'rejected' && empty(trim((string)($in['comment'] ?? '')))) {
            fail_field('comment', 'Give a reason when rejecting a request.');
        }
        if ($in['status'] === 'approved') {
            $type = q('SELECT * FROM leave_types WHERE id = ?', [$req['leave_type_id']])->fetch();
            assert_balance((int)$req['employee_id'], $user['organization_id'], $type, (int)substr($req['start_date'], 0, 4),
                (float)$req['days'], false);
        }
        q('UPDATE leave_requests SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_comment = ? WHERE id = ?',
            [$in['status'], $user['id'], clean($in['comment'] ?? null, 500), $req['id']]);

        if ($in['status'] === 'approved') {
            // Mark the leave days on the attendance register (without overwriting recorded attendance).
            $d = new DateTime($req['start_date']);
            $endD = new DateTime($req['end_date']);
            while ($d <= $endD) {
                if ((int)$d->format('N') < 6) {
                    q("INSERT IGNORE INTO attendance (employee_id, attendance_date, status, notes, recorded_by)
                       VALUES (?, ?, 'on_leave', 'Approved leave', 'hr')", [$req['employee_id'], $d->format('Y-m-d')]);
                }
                $d->modify('+1 day');
            }
        }
        Audit::log($user, "leave.{$in['status']}", 'leave_request', (int)$req['id']);
        notify_employee((int)$req['id']);
        ok(leave_row((int)$req['id']), 'Leave request ' . $in['status']);
    }

    if ($action === 'cancel' && method() === 'POST') {
        $user = Auth::requireEmployee();
        $in = input();
        $n = q("UPDATE leave_requests SET status = 'cancelled' WHERE id = ? AND employee_id = ? AND status = 'pending'",
            [(int)($in['id'] ?? 0), $user['employee_id']])->rowCount();
        if (!$n) {
            throw new ApiError('Only your own pending requests can be cancelled.', 409);
        }
        Audit::log($user, 'leave.cancelled', 'leave_request', (int)$in['id']);
        ok(null, 'Leave request cancelled');
    }

    if ($action === '' && method() === 'GET') {
        $where = ['e.organization_id = ?'];
        $params = [$user['organization_id']];
        if ($isEmployee) {
            $where[] = 'lr.employee_id = ?';
            $params[] = $user['employee_id'];
        } else {
            Auth::requirePermission('employees.view');
            if (!empty($_GET['employee_id'])) {
                $where[] = 'lr.employee_id = ?';
                $params[] = (int)$_GET['employee_id'];
            }
        }
        if (!empty($_GET['status'])) {
            $where[] = 'lr.status = ?';
            $params[] = $_GET['status'];
        }
        ok(q('SELECT lr.*, lt.name AS leave_type, CONCAT(e.first_name, \' \', e.last_name) AS employee_name,
                     e.employee_number, CONCAT(r.first_name, \' \', r.last_name) AS reviewed_by_name
              FROM leave_requests lr
              JOIN employees e ON e.id = lr.employee_id
              JOIN leave_types lt ON lt.id = lr.leave_type_id
              LEFT JOIN employer_users r ON r.id = lr.reviewed_by
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY (lr.status = \'pending\') DESC, lr.created_at DESC LIMIT 500', $params)->fetchAll());
    }

    throw new ApiError('Unknown request.', 404);
});

// ---------------------------------------------------------------------

/** Monday-Friday days between two dates, inclusive. Public holidays are not excluded. */
function working_days(string $start, string $end): float
{
    $days = 0;
    $d = new DateTime($start);
    $e = new DateTime($end);
    while ($d <= $e) {
        if ((int)$d->format('N') < 6) {
            $days++;
        }
        $d->modify('+1 day');
    }
    return (float)$days;
}

function leave_balances(int $employeeId, int $orgId, int $year): array
{
    return q("SELECT lt.id AS leave_type_id, lt.name, lt.code, lt.days_per_year AS entitlement,
                     COALESCE(SUM(CASE WHEN lr.status = 'approved' THEN lr.days END), 0) AS used,
                     COALESCE(SUM(CASE WHEN lr.status = 'pending' THEN lr.days END), 0) AS pending,
                     lt.days_per_year - COALESCE(SUM(CASE WHEN lr.status = 'approved' THEN lr.days END), 0) AS remaining
              FROM leave_types lt
              LEFT JOIN leave_requests lr ON lr.leave_type_id = lt.id AND lr.employee_id = ? AND YEAR(lr.start_date) = ?
              WHERE lt.organization_id = ? AND lt.is_active = 1
              GROUP BY lt.id ORDER BY lt.id", [$employeeId, $year, $orgId])->fetchAll();
}

function assert_balance(int $employeeId, int $orgId, array $type, int $year, float $days, bool $countPending): void
{
    foreach (leave_balances($employeeId, $orgId, $year) as $b) {
        if ((int)$b['leave_type_id'] === (int)$type['id']) {
            $available = (float)$b['remaining'] - ($countPending ? (float)$b['pending'] : 0);
            if ($days > $available) {
                throw new ApiError(sprintf('Not enough %s: %s day(s) requested, %s available%s.', strtolower($type['name']),
                    $days + 0, max(0, $available) + 0, $countPending && $b['pending'] > 0 ? ' after pending requests' : ''),
                    422, ['errors' => ['end_date' => 'Exceeds leave balance.']]);
            }
        }
    }
}

function assert_no_overlap(int $employeeId, string $start, string $end, ?int $exceptId): void
{
    $overlap = q("SELECT 1 FROM leave_requests WHERE employee_id = ? AND status IN ('pending','approved')
                  AND start_date <= ? AND end_date >= ? AND id <> ?", [$employeeId, $end, $start, $exceptId ?? 0])->fetch();
    if ($overlap) {
        fail_field('start_date', 'These dates overlap an existing pending or approved leave request.');
    }
}

function leave_row(int $id): array
{
    return q('SELECT lr.*, lt.name AS leave_type FROM leave_requests lr JOIN leave_types lt ON lt.id = lr.leave_type_id
              WHERE lr.id = ?', [$id])->fetch();
}

function notify_employee(int $requestId): void
{
    $r = q('SELECT lr.*, lt.name AS leave_type, e.first_name, COALESCE(e.work_email, e.personal_email) AS email
            FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id JOIN leave_types lt ON lt.id = lr.leave_type_id
            WHERE lr.id = ?', [$requestId])->fetch();
    if ($r && $r['email']) {
        Mailer::send($r['email'], "Leave request {$r['status']}", Mailer::layout('Leave request ' . $r['status'],
            '<p>Dear ' . htmlspecialchars($r['first_name']) . ',</p><p>Your ' . htmlspecialchars(strtolower($r['leave_type']))
            . ' request for ' . $r['start_date'] . ' to ' . $r['end_date'] . ' (' . ($r['days'] + 0) . ' days) has been <b>'
            . $r['status'] . '</b>.</p>' . ($r['review_comment'] ? '<p>Comment: ' . htmlspecialchars($r['review_comment']) . '</p>' : '')));
    }
}
