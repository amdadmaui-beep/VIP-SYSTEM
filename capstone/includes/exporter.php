<?php
/**
 * Data Export Utility
 * Provides CSV and Excel export functionality
 * 
 * Location: capstone/includes/exporter.php
 * Feature: Export data to CSV, Excel, and PDF formats
 */

/**
 * Export data to CSV
 * 
 * @param array $data Array of associative arrays
 * @param array $columns Column mapping ['field' => 'Header Label']
 * @param string $filename Output filename (without extension)
 * @return void
 */
function exportToCsv(array $data, array $columns, string $filename = 'export') {
    // Clean output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Sanitize filename to prevent header injection
    $safeFilename = preg_replace('/[^\w\-]/', '', $filename);
    if ($safeFilename === '') {
        $safeFilename = 'export';
    }
    
    // Set headers
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safeFilename . '_' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Output CSV
    $output = fopen('php://output', 'w');
    
    // Add BOM for UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Headers
    fputcsv($output, array_values($columns));
    
    // Data rows
    foreach ($data as $row) {
        $csvRow = [];
        foreach ($columns as $field => $label) {
            $value = $row[$field] ?? '';
            // Handle special cases
            if (is_array($value)) {
                $value = json_encode($value);
            }
            // Prevent CSV formula injection
            if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                $value = "'" . $value;
            }
            $csvRow[] = $value;
        }
        fputcsv($output, $csvRow);
    }
    
    fclose($output);
    exit;
}

/**
 * Export data to Excel (HTML format - compatible with Excel)
 * 
 * @param array $data Array of associative arrays
 * @param array $columns Column mapping ['field' => 'Header Label']
 * @param string $filename Output filename (without extension)
 * @param string $title Report title
 * @return void
 */
function exportToExcel(array $data, array $columns, string $filename = 'export', string $title = 'Report') {
    // Clean output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Sanitize filename to prevent header injection
    $safeFilename = preg_replace('/[^\w\-]/', '', $filename);
    if ($safeFilename === '') {
        $safeFilename = 'export';
    }
    
    // Set headers for Excel
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $safeFilename . '_' . date('Y-m-d') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Build HTML table that Excel can open
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta charset="UTF-8"></head>';
    echo '<body>';
    
    // Title
    echo '<h2>' . htmlspecialchars($title) . '</h2>';
    echo '<p>Generated: ' . date('F j, Y g:i A') . '</p>';
    
    // Table
    echo '<table border="1">';
    
    // Header row
    echo '<tr style="background-color: #6366f1; color: white; font-weight: bold;">';
    foreach ($columns as $field => $label) {
        echo '<th>' . htmlspecialchars($label) . '</th>';
    }
    echo '</tr>';
    
    // Data rows
    $rowNum = 0;
    foreach ($data as $row) {
        $bgColor = ($rowNum % 2 === 0) ? '#ffffff' : '#f8fafc';
        echo '<tr style="background-color: ' . $bgColor . ';">';
        foreach ($columns as $field => $label) {
            $value = $row[$field] ?? '';
            if (is_array($value)) {
                $value = json_encode($value);
            }
            // Format numbers
            if (is_numeric($value) && strpos($field, 'amount') !== false || strpos($field, 'price') !== false || strpos($field, 'total') !== false) {
                echo '<td style="mso-number-format:\"\\@\";">' . number_format((float)$value, 2) . '</td>';
            } else {
                echo '<td>' . htmlspecialchars((string)$value) . '</td>';
            }
        }
        echo '</tr>';
        $rowNum++;
    }
    
    echo '</table>';
    echo '</body>';
    echo '</html>';
    exit;
}

/**
 * Export to PDF (using simple HTML to PDF approach)
 * 
 * @param array $data Array of associative arrays
 * @param array $columns Column mapping
 * @param string $filename Output filename
 * @param string $title Report title
 * @return void
 */
