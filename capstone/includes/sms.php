<?php
declare(strict_types=1);

$smsConfig = [];
$smsConfigPath = __DIR__ . '/sms_config.php';
if (file_exists($smsConfigPath)) {
    $tmpSmsConfig = require $smsConfigPath;
    if (is_array($tmpSmsConfig)) {
        $smsConfig = $tmpSmsConfig;
    }
}

/**
 * SMS helper.
 *
 * Supported providers:
 * - twilio
 * - unisms
 * - semaphore (recommended for Philippines)
 * - textbelt (fallback; free key blocked in some countries)
 *
 * Optional env vars:
 * - SMS_PROVIDER=twilio|unisms|semaphore|textbelt
 * - SMS_TWILIO_SID=ACxxxxxxxx
 * - SMS_TWILIO_TOKEN=xxxxxxxx
 * - SMS_TWILIO_FROM=+1xxxxxxxxxx (Twilio number)
 * - SMS_UNISMS_API_KEY=your_unisms_secret_key
 * - SMS_SEMAPHORE_API_KEY=your_semaphore_api_key
 * - SMS_SEMAPHORE_SENDER=VIPICE (optional approved sender name)
 * - SMS_TEXTBELT_KEY=textbelt
 */

function normalizePhoneForSms(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    $digits = preg_replace('/\D+/', '', $raw);
    if (!is_string($digits) || $digits === '') {
        return '';
    }

    // Philippines normalization:
    // 09XXXXXXXXX => +639XXXXXXXXX
    // 639XXXXXXXXX => +639XXXXXXXXX
    if (strlen($digits) === 11 && substr($digits, 0, 2) === '09') {
        return '+63' . substr($digits, 1);
    }
    if (strlen($digits) === 12 && substr($digits, 0, 3) === '639') {
        return '+' . $digits;
    }

    // Fallback: if already includes country-style leading digits, prepend +
    if (strlen($digits) >= 10) {
        return '+' . $digits;
    }

    return '';
}

function getSmsBusinessName(): string
{
    global $smsConfig;
    $businessName = trim((string)(getenv('SMS_BUSINESS_NAME') ?: (string)($smsConfig['business_name'] ?? '')));
    return $businessName !== '' ? $businessName : 'VIP Ice Plant';
}

function formatSmsDate(string $rawDate): string
{
    $timestamp = strtotime($rawDate);
    if ($timestamp === false) {
        return trim($rawDate);
    }

    return date('M j, Y', $timestamp);
}

function sendViaTextbelt(string $toPhone, string $message): array
{
    $key = getenv('SMS_TEXTBELT_KEY');
    if (!is_string($key) || trim($key) === '') {
        $key = 'textbelt'; // free key
    }

    $payload = http_build_query([
        'phone' => $toPhone,
        'message' => $message,
        'key' => trim($key),
    ]);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 20,
        ],
    ]);

    $raw = @file_get_contents('https://textbelt.com/text', false, $context);
    if ($raw === false) {
        return ['ok' => false, 'message' => 'SMS API request failed (network/API unreachable).'];
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return ['ok' => false, 'message' => 'SMS API returned invalid response.'];
    }

    if (!empty($json['success'])) {
        return [
            'ok' => true,
            'message' => 'SMS sent successfully.',
            'provider' => 'textbelt',
            'quotaRemaining' => $json['quotaRemaining'] ?? null,
            'textId' => $json['textId'] ?? null,
        ];
    }

    return [
        'ok' => false,
        'message' => 'Failed to send SMS: ' . (string)($json['error'] ?? 'Unknown SMS API error'),
        'provider' => 'textbelt',
    ];
}

function phoneForSemaphore(string $normalized): string
{
    // Convert +639XXXXXXXXX -> 09XXXXXXXXX
    if (substr($normalized, 0, 3) === '+63' && strlen($normalized) === 13) {
        return '0' . substr($normalized, 3);
    }
    if (substr($normalized, 0, 2) === '63' && strlen($normalized) === 12) {
        return '0' . substr($normalized, 2);
    }
    return $normalized;
}

