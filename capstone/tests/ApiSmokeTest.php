<?php
require_once __DIR__ . '/bootstrap.php';

class ApiSmokeTest extends TestCase
{
    public function run()
    {
        $this->testCacheSystemWorks();
        $this->testConfigConstantsDefined();
        $this->testCsrfTokenValid();
    }

    private function testCacheSystemWorks()
    {
        require_once INCLUDES_DIR . '/cache.php';

        $key = 'smoke_test_' . time();
        $value = ['hello' => 'world'];

        cacheSet($key, $value, 30);
        $got = cacheGet($key);
        $this->assertEquals($value, $got, 'cacheSet + cacheGet round-trip');

        cacheDelete($key);
        $gone = cacheGet($key);
        $this->assertNull($gone, 'cacheDelete removes key');
    }

    private function testConfigConstantsDefined()
    {
        require_once INCLUDES_DIR . '/config.php';

        $this->assertTrue(defined('DB_HOST'), 'DB_HOST defined');
        $this->assertTrue(defined('DB_NAME'), 'DB_NAME defined');
        $this->assertTrue(defined('CSRF_TOKEN_LIFETIME'), 'CSRF_TOKEN_LIFETIME defined');
        $this->assertTrue(defined('CACHE_ENABLED'), 'CACHE_ENABLED defined');
    }

    private function testCsrfTokenValid()
    {
        require_once INCLUDES_DIR . '/csrf.php';

        $token = generateCsrfToken();
        $this->assertEquals(64, strlen($token), 'CSRF token is 64 chars');
        $this->assertTrue(ctype_xdigit($token), 'CSRF token is hex');

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = $token;
        $valid = validateCsrfToken(false);
        $this->assertTrue($valid, 'Generated token validates');
        unset($_POST['csrf_token']);
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }
}
