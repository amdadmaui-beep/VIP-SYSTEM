<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

class PHPUnitConfigTest extends TestCase
{
    public function testConfigConstantsDefined()
    {
        require_once INCLUDES_DIR . '/config.php';
        $this->assertTrue(defined('DB_HOST'));
        $this->assertTrue(defined('DB_NAME'));
        $this->assertTrue(defined('CSRF_TOKEN_LIFETIME'));
    }

    public function testCacheLayerWorks()
    {
        require_once INCLUDES_DIR . '/cache.php';

        $key = 'phpunit_test_' . time();
        $value = ['phpunit' => true];

        cacheSet($key, $value, 30);
        $this->assertSame($value, cacheGet($key));

        cacheDelete($key);
        $this->assertNull(cacheGet($key));
    }

    public function testCacheInvalidateClearsQueries()
    {
        require_once INCLUDES_DIR . '/cache.php';

        $key = cacheKey('query', ['PHPUnit smoke test']);
        cacheSet($key, ['smoke'], 300);
        $this->assertNotNull(cacheGet($key));

        cacheInvalidateTable();
        $this->assertNull(cacheGet($key));
    }

    public function testPaginationDefaults()
    {
        $page = max(1, intval($_GET['page'] ?? 1));
        $per_page = min(100, max(1, intval($_GET['per_page'] ?? 20)));

        $this->assertSame(1, $page);
        $this->assertSame(20, $per_page);
    }

    public function testCsrfTokenFormat()
    {
        require_once INCLUDES_DIR . '/csrf.php';

        $token = generateCsrfToken();
        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
    }
}
