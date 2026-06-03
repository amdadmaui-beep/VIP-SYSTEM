<?php
/**
 * API Base Controller
 * Provides standardized API endpoint handling to eliminate code duplication
 * 
 * Location: capstone/includes/ApiController.php
 * Architecture Fix: Base class for all API endpoints
 */

require_once __DIR__ . '/middleware.php';

/**
 * Base API Controller
 * All API backend files should extend this class
 */
abstract class ApiController {
    protected $conn;
    protected $request;
    protected $response;
    protected $userId;
    protected $userRole;
    protected $action;
    
    // Configuration
    protected $allowedRoles = [];
    protected $stateChangingActions = [];
    protected $moduleKey = null;
    protected $ownerWriteActions = [];
    
    /**
     * Constructor
     */
    public function __construct(PDO $conn) {
        $this->conn = $conn;
        $this->init();
    }
    
    /**
     * Initialize request/response handling
     */
    protected function init() {
        // Set JSON output
        ini_set('display_errors', '0');
        error_reporting(E_ALL);
        header('Content-Type: application/json; charset=utf-8');
        
        // Start session if needed
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Initialize request/response objects
        $this->request = new ApiRequest();
        $this->response = new ApiResponse();
        $this->userId = $this->request->userId;
        $this->userRole = $this->request->userRole;
        $this->action = $this->request->action;
        
        // Run middleware chain
        $this->runMiddleware();
        
        // Route to handler
        $this->route();
    }
    
    /**
     * Run configured middleware
     */
    protected function runMiddleware() {
        $chain = new MiddlewareChain();
        
        // Always add auth middleware first
        $chain->use('authMiddleware');
        
        // Add role middleware if roles specified
        if (!empty($this->allowedRoles)) {
            $chain->use(roleMiddleware($this->allowedRoles));
        }
        
        // Add CSRF middleware for state-changing actions
        if (!empty($this->stateChangingActions)) {
            $chain->use(csrfMiddleware($this->stateChangingActions));
        }
        
        // Add module access middleware if module specified
        if ($this->moduleKey) {
            $chain->use(moduleAccessMiddleware($this->conn, $this->moduleKey));
        }
        
        // Add owner view-only restriction
        if (!empty($this->ownerWriteActions)) {
            $chain->use(ownerViewOnlyMiddleware($this->ownerWriteActions));
        }
        
        // Add JSON body parser
        $chain->use('jsonBodyMiddleware');
        
        // Execute chain
        $chain->run($this->request, $this->response);
    }
    
    /**
     * Route request to appropriate handler
     */
    protected function route() {
        $method = $this->request->method;
        $action = $this->action;
        
        // Build handler name
        $handlerName = 'handle' . ucfirst($method) . ($action ? ucfirst(str_replace('_', '', $action)) : 'Index');
        
        // Check for specific action handler first
        if (method_exists($this, $handlerName)) {
            $this->$handlerName();
            return;
        }
        
        // Check for generic method handler
        $genericHandler = 'handle' . $method;
        if (method_exists($this, $genericHandler)) {
            $this->$genericHandler();
            return;
        }
        
        // No handler found
        $this->response->error('Invalid action or method', 400);
    }
    
    /**
     * Success response helper
     */
    protected function success($data = null, $message = null) {
        $this->response->success($data, $message);
    }
    
    /**
     * Error response helper
     */
    protected function error($message, $code = 400, $data = null) {
        $this->response->error($message, $code, $data);
    }
    
    /**
     * Redirect helper
     */
    protected function redirect($url, $message = null, $type = 'success') {
        $this->response->redirect($url, $message, $type);
    }
    
    /**
     * Log activity helper
     */
    protected function log($type, $message, $targetId = null) {
        if (function_exists('logActivity')) {
            logActivity($type, $message, $targetId);
        }
    }
    
    /**
     * Validate required fields
     */
    protected function validateRequired(array $fields): array {
        $errors = [];
        foreach ($fields as $field) {
            if (!$this->request->has($field) || empty($this->request->get($field))) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
            }
        }
        return $errors;
    }
    
    /**
     * Check if user has specific role
     */
    protected function hasRole($role): bool {
        return in_array($this->userRole, (array)$role, true);
    }
    
    /**
     * Check if user is owner (view-only)
     */
    protected function isOwner(): bool {
        return $this->userRole === 1;
    }
    
    /**
     * Sanitize input
     */
    protected function sanitize(string $input, int $maxLength = 255): string {
        $clean = htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
        return substr($clean, 0, $maxLength);
    }
    
    /**
     * Begin database transaction
     */
    protected function beginTransaction() {
        $this->conn->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    protected function commit() {
        $this->conn->commit();
    }
    
    /**
     * Rollback transaction
     */
    protected function rollback() {
        $this->conn->rollBack();
    }
    
    /**
     * Execute query with error handling
     */
    protected function query(string $sql, array $params = []) {
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("[DB ERROR] {$e->getMessage()} | SQL: {$sql}");
            throw $e;
        }
    }
    
    /**
     * Fetch all results
     */
    protected function fetchAll(string $sql, array $params = []): array {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Fetch single row
     */
    protected function fetchOne(string $sql, array $params = []): ?array {
        $result = $this->query($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
    
    /**
     * Get last insert ID
     */
    protected function lastInsertId(): string {
        return $this->conn->lastInsertId();
    }
}

/**
 * Simple API Endpoint Factory
 * For quick API creation without full class
 */
class SimpleApiEndpoint {
    private $handlers = [];
    private $middleware = [];
    private $conn;
    
    public function __construct(PDO $conn) {
        $this->conn = $conn;
    }
    
    /**
     * Add middleware
     */
    public function use(callable $middleware): self {
        $this->middleware[] = $middleware;
        return $this;
    }
    
    /**
     * Register GET handler
     */
    public function get(string $action, callable $handler): self {
        $this->handlers['GET'][$action] = $handler;
        return $this;
    }
    
    /**
     * Register POST handler
     */
    public function post(string $action, callable $handler): self {
        $this->handlers['POST'][$action] = $handler;
        return $this;
    }
    
    /**
     * Run the endpoint
     */
    public function run() {
        $request = new ApiRequest();
        $response = new ApiResponse();
        
        // Run middleware
        $chain = new MiddlewareChain();
        foreach ($this->middleware as $m) {
            $chain->use($m);
        }
        
        $chain->use(function($req, $res) use ($request, $response) {
            $method = $request->method;
            $action = $request->action;
            
            if (isset($this->handlers[$method][$action])) {
                $this->handlers[$method][$action]($req, $res, $this->conn);
            } else {
                $response->error('Invalid action', 400);
            }
        });
        
        $chain->run($request, $response);
    }
}
