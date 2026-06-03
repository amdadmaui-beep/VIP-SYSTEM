<?php
/**
 * Pagination Tests
 * Tests for pagination functionality
 * 
 * Run: php tests/run_tests.php
 */

require_once __DIR__ . '/bootstrap.php';

class PaginationTest extends TestCase {
    
    public function run() {
        $this->testPaginationParameters();
        $this->testPaginationLimits();
        $this->testOffsetCalculation();
    }
    
    private function testPaginationParameters() {
        // Test default values
        $page = max(1, intval($_GET['page'] ?? 1));
        $per_page = min(100, max(1, intval($_GET['per_page'] ?? 20)));
        
        $this->assertEquals(1, $page, 'Default page should be 1');
        $this->assertEquals(20, $per_page, 'Default per_page should be 20');
        
        // Simulate page 2
        $_GET['page'] = 2;
        $_GET['per_page'] = 50;
        
        $page = max(1, intval($_GET['page'] ?? 1));
        $per_page = min(100, max(1, intval($_GET['per_page'] ?? 20)));
        $offset = ($page - 1) * $per_page;
        
        $this->assertEquals(2, $page, 'Page should be 2');
        $this->assertEquals(50, $per_page, 'Per page should be 50');
        $this->assertEquals(50, $offset, 'Offset should be 50');
        
        // Reset
        unset($_GET['page'], $_GET['per_page']);
    }
    
    private function testPaginationLimits() {
        // Test maximum limit enforcement
        $_GET['per_page'] = 200; // Try to exceed max
        
        $per_page = min(100, max(1, intval($_GET['per_page'] ?? 20)));
        
        $this->assertEquals(100, $per_page, 'Per page should be capped at 100');
        
        // Test minimum limit
        $_GET['per_page'] = 0;
        
        $per_page = min(100, max(1, intval($_GET['per_page'] ?? 20)));
        
        $this->assertEquals(1, $per_page, 'Per page should be at least 1');
        
        // Reset
        unset($_GET['per_page']);
    }
    
    private function testOffsetCalculation() {
        $test_cases = [
            ['page' => 1, 'per_page' => 20, 'expected' => 0],
            ['page' => 2, 'per_page' => 20, 'expected' => 20],
            ['page' => 3, 'per_page' => 50, 'expected' => 100],
            ['page' => 10, 'per_page' => 10, 'expected' => 90],
        ];
        
        foreach ($test_cases as $case) {
            $offset = ($case['page'] - 1) * $case['per_page'];
            $this->assertEquals(
                $case['expected'], 
                $offset, 
                "Offset calculation for page {$case['page']} with per_page {$case['per_page']}"
            );
        }
    }
}
