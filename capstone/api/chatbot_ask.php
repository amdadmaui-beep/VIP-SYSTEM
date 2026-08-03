<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

if (!defined('VIP_AI_ASSISTANT_ENABLED') || !VIP_AI_ASSISTANT_ENABLED) {
    http_response_code(503);
    echo json_encode(['success' => false, 'reply' => 'VIP AI Assistant is currently disabled.']);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'reply' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$question = trim($input['question'] ?? '');
$snapshot = $input['snapshot'] ?? [];

if (!$question) {
    http_response_code(400);
    echo json_encode(['success' => false, 'reply' => 'No question provided.']);
    exit();
}

// ── Load Gemini API key from .env ────────────────────────────────────────────
$envPath = __DIR__ . '/../.env';
$env = [];
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $env[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
    }
}
$geminiKey = $env['GEMINI_API_KEY'] ?? '';

if (!$geminiKey) {
    // Return friendly message asking them to configure the key
    echo json_encode([
        'success'     => true,
        'reply'       => "⚙️ <strong>AI Key Not Configured</strong><br><br>To enable the AI chatbot, add your Gemini API key to the <code>.env</code> file:<br><br><code>GEMINI_API_KEY=your_key_here</code><br><br>Get a free key at <strong>aistudio.google.com</strong> → Create API key.",
        'needs_key'   => true,
    ]);
    exit();
}

// ── Build system context string from snapshot ────────────────────────────────
function fmt($n) { return '₱' . number_format((float)$n, 2); }

$ctx = "You are an intelligent, high-end business assistant for the VIP System... You have access to real-time data from the system database. Answer the user's question accurately and concisely. Use Markdown formatting: **bold** for emphasis, line breaks for readability, and • for bullet points. Do NOT output raw HTML tags like <strong> or <br>.\n\n";

$ctx .= "=== CURRENT DATE/TIME: " . date('l, F j Y h:i A') . " ===\n\n";

// Customers
$ctx .= "CUSTOMERS:\n";
$ctx .= "- Total customers: " . ($snapshot['customers']['total'] ?? 0) . "\n";
$ctx .= "- New today: " . ($snapshot['customers']['new_today'] ?? 0) . "\n";
$ctx .= "- New this month: " . ($snapshot['customers']['new_this_month'] ?? 0) . "\n";
$ctx .= "- Overdue customer count: " . ($snapshot['customers']['overdue_count'] ?? 0) . "\n";
$ctx .= "- Total overdue balance: " . fmt($snapshot['customers']['overdue_total'] ?? 0) . "\n";
$ctx .= "- Total AR balance (all unpaid): " . fmt($snapshot['customers']['total_ar_balance'] ?? 0) . "\n";
if (!empty($snapshot['customers']['overdue_list'])) {
    $ctx .= "- Overdue customers:\n";
    foreach ($snapshot['customers']['overdue_list'] as $c) {
        $ctx .= "  * {$c['customer_name']}: owes " . fmt($c['total_overdue']) . ", overdue since {$c['oldest_due']} ({$c['invoice_count']} invoice(s))\n";
    }
}

// Sales
$ctx .= "\nSALES (from sales+sale_details tables):\n";
$ctx .= "- Today: " . fmt($snapshot['sales']['today'] ?? 0) . " (" . ($snapshot['sales']['tx_today'] ?? 0) . " transactions)\n";
$ctx .= "- Yesterday: " . fmt($snapshot['sales']['yesterday'] ?? 0) . "\n";
$ctx .= "- This week: " . fmt($snapshot['sales']['this_week'] ?? 0) . "\n";
$ctx .= "- Last week: " . fmt($snapshot['sales']['last_week'] ?? 0) . "\n";
$ctx .= "- This month: " . fmt($snapshot['sales']['this_month'] ?? 0) . " (" . ($snapshot['sales']['tx_month'] ?? 0) . " transactions)\n";
$ctx .= "- Last month: " . fmt($snapshot['sales']['last_month'] ?? 0) . "\n";
$ctx .= "- This year: " . fmt($snapshot['sales']['this_year'] ?? 0) . "\n";
$ctx .= "- Last year: " . fmt($snapshot['sales']['last_year'] ?? 0) . "\n";

