<?php
/**
 * Security Tests
 * Tests for critical security fixes
 * 
 * Run: php tests/run_tests.php
 */

require_once __DIR__ . '/bootstrap.php';

class SecurityTest extends TestCase {
    
    public function run() {
        $this->testCsrfTokenGeneration();
        $this->testCsrfTokenValidation();
        $this->testCsrfTokenGraceWindow();
        $this->testEnvironmentConfiguration();
        $this->testCacheSystem();
    }
    
    private function testCsrfTokenGeneration() {
        require_once INCLUDES_DIR . '/csrf.php';
        
        $token1 = generateCsrfToken();
        $token2 = getCsrfToken();
        
        $this->assertEquals($token1, $token2, 'CSRF token should be consistent in same session');
        $this->assertEquals(64, strlen($token1), 'CSRF token should be 64 characters (32 bytes hex)');
        $this->assertTrue(ctype_xdigit($token1), 'CSRF token should be hexadecimal');
    }
    
    private function testCsrfTokenValidation() {
        require_once INCLUDES_DIR . '/csrf.php';
        
        // Store valid token
        $valid_token = $_SESSION['csrf_token'] ?? null;
        
        if ($valid_token) {
            // Simulate POST with valid token
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST['csrf_token'] = $valid_token;
            
            $result = validateCsrfToken(false);
            $this->assertTrue($result, 'Valid CSRF token should pass validation');
            
            // Test invalid token
            $_POST['csrf_token'] = 'invalid_token';
            $result = validateCsrfToken(false);
            $this->assertFalse($result, 'Invalid CSRF token should fail validation');
            
            // Reset
            $_SERVER['REQUEST_METHOD'] = 'GET';
            unset($_POST['csrf_token']);
        }
    }

    private function testCsrfTokenGraceWindow() {
        require_once INCLUDES_DIR . '/csrf.php';

        $old_token = getCsrfToken();
        $old_time = time() - ((defined('CSRF_TOKEN_LIFETIME') ? CSRF_TOKEN_LIFETIME : 3600) + 60);
        $_SESSION['csrf_token_time'] = $old_time;

        $new_token = generateCsrfToken();
        $this->assertTrue($old_token !== $new_token, 'CSRF token should rotate after lifetime');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = $old_token;
        $result_old = validateCsrfToken(false);
        $this->assertTrue($result_old, 'Previous CSRF token should be accepted during grace window');

        $_POST['csrf_token'] = $new_token;
        $result_new = validateCsrfToken(false);
        $this->assertTrue($result_new, 'Current CSRF token should validate after rotation');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_POST['csrf_token']);
    }
    
    private function testEnvironmentConfiguration() {
        require_once INCLUDES_DIR . '/config.php';
        
        $this->assertTrue(defined('DB_HOST'), 'DB_HOST should be defined');
        $this->assertTrue(defined('DB_USER'), 'DB_USER should be defined');
        $this->assertTrue(defined('DB_NAME'), 'DB_NAME should be defined');
        $this->assertTrue(defined('CSRF_TOKEN_LIFETIME'), 'CSRF_TOKEN_LIFETIME should be defined');
        $this->assertTrue(defined('CREDIT_LIMIT_ENABLED'), 'CREDIT_LIMIT_ENABLED should be defined');
        
        // Configuration can be enabled per environment; ensure type is boolean.
        $this->assertTrue(is_bool(CREDIT_LIMIT_ENABLED), 'Credit limit flag should be boolean');
    }
    
    private function testCacheSystem() {
        require_once INCLUDES_DIR . '/cache.php';
        
        // Test cache set and get
        $test_key = 'test_' . time();
        $test_value = ['data' => 'test', 'number' => 123];
        
        cacheSet($test_key, $test_value, 60);
        $retrieved = cacheGet($test_key);
        
        $this->assertEquals($test_value, $retrieved, 'Cache should store and retrieve data correctly');
        
        // Test cache delete
        cacheDelete($test_key);
        $retrieved = cacheGet($test_key);
        $this->assertNull($retrieved, 'Deleted cache should return null');
    }
}
