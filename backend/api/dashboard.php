<?php
/** GET /api/dashboard.php : HR dashboard figures for the logged-in user's organisation. */
require_once __DIR__ . '/../lib/bootstrap.php';

run(function () {
    $user = Auth::requirePermission('employees.view');
    $org = $user['organization_id'];
    $today = date('Y-m-d');

    $counts = q("SELECT COUNT(*) AS total, SUM(status = 'active') AS active FROM employees WHERE organization_id = ?",
        [$org])->fetch();
    $onLeave = q("SELECT COUNT(DISTINCT lr.employee_id) FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id
                  WHERE e.organization_id = ? AND lr.status = 'approved' AND ? BETWEEN lr.start_date AND lr.end_date",
        [$org, $today])->fetchColumn();
    $pendingLeave = q("SELECT COUNT(*) FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id
                       WHERE e.organization_id = ? AND lr.status = 'pending'", [$org])->fetchColumn();
    $presentToday = q("SELECT COUNT(*) FROM attendance a JOIN employees e ON e.id = a.employee_id
                       WHERE e.organization_id = ? AND a.attendance_date = ? AND a.status IN ('present','late')",
        [$org, $today])->fetchColumn();

    $data = [
        'employees_total'  => (int)$counts['total'],
        'employees_active' => (int)$counts['active'],
        'on_leave_today'   => (int)$onLeave,
        'pending_leave'    => (int)$pendingLeave,
        'present_today'    => (int)$presentToday,
        'by_department'    => q("SELECT COALESCE(d.name, 'Unassigned') AS department, COUNT(*) AS employees
                                 FROM employees e LEFT JOIN departments d ON d.id = e.department_id
                                 WHERE e.organization_id = ? AND e.status = 'active'
                                 GROUP BY d.name ORDER BY employees DESC", [$org])->fetchAll(),
    ];

    if (Auth::can($user, 'payroll.view')) {
        $data['payroll_trend'] = array_reverse(q('SELECT id, period_month, period_year, status, employee_count,
                                       total_gross, total_net FROM payroll_runs WHERE organization_id = ?
                                       ORDER BY period_year DESC, period_month DESC LIMIT 6', [$org])->fetchAll());
        $data['latest_run'] = end($data['payroll_trend']) ?: null;
    }

    $data['recent_activity'] = q("SELECT a.action, a.entity, a.created_at,
                                         COALESCE(CONCAT(eu.first_name, ' ', eu.last_name), CONCAT(e.first_name, ' ', e.last_name)) AS actor
                                  FROM audit_log a
                                  LEFT JOIN employer_users eu ON a.user_type = 'employer' AND eu.id = a.user_id
                                  LEFT JOIN employee_users us ON a.user_type = 'employee' AND us.id = a.user_id
                                  LEFT JOIN employees e ON e.id = us.employee_id
                                  WHERE a.organization_id = ? ORDER BY a.id DESC LIMIT 8", [$org])->fetchAll();
    ok($data);
});