// Orders
$ctx .= "\nORDERS:\n";
$ctx .= "- Today: " . ($snapshot['orders']['today'] ?? 0) . " orders (" . fmt($snapshot['orders']['revenue_today'] ?? 0) . " revenue)\n";
$ctx .= "- This week: " . ($snapshot['orders']['this_week'] ?? 0) . "\n";
$ctx .= "- This month: " . ($snapshot['orders']['this_month'] ?? 0) . " (" . fmt($snapshot['orders']['revenue_month'] ?? 0) . " revenue)\n";
$ctx .= "- Last month: " . ($snapshot['orders']['last_month'] ?? 0) . "\n";
$ctx .= "- This year: " . ($snapshot['orders']['this_year'] ?? 0) . "\n";
$ctx .= "- All time total: " . ($snapshot['orders']['total'] ?? 0) . "\n";
$ctx .= "- Currently pending: " . ($snapshot['orders']['pending'] ?? 0) . "\n";
if (!empty($snapshot['orders']['today_list'])) {
    $ctx .= "- Today's orders:\n";
    foreach ($snapshot['orders']['today_list'] as $o) {
        $ctx .= "  * #{$o['Order_ID']} {$o['customer_name']} — {$o['order_status']} (" . fmt($o['total_amount']) . ")\n";
    }
}

// Inventory
$ctx .= "\nINVENTORY:\n";
$ctx .= "- Active products: " . ($snapshot['inventory']['total_products'] ?? 0) . "\n";
$ctx .= "- Discontinued: " . ($snapshot['inventory']['discontinued'] ?? 0) . "\n";
if (!empty($snapshot['inventory']['top_selling_month'])) {
    $ctx .= "- Top selling this month:\n";
    foreach ($snapshot['inventory']['top_selling_month'] as $i => $p) {
        $ctx .= "  " . ($i+1) . ". {$p['product_name']}: {$p['units_sold']} units, " . fmt($p['revenue']) . "\n";
    }
}
if (!empty($snapshot['inventory']['top_selling_alltime'])) {
    $ctx .= "- Top selling all time:\n";
    foreach ($snapshot['inventory']['top_selling_alltime'] as $i => $p) {
        $ctx .= "  " . ($i+1) . ". {$p['product_name']}: {$p['units_sold']} units, " . fmt($p['revenue']) . "\n";
    }
}
if (!empty($snapshot['inventory']['low_stock'])) {
    $ctx .= "- Low stock (<= 50 units):\n";
    foreach ($snapshot['inventory']['low_stock'] as $p) {
        $ctx .= "  * {$p['product_name']}: {$p['qty']} units\n";
    }
}

// Damage
$ctx .= "\nDAMAGE GOODS:\n";
$ctx .= "- Today: " . ($snapshot['damage']['today'] ?? 0) . " units (" . ($snapshot['damage']['reports_today'] ?? 0) . " reports)\n";
$ctx .= "- This week: " . ($snapshot['damage']['this_week'] ?? 0) . " units\n";
$ctx .= "- This month: " . ($snapshot['damage']['this_month'] ?? 0) . " units\n";
$ctx .= "- Last month: " . ($snapshot['damage']['last_month'] ?? 0) . " units\n";
$ctx .= "- This year: " . ($snapshot['damage']['this_year'] ?? 0) . " units\n";
if (!empty($snapshot['damage']['by_type'])) {
    $ctx .= "- Damage by type (this month):\n";
    foreach ($snapshot['damage']['by_type'] as $d) {
        $ctx .= "  * {$d['damage_type']}: {$d['qty']} units\n";
    }
}

// Deliveries
$ctx .= "\nDELIVERIES:\n";
$ctx .= "- Scheduled today: " . ($snapshot['deliveries']['today_count'] ?? 0) . "\n";
$ctx .= "- Currently in transit/pending: " . ($snapshot['deliveries']['pending'] ?? 0) . "\n";
$ctx .= "- Overdue (missed schedule): " . ($snapshot['deliveries']['overdue'] ?? 0) . "\n";
$ctx .= "- Completed today: " . ($snapshot['deliveries']['completed_today'] ?? 0) . "\n";
$ctx .= "- This month total: " . ($snapshot['deliveries']['this_month'] ?? 0) . "\n";
if (!empty($snapshot['deliveries']['today_list'])) {
    $ctx .= "- Today's delivery schedule:\n";
    foreach ($snapshot['deliveries']['today_list'] as $d) {
        $rider = $d['rider_name'] ? "Rider: {$d['rider_name']}" : "No rider assigned";
        $ctx .= "  * {$d['customer_name']}: {$d['delivery_status']}, {$rider}, Address: {$d['delivery_address']}\n";
    }
} else {
    $ctx .= "- No deliveries scheduled today.\n";
}

