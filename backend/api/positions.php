<?php
/** Job positions.  GET list | POST create | PUT ?id= update | DELETE ?id= deactivate */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/OrgStructure.php';

run(fn() => OrgStructure::handle('positions', 'title', 'position'));
