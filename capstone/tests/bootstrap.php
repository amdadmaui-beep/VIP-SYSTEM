<?php
/**
 * Test Bootstrap File
 * Initializes testing environment
 * 
 * Location: capstone/tests/bootstrap.php
 */

// Set error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Define test constants
define('TEST_DIR', __DIR__);
define('ROOT_DIR', dirname(__DIR__));
define('API_DIR', ROOT_DIR . '/api');
define('INCLUDES_DIR', ROOT_DIR . '/includes');

// Load Composer autoloader (provides PHPUnit classes)
$composerAutoload = ROOT_DIR . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

// Autoloader for test classes
spl_autoload_register(function ($class) {
    $file = TEST_DIR . '/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// Load application config
require_once INCLUDES_DIR . '/config.php';

// Test database configuration (use separate test database if available)
if (!defined('TEST_DB_NAME')) {
    define('TEST_DB_NAME', DB_NAME . '_test');
}

/**
 * Base Test Class
 */
abstract class TestCase {
    protected function stringifyValue($value): string {
        if (is_array($value) || is_object($value)) {
            $json = json_encode($value);
            return $json !== false ? $json : gettype($value);
        }
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string)$value;
    }

    protected $passed = 0;
    protected $failed = 0;
    protected $tests = [];
    
    abstract public function run();
    
    protected function assert($condition, $message) {
        if ($condition) {
            $this->passed++;
            $this->tests[] = ['status' => 'PASS', 'message' => $message];
        } else {
            $this->failed++;
            $this->tests[] = ['status' => 'FAIL', 'message' => $message];
        }
        return $condition;
    }
    
    protected function assertEquals($expected, $actual, $message) {
        $expectedText = $this->stringifyValue($expected);
        $actualText = $this->stringifyValue($actual);
        return $this->assert($expected === $actual, "$message (Expected: $expectedText, Got: $actualText)");
    }
    
    protected function assertTrue($condition, $message) {
        return $this->assert($condition === true, $message);
    }
    
    protected function assertFalse($condition, $message) {
        return $this->assert($condition === false, $message);
    }
    
    protected function assertNotEmpty($value, $message) {
        return $this->assert(!empty($value), $message);
    }

    protected function assertNull($value, $message) {
        return $this->assert($value === null, $message);
    }
    
    public function getResults() {
        return [
            'class' => get_class($this),
            'passed' => $this->passed,
            'failed' => $this->failed,
            'total' => $this->passed + $this->failed,
            'tests' => $this->tests
        ];
    }
}

/**
 * Test Runner
 */
class TestRunner {
    private $tests = [];
    
    public function addTest(TestCase $test) {
        $this->tests[] = $test;
    }
    
    public function run() {
        echo "=== VIP System Test Suite ===\n\n";
        
        $totalPassed = 0;
        $totalFailed = 0;
        
        foreach ($this->tests as $test) {
            echo "Running: " . get_class($test) . "\n";
            $test->run();
            $results = $test->getResults();
            
            foreach ($results['tests'] as $t) {
                $symbol = $t['status'] === 'PASS' ? '✓' : '✗';
                echo "  $symbol {$t['message']}\n";
            }
            
            $totalPassed += $results['passed'];
            $totalFailed += $results['failed'];
            
            echo "  Passed: {$results['passed']}, Failed: {$results['failed']}\n\n";
        }
        
        echo "=== Summary ===\n";
        echo "Total Passed: $totalPassed\n";
        echo "Total Failed: $totalFailed\n";
        $total = $totalPassed + $totalFailed;
        $successRate = $total > 0 ? round(($totalPassed / $total) * 100, 2) : 100;
        echo "Success Rate: {$successRate}%\n";
        
        return $totalFailed === 0;
    }
}
