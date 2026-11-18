<?php
/** GET /api/health.php : liveness check used by deployment scripts and uptime monitors. */
require_once __DIR__ . '/../lib/bootstrap.php';

run(function () {
    $started = microtime(true);
    q('SELECT 1');
    ok(['status' => 'up', 'database' => 'connected', 'time' => date('c'),
        'db_ms' => round((microtime(true) - $started) * 1000, 1)]);
});
