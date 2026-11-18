<?php
/**
 * Departments.  GET list | POST create | PUT ?id= update | DELETE ?id= deactivate
 * Employees and positions use the same pattern (see positions.php).
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/OrgStructure.php';

run(fn() => OrgStructure::handle('departments', 'name', 'department'));
