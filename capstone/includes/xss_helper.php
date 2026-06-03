<?php
/**
 * XSS Protection Helper Functions
 * Location: capstone/includes/xss_helper.php
 * 
 * Provides standardized XSS protection across the application.
 * All output functions should use these helpers to prevent XSS attacks.
 */

if (!function_exists('e')) {
    /**
     * Shortcut for htmlspecialchars() with default flags
     * Usage: echo e($userInput);
     * 
     * @param string $text Text to escape
     * @param int $flags HTMLSpecialChars flags (default: ENT_QUOTES | ENT_HTML5)
     * @return string Escaped text
     */
    function e($text, $flags = ENT_QUOTES | ENT_HTML5) {
        return htmlspecialchars((string)$text, $flags, 'UTF-8');
    }
}

if (!function_exists('ee')) {
    /**
     * Echo escaped text (combines e() and echo)
     * Usage: ee($userInput);
     * 
     * @param string $text Text to escape and echo
     * @param int $flags HTMLSpecialChars flags
     */
    function ee($text, $flags = ENT_QUOTES | ENT_HTML5) {
        echo e($text, $flags);
    }
}

if (!function_exists('js')) {
    /**
     * Escape text for use in JavaScript contexts
     * Usage: var name = '<?php echo js($name); ?>';
     * 
     * @param string $text Text to escape for JS
     * @return string JS-escaped text
     */
    function js($text) {
        return json_encode((string)$text, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    }
}

if (!function_exists('attr')) {
    /**
     * Escape text for use in HTML attributes
     * Usage: <input value="<?php echo attr($value); ?>">
     * 
     * @param string $text Text to escape for attribute
     * @return string Attribute-escaped text
     */
    function attr($text) {
        return htmlspecialchars((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

if (!function_exists('url')) {
    /**
     * Escape text for use in URLs
     * Usage: <a href="/page?param=<?php echo url($param); ?>">
     * 
     * @param string $text Text to escape for URL
     * @return string URL-escaped text
     */
    function url($text) {
        return urlencode((string)$text);
    }
}

if (!function_exists('sanitizeInput')) {
    /**
     * Sanitize user input - removes dangerous tags and attributes
     * Usage: $clean = sanitizeInput($userInput);
     * 
     * @param string $input Input to sanitize
     * @param array $allowedTags Allowed HTML tags (default: none)
     * @return string Sanitized input
     */
    function sanitizeInput($input, array $allowedTags = []) {
        $input = (string)$input;
        
        // Remove null bytes
        $input = str_replace("\0", '', $input);
        
        // If no tags allowed, strip all HTML
        if (empty($allowedTags)) {
            return strip_tags($input);
        }
        
        // Otherwise, strip tags except allowed ones
        return strip_tags($input, '<' . implode('><', $allowedTags) . '>');
    }
}

if (!function_exists('isSafeUrl')) {
    /**
     * Check if a URL is safe (not a javascript/data URL)
     * Usage: if (isSafeUrl($url)) { echo '<a href="' . e($url) . '">'; }
     * 
     * @param string $url URL to check
     * @return bool True if URL is safe
     */
    function isSafeUrl($url) {
        $url = strtolower(trim((string)$url));
        
        // Reject javascript: and data: URLs
        $dangerousSchemes = ['javascript:', 'data:', 'vbscript:', 'file:', 'about:'];
        foreach ($dangerousSchemes as $scheme) {
            if (strpos($url, $scheme) === 0) {
                return false;
            }
        }
        
        return true;
    }
}

if (!function_exists('xssAuditLog')) {
    /**
     * Log potential XSS attempts for security monitoring
     * Usage: xssAuditLog('Suspicious input detected', $_POST['field']);
     * 
     * @param string $message Description of the issue
     * @param string $input The suspicious input
     */
    function xssAuditLog($message, $input) {
        $logMessage = sprintf(
            "[XSS Audit] %s | Input: %s | IP: %s | Time: %s | URI: %s",
            $message,
            substr((string)$input, 0, 100), // Truncate long inputs
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            date('Y-m-d H:i:s'),
            $_SERVER['REQUEST_URI'] ?? 'unknown'
        );
        
        error_log($logMessage);
    }
}

if (!function_exists('detectXSSPatterns')) {
    /**
     * Detect common XSS patterns in input
     * Usage: if (detectXSSPatterns($input)) { // handle suspicious input }
     * 
     * @param string $input Input to check
     * @return bool True if XSS patterns detected
     */
    function detectXSSPatterns($input) {
        $patterns = [
            '/<script[^>]*>.*?<\/script>/si',           // Script tags
            '/javascript:/i',                            // javascript: protocol
            '/on\w+\s*=/i',                              // Event handlers (onclick, onload, etc.)
            '/<iframe/i',                                 // Iframe tags
            '/<object/i',                                // Object tags
            '/<embed/i',                                 // Embed tags
            '/<form/i',                                  // Form tags
            '/expression\s*\(/i',                        // CSS expressions
            '/url\s*\(\s*["\']*javascript:/i',           // CSS javascript URLs
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, (string)$input)) {
                return true;
            }
        }
        
        return false;
    }
}

if (!function_exists('secureEcho')) {
    /**
     * Secure echo with XSS detection and logging
     * Usage: secureEcho($userInput, 'field_name');
     * 
     * @param string $text Text to output
     * @param string $fieldName Name of the field (for logging)
     * @param int $flags HTMLSpecialChars flags
     */
    function secureEcho($text, $fieldName = 'unknown', $flags = ENT_QUOTES | ENT_HTML5) {
        $text = (string)$text;
        
        // Detect and log potential XSS attempts
        if (detectXSSPatterns($text)) {
            xssAuditLog("XSS pattern detected in field: {$fieldName}", $text);
        }
        
        echo htmlspecialchars($text, $flags, 'UTF-8');
    }
}
