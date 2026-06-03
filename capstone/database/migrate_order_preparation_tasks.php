<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/preparation_tasks_helper.php';

prepTasksEnsureSchema($conn);

echo "OK: order_preparation_tasks table is ready.\n";
