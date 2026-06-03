<?php
require_once __DIR__ . '/bootstrap.php';

class CacheIntegrationTest extends TestCase
{
    public function run()
    {
        $this->testInvalidateClearsQueryCache();
        $this->testCacheRemember();
    }

    private function testInvalidateClearsQueryCache()
    {
        require_once INCLUDES_DIR . '/cache.php';

        $key = cacheKey('query', ['SELECT 1 FROM orders']);
        cacheSet($key, ['dummy'], 300);

        $before = cacheGet($key);
        $this->assertNotEmpty($before, 'Query cache entry exists before invalidation');

        $cleared = cacheInvalidateTable('orders');
        $after = cacheGet($key);
        $this->assertNull($after, 'Query cache entry cleared after invalidation');
        $this->assertTrue($cleared >= 1, 'At least one cache file deleted');
    }

    private function testCacheRemember()
    {
        require_once INCLUDES_DIR . '/cache.php';

        $key = 'remember_test_' . time();
        $callCount = 0;

        $result1 = cacheRemember($key, function () use (&$callCount) {
            $callCount++;
            return ['called' => 1];
        }, 60);

        $result2 = cacheRemember($key, function () use (&$callCount) {
            $callCount++;
            return ['called' => 2];
        }, 60);

        $this->assertEquals(1, $callCount, 'Callback called only once (cached)');
        $this->assertEquals($result1, $result2, 'Same value returned from cache');

        cacheDelete($key);
    }
}
