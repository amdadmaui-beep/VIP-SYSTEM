<?php
/**
 * Secure Configuration Loader
 * Loads environment variables from .env file
 * Provides centralized configuration management
 * 
 * Location: capstone/includes/config.php
 * Created: Security Fix - Critical Level
 */

if (!function_exists('loadEnvFile')) {
    function loadEnvFile(string $path): void {
        if (!file_exists($path)) {
            throw new Exception("Environment file not found: {$path}");
        }
        
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Parse KEY=VALUE pairs
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove quotes if present
                if ((strpos($value, '"') === 0 && strrpos($value, '"') === strlen($value) - 1) ||
                    (strpos($value, "'") === 0 && strrpos($value, "'") === strlen($value) - 1)) {
                    $value = substr($value, 1, -1);
                }
                
                // Set environment variable
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }
}

if (!function_exists('env')) {
    function env(string $key, $default = null) {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        
        // Convert boolean strings
        $lowercase = strtolower($value);
        if ($lowercase === 'true') return true;
        if ($lowercase === 'false') return false;
        if ($lowercase === 'null') return null;
        
        return $value;
    }
}

// Load environment variables
$envPath = __DIR__ . '/../.env';
try {
    loadEnvFile($envPath);
} catch (Exception $e) {
    // Fallback for environments without .env
    error_log("Config warning: " . $e->getMessage());
}

// Define configuration constants with secure defaults
if (!defined('DB_HOST')) define('DB_HOST', env('DB_HOST', 'localhost'));
if (!defined('DB_USER')) define('DB_USER', env('DB_USER', 'root'));
if (!defined('DB_PASS')) define('DB_PASS', env('DB_PASS', ''));
if (!defined('DB_NAME')) define('DB_NAME', env('DB_NAME', 'vip_db'));
if (!defined('DB_CHARSET')) define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

if (!defined('APP_ENV')) define('APP_ENV', env('APP_ENV', 'production'));
if (!defined('APP_DEBUG')) define('APP_DEBUG', env('APP_DEBUG', false));
if (!defined('APP_URL')) define('APP_URL', env('APP_URL', 'http://localhost'));

if (!defined('CSRF_TOKEN_LIFETIME')) define('CSRF_TOKEN_LIFETIME', (int)env('CSRF_TOKEN_LIFETIME', 3600));
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', (int)env('SESSION_LIFETIME', 7200));

// Credit Limit Settings - Disabled by default (set to true in .env to enable)
if (!defined('CREDIT_LIMIT_ENABLED')) define('CREDIT_LIMIT_ENABLED', env('CREDIT_LIMIT_ENABLED', false));
if (!defined('CREDIT_LIMIT_WARNING_THRESHOLD')) define('CREDIT_LIMIT_WARNING_THRESHOLD', (float)env('CREDIT_LIMIT_WARNING_THRESHOLD', 0.9));

// Encryption key for sensitive data
if (!defined('ENCRYPTION_KEY')) {
    $ek = env('ENCRYPTION_KEY', '');
    if ($ek === '' || $ek === 'DefaultKeyChangeThis!' || $ek === 'ChangeThisTo32CharacterKey!') {
        error_log("FATAL: ENCRYPTION_KEY not set or still default. Set a secure 32+ char key in .env.");
        http_response_code(500);
        die('System configuration error. Contact administrator.');
    }
    define('ENCRYPTION_KEY', $ek);
    unset($ek);
}

if (APP_ENV === 'production' && APP_DEBUG === true) {
    error_log("SECURITY WARNING: Debug mode enabled in production environment");
}

// Maintenance mode check
$maintenanceFlag = __DIR__ . '/../maintenance.flag';
if (file_exists($maintenanceFlag)) {
    $isApi = (strpos(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')), '/api/') !== false);
    if ($isApi) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        die(json_encode(['success' => false, 'error' => 'System is under maintenance. Please try again later.']));
    }
    http_response_code(503);
    $maintenanceMsg = trim(file_get_contents($maintenanceFlag));
    if (empty($maintenanceMsg)) {
        $maintenanceMsg = 'The system is currently under maintenance. Please check back shortly.';
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Maintenance</title>
    <style>body{font-family:Arial,sans-serif;background:#f1f5f9;color:#0f172a;margin:0;padding:24px;display:flex;min-height:100vh;align-items:center;justify-content:center}
    .card{max-width:420px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px;text-align:center;box-shadow:0 10px 30px rgba(15,23,42,0.08)}
    .icon{width:72px;height:72px;margin:0 auto 12px;border-radius:20px;background:#fffbeb;display:flex;align-items:center;justify-content:center;font-size:2rem;color:#d97706}
    .title{font-size:1.1rem;font-weight:800;margin-bottom:8px}
    .text{color:#475569;font-size:.92rem;line-height:1.45}
    </style></head>
    <body>
    <div class="card">
        <div class="icon">&#9888;</div>
        <div class="title">Under Maintenance</div>
        <div class="text"><?php echo htmlspecialchars($maintenanceMsg); ?></div>
    </div>
    </body>
    </html>
    <?php
    exit;
}
