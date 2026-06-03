<?php
/**
 * Test Runner
 * Execute all test suites
 * 
 * Usage: php tests/run_tests.php
 * Location: capstone/tests/run_tests.php
 */

require_once __DIR__ . '/bootstrap.php';

// Find all test files
$test_files = glob(__DIR__ . '/*Test.php');

if (empty($test_files)) {
    echo "No test files found.\n";
    exit(1);
}

$runner = new TestRunner();

foreach ($test_files as $file) {
    require_once $file;
    
    // Get class name from file
    $class = basename($file, '.php');
    
    if (class_exists($class)) {
        $test = new $class();
        if ($test instanceof TestCase) {
            $runner->addTest($test);
        }
    }
}

$success = $runner->run();

exit($success ? 0 : 1);