function exportToPdf(array $data, array $columns, string $filename = 'export', string $title = 'Report') {
    // For PDF, we'll use a print-friendly HTML that users can print to PDF
    // This avoids requiring heavy PDF libraries
    
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: text/html; charset=utf-8');
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title><?php echo htmlspecialchars($title); ?></title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            h1 { color: #333; border-bottom: 2px solid #6366f1; padding-bottom: 10px; }
            .meta { color: #666; margin-bottom: 20px; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th { background: #6366f1; color: white; padding: 10px; text-align: left; }
            td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
            tr:nth-child(even) { background: #f8fafc; }
            .print-btn { 
                background: #6366f1; color: white; border: none; 
                padding: 10px 20px; border-radius: 6px; cursor: pointer;
                margin-bottom: 20px;
            }
            @media print {
                .print-btn { display: none; }
                body { margin: 0; }
            }
        </style>
    </head>
    <body>
        <button class="print-btn" onclick="window.print()">
            <i class="fas fa-print"></i> Print / Save as PDF
        </button>
        
        <h1><?php echo htmlspecialchars($title); ?></h1>
        <div class="meta">
            Generated: <?php echo date('F j, Y g:i A'); ?> | 
            Records: <?php echo count($data); ?>
        </div>
        
        <table>
            <thead>
                <tr>
                    <?php foreach ($columns as $field => $label): ?>
                        <th><?php echo htmlspecialchars($label); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data as $row): ?>
                <tr>
                    <?php foreach ($columns as $field => $label): 
                        $value = $row[$field] ?? '';
                        if (is_array($value)) $value = json_encode($value);
                        if (is_numeric($value) && (strpos($field, 'amount') !== false || strpos($field, 'price') !== false)) {
                            $value = '₱' . number_format((float)$value, 2);
                        }
                    ?>
                        <td><?php echo htmlspecialchars((string)$value); ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}

/**
 * Universal Export Function
 * Handles all export formats
 * 
 * @param array $data Data to export
 * @param array $columns Column definitions
 * @param string $filename Base filename
 * @param string $format csv|excel|pdf
 * @param string $title Report title
 * @return void
 */
function exportData(array $data, array $columns, string $filename = 'export', string $format = 'csv', string $title = 'Report') {
    switch (strtolower($format)) {
        case 'csv':
            exportToCsv($data, $columns, $filename);
            break;
        case 'excel':
        case 'xls':
        case 'xlsx':
            exportToExcel($data, $columns, $filename, $title);
            break;
        case 'pdf':
            exportToPdf($data, $columns, $filename, $title);
            break;
        default:
            throw new Exception('Unsupported export format: ' . $format);
    }
}

/**
 * Format value for export
 * 
 * @param mixed $value Value to format
 * @param string $type Data type (string, number, date, currency, boolean)
 * @return string Formatted value
 */
function formatExportValue($value, string $type = 'string'): string {
    switch ($type) {
        case 'number':
            return is_numeric($value) ? number_format((float)$value, 0) : '0';
        case 'decimal':
        case 'currency':
            return is_numeric($value) ? number_format((float)$value, 2) : '0.00';
        case 'date':
            return $value ? date('Y-m-d', strtotime($value)) : '';
        case 'datetime':
            return $value ? date('Y-m-d H:i:s', strtotime($value)) : '';
        case 'boolean':
            return $value ? 'Yes' : 'No';
        case 'json':
            return is_array($value) ? json_encode($value) : (string)$value;
        default:
            return (string)$value;
    }
}

/**
 * Common export column definitions
 */
class ExportColumns {
    
    /**
     * Sales columns
     */
    public static function sales(): array {
        return [
            'sale_id' => 'Sale ID',
            'customer_name' => 'Customer',
            'sale_date' => 'Date',
            'total_amount' => 'Total Amount',
            'payment_method' => 'Payment',
            'status' => 'Status',
            'created_by' => 'Created By',
            'notes' => 'Notes'
        ];
    }
    
    /**
     * Orders columns
     */
    public static function orders(): array {
        return [
            'order_id' => 'Order ID',
            'customer_name' => 'Customer',
            'order_date' => 'Order Date',
            'delivery_date' => 'Delivery Date',
            'total_amount' => 'Total',
            'status' => 'Status',
            'payment_status' => 'Payment',
            'created_by' => 'Created By'
        ];
    }
    
    /**
     * Accounts Receivable columns
     */
    public static function ar(): array {
        return [
            'ar_id' => 'AR ID',
            'customer_name' => 'Customer',
            'phone_number' => 'Phone Number',
            'invoice_number' => 'Invoice #',
            'invoice_date' => 'Invoice Date',
            'due_date' => 'Due Date',
            'invoice_amount' => 'Invoice Amount',
            'amount_due' => 'Balance Due',
            'status' => 'Status',
            'days_overdue' => 'Days Overdue'
        ];
    }
    
    /**
     * Inventory columns
     */
    public static function inventory(): array {
        return [
            'product_id' => 'Product ID',
            'product_name' => 'Product Name',
            'category' => 'Category',
            'current_stock' => 'Current Stock',
            'min_stock' => 'Min Stock',
            'unit_price' => 'Unit Price',
            'status' => 'Status',
            'last_updated' => 'Last Updated'
        ];
    }
    
    /**
     * Activity logs columns
     */
    public static function activityLogs(): array {
        return [
            'log_id' => 'Log ID',
            'user_name' => 'User',
            'activity_type' => 'Type',
            'action_details' => 'Details',
            'log_time' => 'Timestamp',
            'ip_address' => 'IP Address'
        ];
    }
    
    /**
     * Customers columns
     */
    public static function customers(): array {
        return [
            'customer_id' => 'Customer ID',
            'customer_name' => 'Name',
            'phone_number' => 'Phone',
            'email' => 'Email',
            'address' => 'Address',
            'credit_limit' => 'Credit Limit',
            'outstanding_balance' => 'Balance',
            'status' => 'Status',
            'created_date' => 'Created Date'
        ];
    }
}
