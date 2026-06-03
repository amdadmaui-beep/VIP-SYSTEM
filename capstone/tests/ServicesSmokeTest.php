<?php
require_once __DIR__ . '/bootstrap.php';

class ServicesSmokeTest extends TestCase
{
    private array $serviceFiles = [];

    protected function setUp(): void
    {
        $this->serviceFiles = [
            'orders_service' => INCLUDES_DIR . '/services/orders_service.php',
            'orders_repository' => INCLUDES_DIR . '/repositories/orders_repository.php',
            'orders_helper' => INCLUDES_DIR . '/helpers/orders_helper.php',
            'order_cancellation_helper' => INCLUDES_DIR . '/order_cancellation_helper.php',
            'cache' => INCLUDES_DIR . '/cache.php',
            'rate_limiter' => INCLUDES_DIR . '/rate_limiter.php',
            'password_security' => INCLUDES_DIR . '/password_security.php',
            'preparation_tasks_helper' => INCLUDES_DIR . '/preparation_tasks_helper.php',
            'cash_session_helper' => INCLUDES_DIR . '/cash_session_helper.php',
            'delivery_cancellation_helper' => INCLUDES_DIR . '/delivery_cancellation_helper.php',
        ];
    }

    public function run()
    {
        $this->testAllServiceFilesParse();
        $this->testPasswordSecurity();
        $this->testRateLimiterKey();
        $this->testOrderCancellationDefaults();
        $this->testOrderStatusHelper();
        $this->testCacheKeyGeneration();
    }

    private function testAllServiceFilesParse()
    {
        $allPass = true;
        $firstError = '';
        foreach ($this->serviceFiles as $name => $path) {
            if (!file_exists($path)) {
                $allPass = false;
                $firstError = "$name: file not found";
                break;
            }
            $output = [];
            $rc = 0;
            exec("php -l " . escapeshellarg($path) . " 2>&1", $output, $rc);
            if ($rc !== 0) {
                $allPass = false;
                $firstError = "$name: " . implode(' ', $output);
                break;
            }
        }
        $this->assertTrue($allPass, 'All service files pass syntax check' . ($firstError ? ': ' . $firstError : ''));
    }

    private function testPasswordSecurity()
    {
        require_once INCLUDES_DIR . '/password_security.php';
        $this->assertTrue(function_exists('vipPasswordHash'), 'vipPasswordHash exists');
        $this->assertTrue(function_exists('vipPasswordVerify'), 'vipPasswordVerify exists');

        $hash = vipPasswordHash('test_password_123');
        $this->assertNotEmpty($hash, 'Password hash is not empty');
        $this->assertTrue(vipPasswordVerify('test_password_123', $hash), 'Correct password verifies');
        $this->assertFalse(vipPasswordVerify('wrong_password', $hash), 'Wrong password fails');
    }

    private function testRateLimiterKey()
    {
        require_once INCLUDES_DIR . '/rate_limiter.php';
        $this->assertTrue(function_exists('rateLimitKey'), 'rateLimitKey exists');
    }

    private function testOrderCancellationDefaults()
    {
        require_once INCLUDES_DIR . '/order_cancellation_helper.php';
        $options = getOrderCancellationDefaultOptions();
        $this->assertNotEmpty($options, 'Cancellation options not empty');
        $this->assertTrue(in_array('Customer Change Mind', $options), 'Contains Customer Change Mind');
        $this->assertEquals(4, count($options), 'Four default cancellation options');
    }

    private function testOrderStatusHelper()
    {
        require_once INCLUDES_DIR . '/order_status_helper.php';
        $this->assertTrue(function_exists('getValidOrderStatus'), 'getValidOrderStatus exists');
    }

    private function testCacheKeyGeneration()
    {
        require_once INCLUDES_DIR . '/cache.php';
        $key1 = cacheKey('test', ['a' => 1, 'b' => 2]);
        $key2 = cacheKey('test', ['a' => 1, 'b' => 2]);
        $key3 = cacheKey('test', ['a' => 2, 'b' => 1]);

        $this->assertEquals($key1, $key2, 'Same params produce same key');
        $this->assertTrue($key1 !== $key3, 'Different params produce different key');
        $this->assertTrue(strpos($key1, 'test_') === 0, 'Key starts with prefix');
    }
}