// Users
$ctx .= "\nSYSTEM USERS:\n";
$ctx .= "- Total active users: " . ($snapshot['users']['total'] ?? 0) . "\n";
if (!empty($snapshot['users']['by_role'])) {
    foreach ($snapshot['users']['by_role'] as $r) {
        $ctx .= "- {$r['role_name']}: {$r['count']} user(s)\n";
    }
}
if (!empty($snapshot['users']['all_list'])) {
    $ctx .= "- All active staff:\n";
    foreach ($snapshot['users']['all_list'] as $u) {
        $ctx .= "  * {$u['full_name']} ({$u['role_name']})\n";
    }
}
if (!empty($snapshot['users']['active_now'])) {
    $ctx .= "- Currently online (last 3 minutes):\n";
    foreach ($snapshot['users']['active_now'] as $u) {
        $ctx .= "  * {$u['full_name']} ({$u['role_name']}) — last seen {$u['last_seen_at']}\n";
    }
} else {
    $ctx .= "- No users currently online in the last 3 minutes.\n";
}
if (!empty($snapshot['users']['login_history'])) {
    $ctx .= "- Login history (last 7 days, most recent first):\n";
    foreach ($snapshot['users']['login_history'] as $u) {
        $out = $u['logout_at'] ? "logged out {$u['logout_at']}" : "({$u['status']})";
        $ctx .= "  * {$u['full_name']} ({$u['role_name']}) — logged in {$u['login_at']}, $out\n";
    }
}

// Activity logs
if (!empty($snapshot['activity']['recent'])) {
    $ctx .= "\nRECENT ACTIVITY LOG:\n";
    foreach ($snapshot['activity']['recent'] as $a) {
        $ctx .= "- [{$a['Log_Time']}] {$a['user_name']} ({$a['role_name']}): {$a['Activity_Type']} — {$a['Action_Details']}\n";
    }
}

$ctx .= "\n=== END OF SYSTEM DATA ===\n";

// ── Agent Tool Implementations ────────────────────────────────────────────────
function build_public_report_url(string $filename): string {
    // Serve downloads via PHP endpoint to avoid Apache "403 Forbidden" on /reports/*.xls in some setups.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $capstoneBase = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/capstone/api/chatbot_ask.php')), "/\\");
    if ($capstoneBase === '') $capstoneBase = '/capstone';
    $q = http_build_query(['file' => $filename]);
    return "{$scheme}://{$host}{$capstoneBase}/api/download_report.php?{$q}";
}

