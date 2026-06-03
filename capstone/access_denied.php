<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/module_access.php';
require_once __DIR__ . '/includes/security_headers.php';

$moduleKey = trim((string)($_GET['module'] ?? ''));
$defs = getManagedModuleDefinitions();
$moduleName = $defs[$moduleKey] ?? 'this module';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Restricted - VIP System</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            font-family: 'Poppins', sans-serif;
            background: #f8fafc;
            color: #0f172a;
        }
        .card {
            width: min(92vw, 560px);
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
            padding: 28px;
        }
        h1 {
            margin: 0 0 8px 0;
            font-size: 1.35rem;
            color: #b91c1c;
        }
        p {
            margin: 0 0 12px 0;
            color: #334155;
            line-height: 1.6;
            font-size: 0.95rem;
        }
        .module {
            display: inline-block;
            margin-top: 6px;
            padding: 6px 10px;
            border-radius: 999px;
            background: #fee2e2;
            color: #991b1b;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .actions {
            margin-top: 18px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn {
            text-decoration: none;
            border: none;
            cursor: pointer;
            padding: 10px 14px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9rem;
        }
        .btn-primary {
            background: #2563eb;
            color: #fff;
        }
        .btn-secondary {
            background: #e2e8f0;
            color: #0f172a;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/loading_screen.php'; ?>
    <div class="card">
        <h1>Access Restricted</h1>
        <p>You can’t access this module right now.</p>
        <p>Please contact your manager if you need temporary access.</p>
        <div class="module">Module: <?php echo htmlspecialchars($moduleName); ?></div>
        <div class="actions">
            <a class="btn btn-primary" href="index.php">Go to Dashboard</a>
            <button class="btn btn-secondary" type="button" onclick="history.back()">Go Back</button>
        </div>
    </div>
</body>
</html>
