<?php
require_once __DIR__ . '/../vendor/autoload.php';
$config = require __DIR__ . '/mail_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;



function createConfiguredMailer(): array
{
    global $config;
    $mailHost = getenv('MAIL_HOST') ?: (string)($config['host'] ?? '');
    $mailPort = (int)(getenv('MAIL_PORT') ?: (int)($config['port'] ?? 587));
    $mailUser = getenv('MAIL_USERNAME') ?: (string)($config['username'] ?? '');
    $mailPass = getenv('MAIL_PASSWORD') ?: (string)($config['password'] ?? '');
    $mailFrom = getenv('MAIL_FROM_ADDRESS') ?: (string)($config['from_address'] ?? $mailUser);
    $mailFromName = getenv('MAIL_FROM_NAME') ?: (string)($config['from_name'] ?? 'VIP System');
    $mailSecure = strtolower((string)(getenv('MAIL_ENCRYPTION') ?: (string)($config['encryption'] ?? 'tls')));

    if ($mailHost === '' || $mailUser === '' || $mailPass === '' || $mailFrom === '') {
        return [
            'ok' => false,
            'message' => 'Mail server is not configured. Please update includes/mail_config.php or set MAIL_* environment variables.'
        ];
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $mailHost;
        $mail->SMTPAuth = true;
        $mail->Username = $mailUser;
        $mail->Password = $mailPass;
        $mail->Port = $mailPort;
        $mail->SMTPSecure = ($mailSecure === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->setFrom($mailFrom, $mailFromName);
        $mail->isHTML(true);
        return ['ok' => true, 'mail' => $mail];
    } catch (Exception $e) {
        return ['ok' => false, 'message' => 'Failed to initialize mailer: ' . $e->getMessage()];
    }
}

/**
 * Send password reset code email using SMTP.
 */
function sendPasswordResetCodeEmail(string $toEmail, string $toName, string $code): array
{
    $setup = createConfiguredMailer();
    if (!$setup['ok']) return $setup;
    $mail = $setup['mail'];

    try {
        $mail->addAddress($toEmail, $toName !== '' ? $toName : $toEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Your VIP System Password Reset Code';

        $logoPath = realpath(__DIR__ . '/../assets/img/VIP-LOGS - Copy.jpg');
        $hasLogo = ($logoPath && file_exists($logoPath));
        if ($hasLogo) {
            $mail->addEmbeddedImage($logoPath, 'logo');
        }

        $mail->Body = '
<div style="background-color: #f0f9ff; padding: 40px 10px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
    <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 500px; background-color: #ffffff; border-radius: 16px; border: 1px solid #e0f2fe; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
        <tr>
            <td style="padding: 40px 30px;">
                <table border="0" cellpadding="0" cellspacing="0" width="100%">
                    <tr>
                        <td style="padding-bottom: 30px;">
                            ' . ($hasLogo ? '<img src="cid:logo" alt="VIP Ice Plant" width="120" style="display: block; border: 0;" />' : '<h2 style="margin: 0; color: #2563eb;">VIP SYSTEM</h2>') . '
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <h1 style="margin: 0 0 15px; font-size: 24px; color: #1e3a8a;">Password Reset</h1>
                            <p style="margin: 0 0 25px; font-size: 16px; line-height: 24px; color: #475569;">
                                Hello <strong>' . htmlspecialchars($toName ?: 'User', ENT_QUOTES, 'UTF-8') . '</strong>,<br><br>
                                Use the verification code below to reset your password:
                            </p>
                            <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; text-align: center; margin-bottom: 25px;">
                                <span style="font-family: monospace; font-size: 36px; font-weight: bold; letter-spacing: 4px; color: #2563eb;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</span>
                            </div>
                            <div style="background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 15px; margin-bottom: 25px; border-radius: 4px;">
                                <p style="margin: 0; font-size: 13px; color: #92400e;">
                                    <strong>Important:</strong> This code expires in 5 minutes.
                                </p>
                            </div>
                            <p style="margin: 0; font-size: 14px; color: #64748b; line-height: 20px;">
                                If you did not request this, please ignore this email.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td style="padding: 0 30px 40px; text-align: center; font-size: 12px; color: #94a3b8;">
                &copy; ' . date('Y') . ' VIP Ice Plant. All rights reserved.
            </td>
        </tr>
    </table>
</div>';
        $mail->AltBody = "Hello " . ($toName ?: 'User')
            . ",\n\nYour password reset verification code is: " . $code
            . "\nThis code will expire in 5 minutes.\n\nIf you did not request this, you can ignore this email.";

        $mail->send();

        return ['ok' => true, 'message' => 'Reset code sent successfully.'];
    } catch (Exception $e) {
        $err = (string)$mail->ErrorInfo;
        if (stripos($err, 'authenticate') !== false) {
            $err .= ' | SMTP auth failed. Check username, app password, and encryption/port in includes/mail_config.php.';
        }
        return [
            'ok' => false,
            'message' => 'Failed to send reset email: ' . $err
        ];
    }
}

/**
 * Send password change verification code email using SMTP.
 * Used when a logged-in user is changing their password.
 */
function sendPasswordChangeCodeEmail(string $toEmail, string $toName, string $code): array
{
    $setup = createConfiguredMailer();
    if (!$setup['ok']) return $setup;
    $mail = $setup['mail'];

    try {
        $mail->addAddress($toEmail, $toName !== '' ? $toName : $toEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Your VIP System Password Change Code';

        $logoPath = realpath(__DIR__ . '/../assets/img/VIP-LOGS - Copy.jpg');
        $hasLogo = ($logoPath && file_exists($logoPath));
        if ($hasLogo) {
            $mail->addEmbeddedImage($logoPath, 'logo');
        }

        $mail->Body = '
<div style="background-color: #f0f9ff; padding: 40px 10px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
    <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 500px; background-color: #ffffff; border-radius: 16px; border: 1px solid #e0f2fe; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);">
        <tr>
            <td style="padding: 40px 30px;">
                <table border="0" cellpadding="0" cellspacing="0" width="100%">
                    <tr>
                        <td style="padding-bottom: 30px;">
                            ' . ($hasLogo ? '<img src="cid:logo" alt="VIP Ice Plant" width="120" style="display: block; border: 0;" />' : '<h2 style="margin: 0; color: #2563eb;">VIP SYSTEM</h2>') . '
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <h1 style="margin: 0 0 15px; font-size: 24px; color: #1e3a8a;">Password Change</h1>
                            <p style="margin: 0 0 25px; font-size: 16px; line-height: 24px; color: #475569;">
                                Hello <strong>' . htmlspecialchars($toName ?: 'User', ENT_QUOTES, 'UTF-8') . '</strong>,<br><br>
                                A password change was requested. Use the code below to verify:
                            </p>
                            <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; text-align: center; margin-bottom: 25px;">
                                <span style="font-family: monospace; font-size: 36px; font-weight: bold; letter-spacing: 4px; color: #2563eb;">' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '</span>
                            </div>
                            <div style="background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 15px; margin-bottom: 25px; border-radius: 4px;">
                                <p style="margin: 0; font-size: 13px; color: #92400e;">
                                    <strong>Important:</strong> This code expires in 5 minutes.
                                </p>
                            </div>
                            <p style="margin: 0; font-size: 14px; color: #64748b; line-height: 20px;">
                                If you did not request this change, please contact an administrator.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td style="padding: 0 30px 40px; text-align: center; font-size: 12px; color: #94a3b8;">
                &copy; ' . date('Y') . ' VIP Ice Plant. All rights reserved.
            </td>
        </tr>
    </table>
</div>';
        $mail->AltBody = "Hello " . ($toName ?: 'User')
            . ",\n\nWe received a request to change your password."
            . "\nYour verification code is: " . $code
            . "\nThis code will expire in 5 minutes."
            . "\n\nIf you did not request this, please contact an administrator immediately.";

        $mail->send();
        return ['ok' => true, 'message' => 'Verification code sent successfully.'];
    } catch (Exception $e) {
        $err = (string)$mail->ErrorInfo;
        if (stripos($err, 'authenticate') !== false) {
            $err .= ' | SMTP auth failed. Check username, app password, and encryption/port in includes/mail_config.php.';
        }
        return ['ok' => false, 'message' => 'Failed to send verification email: ' . $err];
    }
}

function sendARBalanceReminderEmail(string $toEmail, string $toName, int $arId, float $amountDue, string $dueDate): array
{
    $setup = createConfiguredMailer();
    if (!$setup['ok']) return $setup;
    $mail = $setup['mail'];

    try {
        $mail->addAddress($toEmail, $toName !== '' ? $toName : $toEmail);
        $mail->Subject = 'Balance Reminder - VIP Ice Plant';
        $formattedAmount = number_format($amountDue, 2);
        $safeName = htmlspecialchars($toName ?: 'Customer', ENT_QUOTES, 'UTF-8');
        $safeDueDate = htmlspecialchars($dueDate, ENT_QUOTES, 'UTF-8');

        $mail->Body = '<p>Hello ' . $safeName . ',</p>'
            . '<p>This is a friendly reminder regarding your outstanding balance with VIP Ice Plant.</p>'
            . '<p><strong>Reference:</strong> AR-' . (int)$arId . '<br>'
            . '<strong>Balance Due:</strong> PHP ' . $formattedAmount . '<br>'
            . '<strong>Due Date:</strong> ' . $safeDueDate . '</p>'
            . '<p>Please settle your account at your earliest convenience. Thank you.</p>';

        $mail->AltBody = "Hello " . ($toName ?: 'Customer')
            . ",\n\nThis is a reminder for your outstanding balance."
            . "\nReference: AR-" . (int)$arId
            . "\nBalance Due: PHP " . $formattedAmount
            . "\nDue Date: " . $dueDate
            . "\n\nPlease settle your account at your earliest convenience. Thank you.";

        $mail->send();
        return ['ok' => true, 'message' => 'Reminder email sent successfully.'];
    } catch (Exception $e) {
        return ['ok' => false, 'message' => 'Failed to send reminder email: ' . (string)$mail->ErrorInfo];
    }
}

function sendARCreatedEmail(string $toEmail, string $toName, int $arId, float $invoiceAmount, float $amountDue, string $dueDate, int $saleId = 0): array
{
    $setup = createConfiguredMailer();
    if (!$setup['ok']) return $setup;
    $mail = $setup['mail'];

    try {
        $mail->addAddress($toEmail, $toName !== '' ? $toName : $toEmail);
        $mail->Subject = 'Account Receivable Created - VIP Ice Plant';

        $safeName = htmlspecialchars($toName ?: 'Customer', ENT_QUOTES, 'UTF-8');
        $safeDueDate = htmlspecialchars($dueDate, ENT_QUOTES, 'UTF-8');
        $reference = 'AR-' . (int)$arId;
        $saleReference = $saleId > 0 ? '#' . (int)$saleId : 'N/A';

        $mail->Body = '
<div style="background-color:#f8fafc;padding:36px 10px;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">
  <table align="center" cellpadding="0" cellspacing="0" width="100%" style="max-width:560px;background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;">
    <tr>
      <td style="padding:28px 32px;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#ffffff;">
        <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1px;opacity:.88;">Account Receivable Notice</div>
        <h2 style="margin:6px 0 0;font-size:22px;">Reference ' . htmlspecialchars($reference, ENT_QUOTES, 'UTF-8') . '</h2>
      </td>
    </tr>
    <tr>
      <td style="padding:28px 32px;color:#334155;">
        <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">Hello <strong>' . $safeName . '</strong>,</p>
        <p style="margin:0 0 18px;font-size:15px;line-height:1.6;">An account receivable has been recorded for your delivery order with VIP Ice Plant.</p>
        <table cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;">
          <tr>
            <td style="padding:12px 16px;color:#64748b;font-size:13px;">AR Reference</td>
            <td style="padding:12px 16px;text-align:right;color:#0f172a;font-weight:700;">' . htmlspecialchars($reference, ENT_QUOTES, 'UTF-8') . '</td>
          </tr>
          <tr>
            <td style="padding:12px 16px;color:#64748b;font-size:13px;border-top:1px solid #e2e8f0;">Sale Reference</td>
            <td style="padding:12px 16px;text-align:right;color:#0f172a;font-weight:700;border-top:1px solid #e2e8f0;">' . htmlspecialchars($saleReference, ENT_QUOTES, 'UTF-8') . '</td>
          </tr>
          <tr>
            <td style="padding:12px 16px;color:#64748b;font-size:13px;border-top:1px solid #e2e8f0;">Invoice Amount</td>
            <td style="padding:12px 16px;text-align:right;color:#0f172a;font-weight:700;border-top:1px solid #e2e8f0;">PHP ' . number_format($invoiceAmount, 2) . '</td>
          </tr>
          <tr>
            <td style="padding:12px 16px;color:#64748b;font-size:13px;border-top:1px solid #e2e8f0;">Total Balance</td>
            <td style="padding:12px 16px;text-align:right;color:#b45309;font-weight:800;border-top:1px solid #e2e8f0;">PHP ' . number_format($amountDue, 2) . '</td>
          </tr>
          <tr>
            <td style="padding:12px 16px;color:#64748b;font-size:13px;border-top:1px solid #e2e8f0;">Due Date</td>
            <td style="padding:12px 16px;text-align:right;color:#0f172a;font-weight:700;border-top:1px solid #e2e8f0;">' . $safeDueDate . '</td>
          </tr>
        </table>
        <p style="margin:18px 0 0;font-size:14px;line-height:1.6;color:#64748b;">Please use the AR reference above when settling your balance.</p>
      </td>
    </tr>
  </table>
</div>';

        $mail->AltBody = "Hello " . ($toName ?: 'Customer')
            . ",\n\nAn account receivable has been recorded for your delivery order."
            . "\nAR Reference: " . $reference
            . "\nSale Reference: " . $saleReference
            . "\nInvoice Amount: PHP " . number_format($invoiceAmount, 2)
            . "\nTotal Balance: PHP " . number_format($amountDue, 2)
            . "\nDue Date: " . $dueDate
            . "\n\nPlease use the AR reference above when settling your balance.";

        $mail->send();
        return ['ok' => true, 'message' => 'AR notice email sent successfully.'];
    } catch (Exception $e) {
        return ['ok' => false, 'message' => 'Failed to send AR notice email: ' . (string)$mail->ErrorInfo];
    }
}

function sendOrderOnTheWayEmail(string $toEmail, string $toName, int $orderId, int $deliveryId, float $totalAmount = 0): array
{
    $setup = createConfiguredMailer();
    if (!$setup['ok']) return $setup;
    $mail = $setup['mail'];

    try {
        $mail->addAddress($toEmail, $toName !== '' ? $toName : $toEmail);
        $mail->Subject = 'Your order is now on the way - VIP Ice Plant';
        $safeName = htmlspecialchars($toName ?: 'Customer', ENT_QUOTES, 'UTF-8');

        $mail->Body = '<p>Hello ' . $safeName . ',</p>'
            . '<p>Good news! Your order is now on the way for delivery.</p>'
            . '<p><strong>Order ID:</strong> #' . (int)$orderId . '<br>'
            . '<strong>Delivery ID:</strong> #' . (int)$deliveryId . '<br>'
            . '<strong>Total Amount:</strong> PHP ' . number_format($totalAmount, 2) . '</p>'
            . '<p>Please keep your phone available and prepare for receiving your order.</p>'
            . '<p>Thank you for choosing VIP Ice Plant.</p>';

        $mail->AltBody = "Hello " . ($toName ?: 'Customer')
            . ",\n\nGood news! Your order is now on the way for delivery."
            . "\nOrder ID: #" . (int)$orderId
            . "\nDelivery ID: #" . (int)$deliveryId
            . "\nTotal Amount: PHP " . number_format($totalAmount, 2)
            . "\n\nPlease keep your phone available and prepare for receiving your order."
            . "\nThank you for choosing VIP Ice Plant.";

        $mail->send();
        return ['ok' => true, 'message' => 'On-the-way email sent successfully.'];
    } catch (Exception $e) {
        return ['ok' => false, 'message' => 'Failed to send on-the-way email: ' . (string)$mail->ErrorInfo];
    }
}

/**
 * Send official receipt to the customer via email when their sale is recorded.
 */
function sendDeliverySaleReceiptEmail(string $toEmail, string $toName, array $saleDetails, array $items): array
{
    $setup = createConfiguredMailer();
    if (!$setup['ok']) return $setup;
    $mail = $setup['mail'];

    try {
        $mail->addAddress($toEmail, $toName !== '' ? $toName : $toEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Official Receipt - VIP Ice Plant';

        $logoPath = realpath(__DIR__ . '/../assets/img/VIP-LOGS - Copy.jpg');
        $hasLogo = ($logoPath && file_exists($logoPath));
        if ($hasLogo) {
            $mail->addEmbeddedImage($logoPath, 'logo');
        }

        $saleId = (int)$saleDetails['sale_id'];
        $saleDate = htmlspecialchars($saleDetails['created_at'] ?? date('Y-m-d H:i'));
        $paymentType = htmlspecialchars($saleDetails['payment_type'] ?? 'Cash');
        $grossTotal = floatval($saleDetails['gross_total'] ?? 0);
        $discount = floatval($saleDetails['discount'] ?? 0);
        $totalAmount = floatval($saleDetails['total_amount'] ?? 0);
        $cashReceived = floatval($saleDetails['cash_received'] ?? 0);
        $changeGiven = floatval($saleDetails['change_given'] ?? 0);
        $arBalance = floatval($saleDetails['ar_balance'] ?? 0);
        
        // Build items table
        $itemsHtml = '';
        foreach ($items as $it) {
            $name = htmlspecialchars($it['product_name']);
            $qty = number_format($it['quantity'], 2);
            if (floor($it['quantity']) == $it['quantity']) {
                $qty = number_format($it['quantity'], 0);
            }
            $price = number_format($it['unit_price'], 2);
            $subtotal = number_format($it['subtotal'], 2);
            $itemsHtml .= "
                <tr>
                    <td style='padding: 10px 0; border-bottom: 1px solid #f1f5f9; color: #334155; font-size: 14px;'>{$name}</td>
                    <td style='padding: 10px 0; border-bottom: 1px solid #f1f5f9; color: #475569; font-size: 14px; text-align: center;'>{$qty}</td>
                    <td style='padding: 10px 0; border-bottom: 1px solid #f1f5f9; color: #475569; font-size: 14px; text-align: right;'>₱{$price}</td>
                    <td style='padding: 10px 0; border-bottom: 1px solid #f1f5f9; color: #1e293b; font-size: 14px; font-weight: 600; text-align: right;'>₱{$subtotal}</td>
                </tr>
            ";
        }

        // Render AR section if any
        $arHtml = '';
        if ($arBalance > 0) {
            $arHtml = "
                <tr>
                    <td colspan='3' style='padding: 6px 0; color: #b45309; font-size: 14px; font-weight: 600; text-align: right;'>AR Balance Due:</td>
                    <td style='padding: 6px 0; color: #b45309; font-size: 14px; font-weight: bold; text-align: right;'>₱" . number_format($arBalance, 2) . "</td>
                </tr>
            ";
        }

        $mail->Body = '
<div style="background-color: #f8fafc; padding: 40px 10px; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
    <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 600px; background-color: #ffffff; border-radius: 16px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);">
        <!-- Header -->
        <tr>
            <td style="padding: 30px 40px; border-bottom: 2px solid #f1f5f9; background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); border-top-left-radius: 16px; border-top-right-radius: 16px;">
                <table border="0" cellpadding="0" cellspacing="0" width="100%">
                    <tr>
                        <td>
                            ' . ($hasLogo ? '<img src="cid:logo" alt="VIP Ice Plant" width="100" style="display: block; border: 0;" />' : '<h2 style="margin: 0; color: #ffffff;">VIP ICE PLANT</h2>') . '
                        </td>
                        <td style="text-align: right;">
                            <span style="color: #38bdf8; font-weight: 700; font-size: 12px; text-transform: uppercase; letter-spacing: 1px;">Official Receipt</span>
                            <h3 style="margin: 5px 0 0 0; color: #ffffff; font-size: 18px;">Receipt #' . $saleId . '</h3>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        
        <!-- Customer & Info Details -->
        <tr>
            <td style="padding: 30px 40px 10px 40px;">
                <table border="0" cellpadding="0" cellspacing="0" width="100%">
                    <tr>
                        <td width="50%" style="vertical-align: top;">
                            <span style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 600;">Delivered To</span>
                            <p style="margin: 4px 0 0 0; font-size: 15px; color: #1e293b; font-weight: 700;">' . htmlspecialchars($toName, ENT_QUOTES, 'UTF-8') . '</p>
                            <p style="margin: 2px 0 0 0; font-size: 13px; color: #64748b;">' . htmlspecialchars($toEmail, ENT_QUOTES, 'UTF-8') . '</p>
                        </td>
                        <td width="50%" style="text-align: right; vertical-align: top;">
                            <span style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 600;">Transaction Info</span>
                            <p style="margin: 4px 0 0 0; font-size: 13px; color: #475569;"><strong>Date:</strong> ' . $saleDate . '</p>
                            <p style="margin: 2px 0 0 0; font-size: 13px; color: #475569;"><strong>Payment:</strong> ' . $paymentType . '</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        
        <!-- Table Items -->
        <tr>
            <td style="padding: 20px 40px 10px 40px;">
                <table border="0" cellpadding="0" cellspacing="0" width="100%">
                    <thead>
                        <tr>
                            <th align="left" style="padding-bottom: 8px; border-bottom: 2px solid #e2e8f0; color: #475569; font-size: 12px; font-weight: 700; text-transform: uppercase;">Product / Item</th>
                            <th align="center" style="padding-bottom: 8px; border-bottom: 2px solid #e2e8f0; color: #475569; font-size: 12px; font-weight: 700; text-transform: uppercase; width: 60px;">Qty</th>
                            <th align="right" style="padding-bottom: 8px; border-bottom: 2px solid #e2e8f0; color: #475569; font-size: 12px; font-weight: 700; text-transform: uppercase; width: 90px;">Unit Price</th>
                            <th align="right" style="padding-bottom: 8px; border-bottom: 2px solid #e2e8f0; color: #475569; font-size: 12px; font-weight: 700; text-transform: uppercase; width: 100px;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        ' . $itemsHtml . '
                    </tbody>
                </table>
            </td>
        </tr>
        
        <!-- Summary Calculations -->
        <tr>
            <td style="padding: 10px 40px 30px 40px;">
                <table border="0" cellpadding="0" cellspacing="0" width="100%">
                    <tr>
                        <td width="60%"></td>
                        <td width="40%">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td style="padding: 6px 0; color: #64748b; font-size: 14px; text-align: right;">Subtotal:</td>
                                    <td style="padding: 6px 0; color: #1e293b; font-size: 14px; font-weight: 600; text-align: right;">₱' . number_format($grossTotal, 2) . '</td>
                                </tr>
                                ' . ($discount > 0 ? '
                                <tr>
                                    <td style="padding: 6px 0; color: #ef4444; font-size: 14px; text-align: right;">Discount:</td>
                                    <td style="padding: 6px 0; color: #ef4444; font-size: 14px; font-weight: 600; text-align: right;">-₱' . number_format($discount, 2) . '</td>
                                </tr>' : '') . '
                                <tr>
                                    <td style="padding: 8px 0; color: #1e293b; font-size: 15px; font-weight: 700; text-align: right; border-top: 1px solid #e2e8f0;">Total Sale:</td>
                                    <td style="padding: 8px 0; color: #0f172a; font-size: 16px; font-weight: 800; text-align: right; border-top: 1px solid #e2e8f0;">₱' . number_format($totalAmount, 2) . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 6px 0; color: #64748b; font-size: 13px; text-align: right;">Amount Paid:</td>
                                    <td style="padding: 6px 0; color: #475569; font-size: 13px; text-align: right;">₱' . number_format($cashReceived, 2) . '</td>
                                </tr>
                                <tr>
                                    <td style="padding: 6px 0; color: #64748b; font-size: 13px; text-align: right;">Change:</td>
                                    <td style="padding: 6px 0; color: #475569; font-size: 13px; text-align: right;">₱' . number_format($changeGiven, 2) . '</td>
                                </tr>
                                ' . $arHtml . '
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        
        <!-- Footer / Closing -->
        <tr>
            <td style="padding: 30px 40px; background-color: #f8fafc; border-bottom-left-radius: 16px; border-bottom-right-radius: 16px; border-top: 1px solid #e2e8f0; text-align: center;">
                <p style="margin: 0; font-size: 14px; color: #475569; font-weight: 600;">Thank you for your business!</p>
                <p style="margin: 4px 0 0 0; font-size: 12px; color: #94a3b8;">If you have any questions about this receipt, please contact VIP Ice Plant support.</p>
            </td>
        </tr>
    </table>
</div>';

        $altBody = "OFFICIAL RECEIPT - VIP ICE PLANT\n";
        $altBody .= "Receipt ID: #" . $saleId . "\n";
        $altBody .= "Date: " . $saleDate . "\n";
        $altBody .= "Delivered To: " . $toName . " (" . $toEmail . ")\n\n";
        $altBody .= "ITEMS SOLD:\n";
        foreach ($items as $it) {
            $altBody .= "- " . $it['product_name'] . " x " . $it['quantity'] . " @ ₱" . number_format($it['unit_price'], 2) . " (Subtotal: ₱" . number_format($it['subtotal'], 2) . ")\n";
        }
        $altBody .= "\nSubtotal: ₱" . number_format($grossTotal, 2) . "\n";
        if ($discount > 0) {
            $altBody .= "Discount: -₱" . number_format($discount, 2) . "\n";
        }
        $altBody .= "Total: ₱" . number_format($totalAmount, 2) . "\n";
        $altBody .= "Cash Received: ₱" . number_format($cashReceived, 2) . "\n";
        $altBody .= "Change: ₱" . number_format($changeGiven, 2) . "\n";
        if ($arBalance > 0) {
            $altBody .= "AR Balance: ₱" . number_format($arBalance, 2) . "\n";
        }
        $altBody .= "\nThank you for your business!";
        
        $mail->AltBody = $altBody;

        $mail->send();
        return ['ok' => true, 'message' => 'Receipt email sent successfully.'];
    } catch (Exception $e) {
        return ['ok' => false, 'message' => 'Failed to send receipt email: ' . (string)$mail->ErrorInfo];
    }
}