function generate_csv_report($module, $range, $conn, $format = 'csv') {
    try {
        $reportsDir = __DIR__ . '/../reports';
        if (!is_dir($reportsDir)) {
            @mkdir($reportsDir, 0775, true);
        }
        if (!is_dir($reportsDir) || !is_writable($reportsDir)) {
            return "Error: Reports folder is missing or not writable on the server.";
        }

        $format = strtolower(trim((string)$format));
        $isExcel = in_array($format, ['excel', 'xls', 'xlsx'], true);
        $ext = $isExcel ? 'xls' : 'csv';

        $filename = "report_{$module}_" . date('Ymd_His') . ".{$ext}";
        $filepath = $reportsDir . '/' . $filename;
        
        // Build rows in-memory first so we can write as CSV or Excel (HTML .xls).
        $rows = [];
        
        if ($module === 'sales') {
            $rows[] = ['Sale ID', 'Customer Name', 'Total Amount', 'Sale Date', 'Status'];
            $sql = "SELECT s.Sale_ID, COALESCE(c.customer_name, 'Walk-in') AS customer_name, s.total_amount, s.created_at, s.status 
                    FROM sales s LEFT JOIN customers c ON s.Customer_ID = c.Customer_ID 
                    ORDER BY s.created_at DESC LIMIT 500";
            $stmt = $conn->query($sql);
            while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rows[] = [$row['Sale_ID'], $row['customer_name'], $row['total_amount'], $row['created_at'], $row['status']];
            }
        } 
        elseif ($module === 'orders') {
            $rows[] = ['Order ID', 'Customer Name', 'Total Amount', 'Order Date', 'Status'];
            $sql = "SELECT o.Order_ID, COALESCE(c.customer_name, 'Walk-in') AS customer_name, o.total_amount, o.order_date, o.order_status 
                    FROM orders o LEFT JOIN customers c ON o.Customer_ID = c.Customer_ID 
                    ORDER BY o.order_date DESC LIMIT 500";
            $stmt = $conn->query($sql);
            while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rows[] = [$row['Order_ID'], $row['customer_name'], $row['total_amount'], $row['order_date'], $row['order_status']];
            }
        }
        elseif ($module === 'customers') {
            $rows[] = ['Customer ID', 'Name', 'Email', 'Phone', 'Credit Limit'];
            $sql = "SELECT Customer_ID, customer_name, email, phone_number, credit_limit 
                    FROM customers WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 500";
            $stmt = $conn->query($sql);
            while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rows[] = [$row['Customer_ID'], $row['customer_name'], $row['email'], $row['phone_number'], $row['credit_limit']];
            }
        }
        elseif ($module === 'damage_goods' || $module === 'damage') {
            $rows[] = ['Damage ID', 'Inventory ID', 'Quantity', 'Damage Type', 'Reported At'];
            $sql = "SELECT d.Damage_ID, d.Inventory_ID, d.quantity, d.damage_type, d.created_at 
                    FROM damage_goods d 
                    ORDER BY d.created_at DESC LIMIT 500";
            $stmt = $conn->query($sql);
            while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $rows[] = [$row['Damage_ID'], $row['Inventory_ID'], $row['quantity'], $row['damage_type'], $row['created_at']];
            }
        } else {
            return "Error: Unknown module '$module'. Please specify sales, orders, customers, or damage_goods.";
        }

        if ($isExcel) {
            // Excel-friendly HTML table saved as .xls (opens directly in Excel).
            $html = "<html><head><meta charset='UTF-8'></head><body><table border='1'>";
            foreach ($rows as $r) {
                $html .= "<tr>";
                foreach ($r as $cell) {
                    $cell = htmlspecialchars((string)$cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $html .= "<td>{$cell}</td>";
                }
                $html .= "</tr>";
            }
            $html .= "</table></body></html>";
            if (file_put_contents($filepath, $html) === false) {
                return "Error: Could not write Excel report file on the server.";
            }
        } else {
            $fp = fopen($filepath, 'w');
            if (!$fp) return "Error: Could not create report file on the server.";
            foreach ($rows as $r) {
                fputcsv($fp, $r);
            }
            fclose($fp);
        }

        $publicUrl = build_public_report_url($filename);
        $label = $isExcel ? "Download {$module}.xls (Excel)" : "Download {$module}.csv";
        return "SUCCESS: Report generated. Tell the user to download it immediately using this HTML link exactly as written: <a href='{$publicUrl}' target='_blank' class='text-violet-600 underline font-semibold'>{$label}</a>";
    } catch (Exception $e) {
        return "Error building report: " . $e->getMessage();
    }
}

// ── Define AI Tools (Function Calling) ─────────────────────────────────────────
$tools = [[
    'functionDeclarations' => [[
        'name' => 'generate_csv_report',
        'description' => 'Generates a highly-detailed report and saves it to the server for download. Use this tool if the user asks to export/download a report/spreadsheet. Prefer Excel format when the user says Excel.',
        'parameters' => [
            'type' => 'OBJECT',
            'properties' => [
                'module' => [
                    'type' => 'STRING',
                    'description' => 'The data module to generate a report for. Must be exactly one of: "sales", "orders", "customers", "damage_goods"'
                ],
                'date_range' => [
                    'type' => 'STRING',
                    'description' => 'Optional filter range (e.g. "today", "this_month").'
                ],
                'format' => [
                    'type' => 'STRING',
                    'description' => 'Output format. Use "excel" for an Excel-openable file, otherwise "csv".'
                ]
            ],
            'required' => ['module']
        ]
    ]]
]];