function sendViaSemaphore(string $toPhoneNormalized, string $message): array
{
    global $smsConfig;
    $apiKey = trim((string)(getenv('SMS_SEMAPHORE_API_KEY') ?: (string)($smsConfig['semaphore_api_key'] ?? '')));
    if ($apiKey === '') {
        return ['ok' => false, 'message' => 'Semaphore API key is missing. Set SMS_SEMAPHORE_API_KEY.'];
    }

    $number = phoneForSemaphore($toPhoneNormalized);
    $sender = trim((string)(getenv('SMS_SEMAPHORE_SENDER') ?: (string)($smsConfig['semaphore_sender'] ?? '')));

    $postFields = [
        'apikey' => $apiKey,
        'number' => $number,
        'message' => $message,
    ];
    if ($sender !== '') {
        $postFields['sendername'] = $sender;
    }

    $payload = http_build_query($postFields);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: VIP-System-SMS/1.0\r\n",
            'content' => $payload,
            'timeout' => 20,
            'ignore_errors' => true, // capture response body even on 4xx/5xx
        ],
    ]);

    $raw = @file_get_contents('https://api.semaphore.co/api/v4/messages', false, $context);
    if ($raw === false && empty($http_response_header)) {
        return ['ok' => false, 'message' => 'Semaphore request failed (network/API unreachable).'];
    }

    $statusCode = 0;
    if (!empty($http_response_header) && preg_match('/\s(\d{3})\s/', (string)$http_response_header[0], $m)) {
        $statusCode = (int)$m[1];
    }

    if ($statusCode === 403) {
        $bodySnippet = is_string($raw) ? trim($raw) : '';
        if ($bodySnippet === '') $bodySnippet = 'Forbidden (check API key/account/sender settings).';
        return ['ok' => false, 'message' => 'Semaphore 403: ' . $bodySnippet, 'provider' => 'semaphore'];
    }

    $json = json_decode($raw, true);
    if (!is_array($json) || !isset($json[0]) || !is_array($json[0])) {
        return ['ok' => false, 'message' => 'Semaphore returned an invalid response.'];
    }

    $row = $json[0];
    $status = strtolower((string)($row['status'] ?? ''));
    $success = in_array($status, ['pending', 'queued', 'sending', 'sent'], true);
    if ($success) {
        return [
            'ok' => true,
            'message' => 'SMS queued successfully.',
            'provider' => 'semaphore',
            'message_id' => $row['message_id'] ?? null,
            'status' => $row['status'] ?? null,
        ];
    }

    $errorText = (string)($row['status'] ?? 'Unknown error');
    return ['ok' => false, 'message' => 'Failed to send SMS (Semaphore): ' . $errorText, 'provider' => 'semaphore'];
}

function sendViaUniSms(string $toPhoneNormalized, string $message): array
{
    global $smsConfig;
    $apiKey = trim((string)(getenv('SMS_UNISMS_API_KEY') ?: (string)($smsConfig['unisms_api_key'] ?? '')));
    if ($apiKey === '') {
        return ['ok' => false, 'message' => 'UniSMS API key is missing. Set SMS_UNISMS_API_KEY.'];
    }

    $payload = json_encode([
        'recipient' => $toPhoneNormalized,
        'content' => $message,
    ]);

    if ($payload === false) {
        return ['ok' => false, 'message' => 'Unable to encode SMS payload for UniSMS.'];
    }

    $auth = base64_encode($apiKey . ':');
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Authorization: Basic {$auth}\r\n"
                . "Content-Type: application/json\r\n"
                . "Accept: application/json\r\n"
                . "User-Agent: VIP-System-SMS/1.0\r\n",
            'content' => $payload,
            'timeout' => 25,
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents('https://unismsapi.com/api/sms', false, $context);
    if ($raw === false && empty($http_response_header)) {
        return ['ok' => false, 'message' => 'UniSMS request failed (network/API unreachable).'];
    }

    $statusCode = 0;
    if (!empty($http_response_header) && preg_match('/\s(\d{3})\s/', (string)$http_response_header[0], $m)) {
        $statusCode = (int)$m[1];
    }

    $responseData = json_decode((string)$raw, true);
    if ($statusCode >= 200 && $statusCode < 300) {
        return [
            'ok' => true,
            'message' => 'SMS queued successfully.',
            'provider' => 'unisms',
            'status' => is_array($responseData) ? ($responseData['status'] ?? null) : null,
            'response' => is_array($responseData) ? $responseData : trim((string)$raw),
        ];
    }

    $errorText = trim((string)$raw);
    if (is_array($responseData)) {
        $errorText = (string)($responseData['message'] ?? $responseData['error'] ?? $responseData['detail'] ?? $errorText);
    }
    if ($errorText === '') {
        $errorText = 'Unknown UniSMS error';
    }

    return [
        'ok' => false,
        'message' => 'Failed to send SMS (UniSMS): HTTP ' . $statusCode . ' - ' . $errorText,
        'provider' => 'unisms',
    ];
}

