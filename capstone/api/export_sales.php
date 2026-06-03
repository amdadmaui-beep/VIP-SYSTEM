<?php
/**
 * Export Sales Report to Excel
 *
 * Produces a per-line-item report matching the format:
 *   Title: "MONTH START - END, YEAR"
 *   Columns: DATE | KILO/GRMS | BAGS/PACK | AMOUNT
 *
 * Usage:
 *   /api/export_sales.php?start=2025-12-12&end=2025-12-31
 *   /api/export_sales.php?start=2025-12-12&end=2025-12-31&format=csv
 *
 * Location: capstone/api/export_sales.php
 */

require_once __DIR__ . '/../includes/middleware.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/module_access.php';

try {
    // Auth
    $request = new ApiRequest();
    $response = new ApiResponse();
    authMiddleware($request, $response, function() {});

    $current_user_id = (int)$request->userId;
    if (!isModuleAllowedForUser($conn, $current_user_id, 'sales_report', true)) {
        $response->error('Sales report export access is currently restricted for your account.', 403);
    }

    // ── Parameters ──────────────────────────────────────────────────────────
    $format    = strtolower($request->get('format', 'excel'));
    $startDate = $request->get('start');
    $endDate   = $request->get('end');
    $date      = $request->get('date');

    // Legacy single-date param
    if (!$startDate && !$endDate && $date) {
        if ($date === 'today') {
            $startDate = $endDate = date('Y-m-d');
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $startDate = $endDate = $date;
        }
    }

    // Defaults & validation
    if (!$startDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        $startDate = date('Y-m-d');
    }
    if (!$endDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        $endDate = $startDate;
    }
    if ($startDate > $endDate) {
        [$startDate, $endDate] = [$endDate, $startDate];
    }

    // ── Query: one row per sale line-item ───────────────────────────────────
    $query = "
        SELECT
            DATE(s.created_at)  AS sale_date,
            p.product_name,
            sd.quantity,
            sd.subtotal
        FROM sale_details sd
        INNER JOIN sales    s ON sd.Sale_ID    = s.Sale_ID
        INNER JOIN products p ON sd.Product_ID = p.Product_ID
        WHERE DATE(s.created_at) BETWEEN :start AND :end
          AND (s.status IS NULL OR s.status != 'Cancelled')
        ORDER BY DATE(s.created_at) ASC, sd.Sale_detail_ID ASC
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute([':start' => $startDate, ':end' => $endDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Build report title ───────────────────────────────────────────────────
    $dtStart = new DateTime($startDate);
    $dtEnd   = new DateTime($endDate);

    if ($dtStart->format('Y-m') === $dtEnd->format('Y-m')) {
        // Same month: "DECEMBER 12 - 31, 2025"
        $reportTitle = strtoupper($dtStart->format('F')) . ' '
            . $dtStart->format('j') . ' - '
            . $dtEnd->format('j') . ', '
            . $dtEnd->format('Y');
    } else {
        // Different months: "DEC 12, 2025 - JAN 5, 2026"
        $reportTitle = strtoupper($dtStart->format('M')) . ' ' . $dtStart->format('j, Y')
            . ' - '
            . strtoupper($dtEnd->format('M'))  . ' ' . $dtEnd->format('j, Y');
    }

    $filename = 'sales_report_' . $startDate . ($startDate !== $endDate ? '_to_' . $endDate : '');

    // ── CSV export ───────────────────────────────────────────────────────────
    if ($format === 'csv') {
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
        fputcsv($out, [$reportTitle]);
        fputcsv($out, []);
        fputcsv($out, ['DATE', 'KILO/GRMS', 'BAGS/PACK', 'AMOUNT']);

        foreach ($rows as $r) {
            $d = $r['sale_date'] ? date('n/j/Y', strtotime($r['sale_date'])) : '';
            fputcsv($out, [
                $d,
                $r['product_name'],
                (int)$r['quantity'],
                number_format((float)$r['subtotal'], 2),
            ]);
        }
        fclose($out);
        exit;
    }

    // ── Excel (HTML/XLS) export ──────────────────────────────────────────────
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Calculate totals
    $grandTotal   = array_sum(array_column($rows, 'subtotal'));
    $grandBags    = array_sum(array_column($rows, 'quantity'));

    ?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel">
<head>
<meta charset="UTF-8">
<xml>
  <x:ExcelWorkbook>
    <x:ExcelWorksheets>
      <x:ExcelWorksheet>
        <x:Name>Sales Report</x:Name>
        <x:WorksheetOptions>
          <x:Print>
            <x:ValidPrinterInfo/>
            <x:HorizontalResolution>600</x:HorizontalResolution>
            <x:VerticalResolution>600</x:VerticalResolution>
          </x:Print>
        </x:WorksheetOptions>
      </x:ExcelWorksheet>
    </x:ExcelWorksheets>
  </x:ExcelWorkbook>
</xml>
<style>
  * {
    font-family: Arial, sans-serif;
    font-size: 11pt;
  }
  table {
    border-collapse: collapse;
  }
  td {
    padding: 2px 8px;
    vertical-align: middle;
    mso-number-format: "\@";
  }
  .store-name {
    font-family: Arial, sans-serif;
    font-size: 20pt;
    font-weight: bold;
    text-align: center;
    mso-number-format: "\@";
  }
  .report-title {
    font-family: Arial, sans-serif;
    font-size: 13pt;
    font-weight: bold;
    text-align: center;
    mso-number-format: "\@";
  }
  .spacer {
    font-size: 6pt;
  }
  .col-header {
    font-family: Arial, sans-serif;
    font-size: 11pt;
    font-weight: bold;
    text-align: center;
    mso-number-format: "\@";
  }
  .col-header-date {
    font-family: Arial, sans-serif;
    font-size: 11pt;
    font-weight: bold;
    text-align: left;
    mso-number-format: "\@";
  }
  .col-header-amount {
    font-family: Arial, sans-serif;
    font-size: 11pt;
    font-weight: bold;
    text-align: right;
    mso-number-format: "\@";
  }
  .td-date {
    font-family: Arial, sans-serif;
    font-size: 11pt;
    text-align: left;
    mso-number-format: "\@";
  }
  .td-kilo {
    font-family: Arial, sans-serif;
    font-size: 11pt;
    text-align: center;
    mso-number-format: "\@";
  }
  .td-bags {
    font-family: Arial, sans-serif;
    font-size: 11pt;
    text-align: center;
    mso-number-format: "\@";
  }
  .td-peso {
    font-family: Arial, sans-serif;
    font-size: 11pt;
    text-align: right;
    mso-number-format: "\@";
  }
  .td-amt {
    font-family: Arial, sans-serif;
    font-size: 11pt;
    text-align: right;
    mso-number-format: "\@";
  }
  .total-label {
    font-family: Arial, sans-serif;
    font-size: 11pt;
    font-weight: bold;
    text-align: left;
    mso-number-format: "\@";
  }
  .total-bags {
    font-family: Arial, sans-serif;
    font-size: 11pt;
    font-weight: bold;
    text-align: center;
    mso-number-format: "\@";
  }
  .total-amt {
    font-family: Arial, sans-serif;
    font-size: 11pt;
    font-weight: bold;
    text-align: right;
    mso-number-format: "\@";
  }
  .total-peso {
    font-family: Arial, sans-serif;
    font-size: 11pt;
    font-weight: bold;
    text-align: right;
    mso-number-format: "\@";
  }
</style>
</head>
<body>
<table>
  <!-- Store name -->
  <tr>
    <td colspan="5" class="store-name">VIP ICE MINI STORE</td>
  </tr>
  <!-- Date range title -->
  <tr>
    <td colspan="5" class="report-title"><?= htmlspecialchars($reportTitle) ?></td>
  </tr>
  <!-- Spacer -->
  <tr>
    <td colspan="5" class="spacer">&nbsp;</td>
  </tr>
  <!-- Column headers -->
  <tr>
    <td class="col-header-date">DATE</td>
    <td class="col-header">KILO/GRMS</td>
    <td class="col-header">BAGS/PACK</td>
    <td class="col-header-amount" colspan="2">AMOUNT</td>
  </tr>
<?php foreach ($rows as $r):
    $dateStr = $r['sale_date'] ? date('n/j/Y', strtotime($r['sale_date'])) : '';
    $amount  = number_format((float)$r['subtotal'], 2);
    $kiloGrms = strtoupper($r['product_name']);
?>
  <tr>
    <td class="td-date"><?= htmlspecialchars($dateStr) ?></td>
    <td class="td-kilo"><?= htmlspecialchars($kiloGrms) ?></td>
    <td class="td-bags"><?= (int)$r['quantity'] ?></td>
    <td class="td-peso">&#8369;</td>
    <td class="td-amt"><?= $amount ?></td>
  </tr>
<?php endforeach; ?>
  <!-- Gap before total -->
  <tr>
    <td colspan="5" class="spacer">&nbsp;</td>
  </tr>
  <!-- Total row -->
  <tr>
    <td class="total-label"></td>
    <td class="td-kilo"></td>
    <td class="total-bags"><?= number_format((int)$grandBags) ?></td>
    <td class="total-peso">&#8369;</td>
    <td class="total-amt"><?= number_format((float)$grandTotal, 2) ?></td>
  </tr>
</table>
</body>
</html>
<?php
    exit;


} catch (Throwable $e) {
    http_response_code(500);
    error_log('export_sales failed: ' . $e->getMessage());
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => false, 'error' => 'Export failed']);
}