// ── Agent Execution Loop ───────────────────────────────────────────────────────
$apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key={$geminiKey}";

$messages = [
    [
        'role' => 'user',
        'parts' => [['text' => $ctx . "\nUSER QUESTION: " . $question]]
    ]
];

$maxTurns = 3;
$turn = 0;
$finalText = "No response from AI.";

while ($turn < $maxTurns) {
    $payload = [
        'contents'         => $messages,
        'tools'            => $tools,
        'generationConfig' => [
            'temperature'     => 0.2, // Lower temp for more reliable tool calls
            'maxOutputTokens' => 800,
        ]
    ];

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 30,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        $errDetail = "AI service error (HTTP $httpCode).";
        if ($response) {
            $rData = json_decode($response, true);
            if (isset($rData['error']['message'])) $errDetail .= " Google API says: " . $rData['error']['message'];
        }
        echo json_encode(['success' => false, 'reply' => "⚠️ {$errDetail}"]);
        exit();
    }

    $result = json_decode($response, true);
    $candidate = $result['candidates'][0] ?? null;
    $parts = $candidate['content']['parts'] ?? [];
    
    // Check if the AI wants to call a tool.
    // IMPORTANT (Gemini 3 "thought signatures"):
    // When the model returns a functionCall part, we MUST replay that exact part (including
    // thought_signature if present) in the next request. Reconstructing the functionCall
    // without the signature can trigger HTTP 400: "missing thought_signature".
    $functionCallParts = [];
    $functionCalls = [];
    foreach ($parts as $p) {
        if (isset($p['functionCall'])) {
            $functionCallParts[] = $p;               // preserve verbatim, including thought_signature
            $functionCalls[] = $p['functionCall'];   // convenience for execution
        }
    }
    
    if (!empty($functionCalls)) {
        // Record the AI's tool call(s) in history EXACTLY as returned
        $messages[] = [
            'role' => 'model',
            'parts' => $functionCallParts
        ];
        
        // Execute each requested tool call, then append functionResponse(s).
        // Gemini REST expects functionResponse under role: "user".
        foreach ($functionCalls as $functionCall) {
            $fnName = $functionCall['name'] ?? '';
            $args = $functionCall['args'] ?? [];
            
            $fnResult = "Internal Server Error: Tool not implemented.";
            if ($fnName === 'generate_csv_report') {
                $fnResult = generate_csv_report(
                    $args['module'] ?? '',
                    $args['date_range'] ?? '',
                    $conn,
                    $args['format'] ?? 'csv'
                );
            }
            
            $messages[] = [
                'role' => 'user',
                'parts' => [[
                    'functionResponse' => [
                        'name' => $fnName,
                        'response' => ['result' => $fnResult]
                    ]
                ]]
            ];
        }
        
        $turn++;
        continue; // Loop again to let AI generate its final string text
    }
    
    // No function call was made, we have the final text!
    foreach ($parts as $p) {
        if (isset($p['text'])) {
            $finalText = $p['text'];
            break;
        }
    }
    break;
}

// ── Render Output ─────────────────────────────────────────────────────────────
// Escape raw HTML so the browser doesn't hide text wrapped in <... >
// but we explicitly preserve <a> tags if the AI returned a link from the tool.
$text = htmlspecialchars($finalText, ENT_QUOTES, 'UTF-8');
$text = str_replace(['&lt;a href=', 'target=&#039;_blank&#039;', '&lt;/a&gt;', '&gt;'], ['<a href=', "target='_blank'", '</a>', '>'], $text);
$text = str_replace('class=&#039;text-violet-600 underline font-semibold&#039;', "class='text-violet-600 underline font-semibold'", $text);

// Convert markdown-style to HTML
$text = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $text);
$text = preg_replace('/^#{1,3} (.+)$/m', '<strong>$1</strong>', $text);
$text = preg_replace('/\n/', '<br>', $text);

echo json_encode(['success' => true, 'reply' => $text]);
