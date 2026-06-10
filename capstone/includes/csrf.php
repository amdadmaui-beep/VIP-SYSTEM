<?php
/**
 * CSRF Protection Utility
 * Provides token generation and validation for state-changing operations
 * 
 * Location: capstone/includes/csrf.php
 * Created: Security Fix - Critical Level
 * 
 * Usage:
 *   1. Include at the start of forms: <?php echo csrfTokenField(); ?>
 *   2. Validate on POST: validateCsrfToken() or requireCsrfToken()
 */

// Do not start the session here — auth.php configures cookie params first.
// csrf.php is safe to include after auth/session bootstrap.

if (!defined('CSRF_GRACE_WINDOW')) {
    define('CSRF_GRACE_WINDOW', 600); // 10 minutes
}

/**
 * Rotate CSRF token while keeping a short grace window for stale tabs.
 * This avoids hard failures when a user keeps a page open for a long time.
 */
if (!function_exists('rotateCsrfToken')) {
    function rotateCsrfToken(): string {
        $current_token = $_SESSION['csrf_token'] ?? '';
        $current_time = (int)($_SESSION['csrf_token_time'] ?? 0);

        if (!empty($current_token) && $current_time > 0) {
            $_SESSION['csrf_prev_token'] = $current_token;
            $_SESSION['csrf_prev_token_time'] = $current_time;
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['csrf_token_time'] = time();

        return $_SESSION['csrf_token'];
    }
}

/**
 * Generate a cryptographically secure CSRF token
 * @return string The generated token
 */
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken(): string {
        $lifetime = defined('CSRF_TOKEN_LIFETIME') ? CSRF_TOKEN_LIFETIME : 3600;
        $current_token = $_SESSION['csrf_token'] ?? '';
        $current_time = (int)($_SESSION['csrf_token_time'] ?? 0);

        if (empty($current_token) || $current_time <= 0) {
            return rotateCsrfToken();
        }

        // Sliding token lifecycle: if expired, rotate and keep previous token briefly valid.
        if ((time() - $current_time) > $lifetime) {
            return rotateCsrfToken();
        }

        return $current_token;
    }
}

/**
 * Get current CSRF token (generates if doesn't exist)
 * @return string The CSRF token
 */
if (!function_exists('getCsrfToken')) {
    function getCsrfToken(): string {
        return generateCsrfToken();
    }
}

/**
 * Generate HTML hidden input field with CSRF token
 * @return string HTML input element
 */
if (!function_exists('csrfTokenField')) {
    function csrfTokenField(): string {
        $token = getCsrfToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}

/**
 * Meta tag + window.csrfToken bootstrap for pages and fetch/AJAX callers.
 */
if (!function_exists('csrfBootstrapTags')) {
    function csrfBootstrapTags(): string {
        $token = getCsrfToken();
        $safe = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
        $json = json_encode($token, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        return '<meta name="csrf-token" content="' . $safe . '">' . "\n"
            . '<script>window.csrfToken=' . $json . ';</script>';
    }
}

/**
 * Validate CSRF token from request
 * @param bool $regenerate Whether to regenerate token after validation
 * @return bool True if valid, false otherwise
 */
if (!function_exists('validateCsrfToken')) {
    function validateCsrfToken(bool $regenerate = false): bool {
        // Skip validation for GET requests (they should be idempotent)
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            return true;
        }

        $token = '';
        if (!empty($_POST['csrf_token'])) {
            $token = (string)$_POST['csrf_token'];
        } elseif (!empty($_POST['_token'])) {
            $token = (string)$_POST['_token'];
        } elseif (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            $token = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
        } elseif (!empty($_SERVER['HTTP_X_XSRF_TOKEN'])) {
            $token = (string)$_SERVER['HTTP_X_XSRF_TOKEN'];
        }

        // Support JSON payloads where csrf_token is passed in body.
        if ($token === '') {
            $content_type = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
            if (strpos($content_type, 'application/json') !== false) {
                $raw_body = file_get_contents('php://input');
                if (!empty($raw_body)) {
                    $json = json_decode($raw_body, true);
                    if (is_array($json)) {
                        $token = (string)($json['csrf_token'] ?? $json['_token'] ?? '');
                    }
                }
            }
        }

        $stored_token = (string)($_SESSION['csrf_token'] ?? '');
        $token_time = (int)($_SESSION['csrf_token_time'] ?? 0);
        $prev_token = (string)($_SESSION['csrf_prev_token'] ?? '');
        $prev_token_time = (int)($_SESSION['csrf_prev_token_time'] ?? 0);
        $now = time();
        $lifetime = defined('CSRF_TOKEN_LIFETIME') ? CSRF_TOKEN_LIFETIME : 3600;
        $grace = defined('CSRF_GRACE_WINDOW') ? CSRF_GRACE_WINDOW : 600;

        // Check if token exists
        if (empty($token) || (empty($stored_token) && empty($prev_token))) {
            error_log("CSRF validation failed: Missing token");
            return false;
        }

        $is_current_match = (!empty($stored_token) && hash_equals($stored_token, $token));
        $is_prev_match = (!empty($prev_token) && hash_equals($prev_token, $token));
        $valid = false;

        // Repair legacy sessions that have a token but no timestamp.
        if ($is_current_match && $token_time <= 0) {
            $_SESSION['csrf_token_time'] = $now;
            $token_time = $now;
        }

        if ($is_current_match && $token_time > 0 && ($now - $token_time) <= $lifetime) {
            $valid = true;
        } elseif ($is_prev_match && $prev_token_time > 0 && ($now - $prev_token_time) <= ($lifetime + $grace)) {
            // Accept recently-rotated token from stale tab and refresh.
            $valid = true;
            rotateCsrfToken();
        } elseif ($is_current_match && $token_time > 0 && ($now - $token_time) <= ($lifetime + $grace)) {
            // Soft grace for current token, then refresh to recover seamlessly.
            $valid = true;
            rotateCsrfToken();
        }

        if (!$valid) {
            error_log("CSRF validation failed: Token mismatch or expired");
            return false;
        }

        // Regenerate token if requested (prevents replay attacks)
        if ($regenerate) {
            rotateCsrfToken();
        }

        return true;
    }
}

/**
 * Require CSRF token validation - dies if invalid
 * @param bool $api_mode Whether this is an API request (returns JSON on failure)
 * @param bool $regenerate Whether to regenerate token after validation
 * @return void
 */
if (!function_exists('requireCsrfToken')) {
    function requireCsrfToken(bool $api_mode = true, bool $regenerate = false): void {
        if (!validateCsrfToken($regenerate)) {
            if ($api_mode) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid or expired security token. Please refresh the page and try again.'
                ]);
            } else {
                http_response_code(403);
                die('Invalid or expired security token. Please refresh the page and try again.');
            }
            exit;
        }
    }
}

/**
 * Auto-validate CSRF for API endpoints
 * Call this at the start of API files that handle POST requests
 * @return void
 */
if (!function_exists('autoValidateCsrf')) {
    function autoValidateCsrf(): void {
        // Only validate for POST/PUT/DELETE requests
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'PUT' && $_SERVER['REQUEST_METHOD'] !== 'DELETE') {
            return;
        }
        
        requireCsrfToken(true, false);
    }
}