function sendViaTwilio(string $toPhoneNormalized, string $message): array
{
    global $smsConfig;
    $sid = trim((string)(getenv('SMS_TWILIO_SID') ?: (string)($smsConfig['twilio_sid'] ?? '')));
    $token = trim((string)(getenv('SMS_TWILIO_TOKEN') ?: (string)($smsConfig['twilio_token'] ?? '')));
    $from = trim((string)(getenv('SMS_TWILIO_FROM') ?: (string)($smsConfig['twilio_from'] ?? '')));

    if ($sid === '' || $token === '' || $from === '') {
        return ['ok' => false, 'message' => 'Twilio config missing. Set SMS_TWILIO_SID, SMS_TWILIO_TOKEN, SMS_TWILIO_FROM.'];
    }

    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json';
    $payload = http_build_query([
        'To' => $toPhoneNormalized,
        'From' => $from,
        'Body' => $message,
    ]);

    $auth = base64_encode($sid . ':' . $token);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Authorization: Basic {$auth}\r\n"
                . "Content-Type: application/x-www-form-urlencoded\r\n"
                . "User-Agent: VIP-System-SMS/1.0\r\n",
            'content' => $payload,
            'timeout' => 25,
            'ignore_errors' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    if ($raw === false && empty($http_response_header)) {
        return ['ok' => false, 'message' => 'Twilio request failed (network/API unreachable).'];
    }

    $statusCode = 0;
    if (!empty($http_response_header) && preg_match('/\s(\d{3})\s/', (string)$http_response_header[0], $m)) {
        $statusCode = (int)$m[1];
    }

    $json = json_decode((string)$raw, true);
    if ($statusCode >= 200 && $statusCode < 300 && is_array($json) && !empty($json['sid'])) {
        return [
            'ok' => true,
            'message' => 'SMS queued successfully.',
            'provider' => 'twilio',
            'message_id' => $json['sid'],
            'status' => $json['status'] ?? null,
        ];
    }

    $twilioErr = is_array($json) ? ((string)($json['message'] ?? $json['error_message'] ?? 'Unknown Twilio error')) : trim((string)$raw);
    return [
        'ok' => false,
        'message' => 'Failed to send SMS (Twilio): HTTP ' . $statusCode . ' - ' . $twilioErr,
        'provider' => 'twilio',
    ];
}

function sendOrderOnTheWaySms(string $phoneRaw, string $customerName, int $orderId, int $deliveryId, float $totalAmount = 0): array
{
    $toPhone = normalizePhoneForSms($phoneRaw);
    if ($toPhone === '') {
        return ['ok' => false, 'message' => 'Customer phone number is invalid or missing.'];
    }

    global $smsConfig;
    $provider = strtolower(trim((string)(getenv('SMS_PROVIDER') ?: (string)($smsConfig['provider'] ?? 'twilio'))));
    $name = trim($customerName) !== '' ? trim($customerName) : 'Customer';
    $businessName = getSmsBusinessName();
    $message = "Good day {$name}.\n\n"
        . "{$businessName}: your order is out for delivery.\n\n"
        . "Order Summary:\n"
        . "Order #: {$orderId}\n"
        . "Delivery #: {$deliveryId}\n"
        . "Total Amount: PHP " . number_format($totalAmount, 2) . "\n\n"
        . "Thank you.";

    if ($provider === 'twilio') {
        return sendViaTwilio($toPhone, $message);
    }
    if ($provider === 'unisms') {
        return sendViaUniSms($toPhone, $message);
    }
    if ($provider === 'semaphore') {
        return sendViaSemaphore($toPhone, $message);
    }
    if ($provider === 'textbelt') {
        return sendViaTextbelt($toPhone, $message);
    }

    return ['ok' => false, 'message' => 'Unsupported SMS provider. Use SMS_PROVIDER=twilio, unisms, semaphore, or textbelt.'];
}

function sendSmsUsingConfiguredProvider(string $phoneRaw, string $message): array
{
    $toPhone = normalizePhoneForSms($phoneRaw);
    if ($toPhone === '') {
        return ['ok' => false, 'message' => 'Customer phone number is invalid or missing.'];
    }

    global $smsConfig;
    $provider = strtolower(trim((string)(getenv('SMS_PROVIDER') ?: (string)($smsConfig['provider'] ?? 'twilio'))));

    if ($provider === 'twilio') {
        return sendViaTwilio($toPhone, $message);
    }
    if ($provider === 'unisms') {
        return sendViaUniSms($toPhone, $message);
    }
    if ($provider === 'semaphore') {
        return sendViaSemaphore($toPhone, $message);
    }
    if ($provider === 'textbelt') {
        return sendViaTextbelt($toPhone, $message);
    }

    return ['ok' => false, 'message' => 'Unsupported SMS provider. Use SMS_PROVIDER=twilio, unisms, semaphore, or textbelt.'];
}

function sendARBalanceReminderSms(string $phoneRaw, string $customerName, int $arId, float $amountDue, string $dueDate): array
{
    $name = trim($customerName) !== '' ? trim($customerName) : 'Customer';
    $businessName = getSmsBusinessName();
    $message = "Good day {$name}.\n\n"
        . "{$businessName} balance reminder.\n"
        . "Reference: AR-{$arId}\n"
        . "Amount Due: PHP " . number_format($amountDue, 2) . "\n"
        . "Due Date: " . formatSmsDate($dueDate) . "\n\n"
        . "If payment has already been made, please disregard this message. Thank you.";

    return sendSmsUsingConfiguredProvider($phoneRaw, $message);
}

