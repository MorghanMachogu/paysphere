<?php
/**
 * Attendance (Module 5). Overtime hours and absences recorded here feed payroll.
 *   HR / managers:
 *     GET  ?action=register&date=2026-03-02      every active employee with that day's record
 *     GET  ?month=3&year=2026[&employee_id=5]     records and per-employee monthly totals
 *     POST ?action=record  {employee_id, attendance_date, status, clock_in, clock_out, overtime_hours, notes}
 *     POST ?action=bulk    {attendance_date, records: [{employee_id, status, overtime_hours, ...}]}
 *   Employees:
 *     GET  ?action=today | ?action=mine&month=3&year=2026
 *     POST ?action=clock_in | ?action=clock_out
 */
require_once __DIR__ . '/../lib/bootstrap.php';

const ATT_STATUSES = ['present', 'late', 'absent', 'on_leave', 'half_day'];

run(function () {
    $action = action();
    $config = require __DIR__ . '/../config/payroll_config.php';
    $time = $config['working_time'];

    // ----- Employee self-service -----
    if (in_array($action, ['today', 'mine', 'clock_in', 'clock_out'], true)) {
        $user = Auth::requireEmployee();
        $empId = $user['employee_id'];
        $today = date('Y-m-d');

        if ($action === 'today') {
            ok(q('SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?', [$empId, $today])->fetch() ?: null);
        }
        if ($action === 'mine') {
            [$from, $to] = month_range();
            ok(q('SELECT * FROM attendance WHERE employee_id = ? AND attendance_date BETWEEN ? AND ? ORDER BY attendance_date DESC',
                [$empId, $from, $to])->fetchAll());
        }
        if (method() !== 'POST') {
            throw new ApiError('Method not allowed.', 405);
        }
        assert_month_open($user['organization_id'], $today);
        $row = q('SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?', [$empId, $today])->fetch();

        if ($action === 'clock_in') {
            if ($row && $row['clock_in']) {
                throw new ApiError('You have already clocked in today at ' . substr($row['clock_in'], 0, 5) . '.', 409);
            }
            $now = date('H:i:s');
            $lateAfter = date('H:i:s', strtotime($time['start_time']) + $time['grace_minutes'] * 60);
            $status = $now > $lateAfter ? 'late' : 'present';
            q("INSERT INTO attendance (employee_id, attendance_date, clock_in, status, recorded_by) VALUES (?, ?, ?, ?, 'employee')
               ON DUPLICATE KEY UPDATE clock_in = VALUES(clock_in), status = VALUES(status)", [$empId, $today, $now, $status]);
            Audit::log($user, 'attendance.clock_in', 'attendance', null, ['time' => $now]);
            ok(q('SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?', [$empId, $today])->fetch(),
                $status === 'late' ? 'Clocked in (late).' : 'Clocked in.');
        }

        if ($action === 'clock_out') {
            if (!$row || !$row['clock_in']) {
                throw new ApiError('Clock in first.', 409);
            }
            if ($row['clock_out']) {
                throw new ApiError('You have already clocked out today.', 409);
            }
            q('UPDATE attendance SET clock_out = ? WHERE id = ?', [date('H:i:s'), $row['id']]);
            Audit::log($user, 'attendance.clock_out', 'attendance', (int)$row['id']);
            ok(q('SELECT * FROM attendance WHERE id = ?', [$row['id']])->fetch(), 'Clocked out.');
        }
    }

    // ----- HR / managers -----
    if ($action === 'register') {
        $user = Auth::requirePermission('attendance.manage');
        $date = $_GET['date'] ?? date('Y-m-d');
        if (!valid_date($date)) {
            throw new ApiError('Invalid date.', 422);
        }
        ok(q("SELECT e.id AS employee_id, e.employee_number, CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
                     d.name AS department, a.id, a.status, a.clock_in, a.clock_out, a.overtime_hours, a.notes, a.recorded_by
              FROM employees e
              LEFT JOIN departments d ON d.id = e.department_id
              LEFT JOIN attendance a ON a.employee_id = e.id AND a.attendance_date = ?
              WHERE e.organization_id = ? AND e.status = 'active' AND e.hire_date <= ?
              ORDER BY e.employee_number", [$date, $user['organization_id'], $date])->fetchAll());
    }

    if ($action === 'record' && method() === 'POST') {
        $user = Auth::requirePermission('attendance.manage');
        $in = input();
        require_fields($in, ['employee_id', 'attendance_date', 'status']);
        save_record($user, (int)$in['employee_id'], (string)$in['attendance_date'], $in);
        ok(q('SELECT * FROM attendance WHERE employee_id = ? AND attendance_date = ?',
            [(int)$in['employee_id'], $in['attendance_date']])->fetch(), 'Attendance saved');
    }

    if ($action === 'bulk' && method() === 'POST') {
        $user = Auth::requirePermission('attendance.manage');
        $in = input();
        require_fields($in, ['attendance_date']);
        $records = (array)($in['records'] ?? []);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            foreach ($records as $r) {
                if (!empty($r['status'])) {
                    save_record($user, (int)($r['employee_id'] ?? 0), (string)$in['attendance_date'], $r);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        ok(['saved' => count($records)], count($records) . ' attendance record(s) saved');
    }

    if ($action === '' && method() === 'GET') {
        $user = Auth::requirePermission('attendance.manage');
        [$from, $to] = month_range();
        $where = 'e.organization_id = ? AND a.attendance_date BETWEEN ? AND ?';
        $params = [$user['organization_id'], $from, $to];
        if (!empty($_GET['employee_id'])) {
            $where .= ' AND a.employee_id = ?';
            $params[] = (int)$_GET['employee_id'];
        }
        $records = q("SELECT a.*, e.employee_number, CONCAT(e.first_name, ' ', e.last_name) AS employee_name
                      FROM attendance a JOIN employees e ON e.id = a.employee_id
                      WHERE $where ORDER BY a.attendance_date DESC, e.employee_number", $params)->fetchAll();
        $summary = q("SELECT a.employee_id, e.employee_number, CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
                             SUM(a.status IN ('present','late')) AS present_days, SUM(a.status = 'late') AS late_days,
                             SUM(a.status = 'absent') + 0.5 * SUM(a.status = 'half_day') AS absent_days,
                             SUM(a.status = 'on_leave') AS leave_days, SUM(a.overtime_hours) AS overtime_hours
                      FROM attendance a JOIN employees e ON e.id = a.employee_id
                      WHERE $where GROUP BY a.employee_id ORDER BY e.employee_number", $params)->fetchAll();
        ok(['records' => $records, 'summary' => $summary]);
    }

    throw new ApiError('Unknown request.', 404);
});

// ---------------------------------------------------------------------

function month_range(): array
{
    $m = (int)($_GET['month'] ?? date('n'));
    $y = (int)($_GET['year'] ?? date('Y'));
    $from = sprintf('%04d-%02d-01', $y, $m);
    return [$from, date('Y-m-t', strtotime($from))];
}

/** Attendance for a month cannot change once that month's payroll is approved or paid. */
function assert_month_open(int $orgId, string $date): void
{
    $status = q('SELECT status FROM payroll_runs WHERE organization_id = ? AND period_month = ? AND period_year = ?',
        [$orgId, (int)substr($date, 5, 2), (int)substr($date, 0, 4)])->fetchColumn();
    if ($status && $status !== 'draft') {
        throw new ApiError("Payroll for this month is already $status, so its attendance is locked.", 409);
    }
}

function save_record(array $user, int $empId, string $date, array $in): void
{
    Auth::employeeInOrg($empId, $user);
    if (!valid_date($date)) {
        fail_field('attendance_date', 'Enter a valid date.');
    }
    if ($date > date('Y-m-d')) {
        fail_field('attendance_date', 'Attendance cannot be recorded for a future date.');
    }
    if (!in_array($in['status'], ATT_STATUSES, true)) {
        fail_field('status', 'Invalid attendance status.');
    }
    $ot = (float)($in['overtime_hours'] ?? 0);
    if ($ot < 0 || $ot > 16) {
        fail_field('overtime_hours', 'Overtime must be between 0 and 16 hours a day.');
    }
    foreach (['clock_in', 'clock_out'] as $f) {
        if (!empty($in[$f]) && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string)$in[$f])) {
            fail_field($f, 'Use the 24-hour format HH:MM.');
        }
    }
    assert_month_open($user['organization_id'], $date);
    q("INSERT INTO attendance (employee_id, attendance_date, clock_in, clock_out, status, overtime_hours, notes, recorded_by)
       VALUES (?, ?, ?, ?, ?, ?, ?, 'hr')
       ON DUPLICATE KEY UPDATE clock_in = VALUES(clock_in), clock_out = VALUES(clock_out), status = VALUES(status),
         overtime_hours = VALUES(overtime_hours), notes = VALUES(notes), recorded_by = 'hr'",
        [$empId, $date, ($in['clock_in'] ?? null) ?: null, ($in['clock_out'] ?? null) ?: null, $in['status'], $ot, clean($in['notes'] ?? null)]);
    Audit::log($user, 'attendance.recorded', 'attendance', null,
        ['employee_id' => $empId, 'date' => $date, 'status' => $in['status'], 'overtime_hours' => $ot]);
}
