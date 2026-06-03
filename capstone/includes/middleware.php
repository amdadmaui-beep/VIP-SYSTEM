<?php
/**
 * API Middleware System
 * Centralized request/response handling to eliminate code duplication
 * 
 * Location: capstone/includes/middleware.php
 * Architecture Fix: Reduces duplication across API endpoints
 */

if (!defined('MIDDLEWARE_INCLUDED')) {
    define('MIDDLEWARE_INCLUDED', true);
}

/**
 * Middleware Chain Handler
 * Executes middleware in sequence
 */
class MiddlewareChain {
    private $middlewares = [];
    private $index = 0;
    
    public function use(callable $middleware) {
        $this->middlewares[] = $middleware;
        return $this;
    }
    
    public function run($request, $response) {
        $this->index = 0;
        $this->next($request, $response);
    }
    
    private function next($request, $response) {
        if ($this->index >= count($this->middlewares)) {
            return;
        }
        
        $middleware = $this->middlewares[$this->index++];
        $middleware($request, $response, function() use ($request, $response) {
            $this->next($request, $response);
        });
    }
}

/**
 * Request Object
 * Encapsulates HTTP request data
 */
class ApiRequest {
    public $method;
    public $action;
    public $params = [];
    public $headers = [];
    public $userId = null;
    public $userRole = null;
    public $isAjax = false;
    public $isApi = false;
    
    public function __construct() {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->action = $_POST['action'] ?? $_GET['action'] ?? '';
        $this->params = array_merge($_GET, $_POST);
        $this->headers = getallheaders() ?: [];
        $this->userId = $_SESSION['user_id'] ?? null;
        $this->userRole = $_SESSION['user_role'] ?? null;
        $this->isAjax = isset($_POST['ajax']) || 
                       (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
                        $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest');
        $this->isApi = $this->isApiRequest();
    }
    
    private function isApiRequest(): bool {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if (strpos($script, '/api/') !== false) return true;
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        return strpos($accept, 'application/json') !== false;
    }
    
    public function get($key, $default = null) {
        return $this->params[$key] ?? $default;
    }
    
    public function has($key): bool {
        return isset($this->params[$key]);
    }
}

/**
 * Response Object
 * Handles consistent API responses
 */
class ApiResponse {
    private $headers = [];
    private $statusCode = 200;
    
    public function __construct() {
        $this->header('Content-Type: application/json; charset=utf-8');
    }
    
    public function header($header) {
        $this->headers[] = $header;
    }
    
    public function status($code) {
        $this->statusCode = $code;
        return $this;
    }
    
    public function json($data) {
        $this->sendHeaders();
        http_response_code($this->statusCode);
        echo json_encode($data);
        exit;
    }
    
    public function success($data = null, $message = null) {
        $response = ['success' => true];
        if ($data !== null) $response['data'] = $data;
        if ($message !== null) $response['message'] = $message;
        $this->json($response);
    }
    
    public function error($message, $code = 400, $data = null) {
        $response = ['success' => false, 'error' => $message];
        if ($data !== null) $response['data'] = $data;
        $this->status($code)->json($response);
    }
    
    public function redirect($url, $message = null, $type = 'success') {
        $sep = (strpos($url, '?') === false) ? '?' : '&';
        $msg = is_array($message) ? ($message['message'] ?? '') : $message;
        if ($msg) {
            $url .= $sep . $type . '=' . urlencode($msg);
        }
        header("Location: $url");
        exit;
    }
    
    private function sendHeaders() {
        foreach ($this->headers as $header) {
            header($header);
        }
    }
}

/**
 * Authentication Middleware
 * Ensures user is logged in
 */
function authMiddleware(ApiRequest $req, ApiResponse $res, callable $next) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['user_id'])) {
        if ($req->isApi || $req->isAjax) {
            $res->error('Unauthorized', 401);
        }
        $res->redirect('../login.php?error=' . urlencode('Please login to continue'));
    }
    
    $req->userId = $_SESSION['user_id'];
    $req->userRole = $_SESSION['user_role'] ?? null;
    $next();
}

/**
 * Role Check Middleware Factory
 * Returns middleware that checks for specific roles
 */
function roleMiddleware(array $allowedRoles) {
    return function(ApiRequest $req, ApiResponse $res, callable $next) use ($allowedRoles) {
        $userRole = $req->userRole ?? 0;
        
        if (!in_array($userRole, $allowedRoles, true)) {
            // Get role-specific redirect
            global $conn;
            $rider_ids = isset($conn) ? getRiderRoleIds($conn) : [];
            $inv_ids = isset($conn) ? getInventoryStaffRoleIds($conn) : [];
            $cashier_ids = isset($conn) ? getCashierRoleIds($conn) : [];
            
            $is_rider = in_array($userRole, $rider_ids);
            $is_inv = in_array($userRole, $inv_ids);
            $is_cashier = in_array($userRole, $cashier_ids);
            
            if ($req->isApi || $req->isAjax) {
                $res->error('Forbidden - Insufficient permissions', 403);
            }
            
            if ($is_rider) {
                $res->redirect('../pages/rider_view.php?error=' . urlencode('Access denied'));
            } elseif ($is_inv) {
                $res->redirect('../pages/manual_adjustment.php?error=' . urlencode('Access denied'));
            } elseif ($is_cashier) {
                $res->redirect('../pages/cashier_view.php?error=' . urlencode('Access denied'));
            } else {
                $res->redirect('../index.php?error=' . urlencode('Access denied'));
            }
        }
        
        $next();
    };
}

/**
 * CSRF Protection Middleware
 * Validates CSRF token for state-changing requests
 */
function csrfMiddleware(array $stateChangingActions = []) {
    return function(ApiRequest $req, ApiResponse $res, callable $next) use ($stateChangingActions) {
        if ($req->method !== 'POST') {
            $next();
            return;
        }
        
        // Check if action requires CSRF validation
        if (!empty($stateChangingActions) && !in_array($req->action, $stateChangingActions)) {
            $next();
            return;
        }
        
        if (!validateCsrfToken(false)) {
            $msg = 'Invalid or expired security token. Please refresh the page and try again.';
            if ($req->isApi || $req->isAjax) {
                $res->error($msg, 403);
            }
            $res->redirect('../pages/dashboard.php?error=' . urlencode($msg));
        }
        
        $next();
    };
}

/**
 * Owner View-Only Middleware
 * Restricts Owner role (1) to view-only operations
 */
function ownerViewOnlyMiddleware(array $writeActions = []) {
    return function(ApiRequest $req, ApiResponse $res, callable $next) use ($writeActions) {
        if (($req->userRole ?? 0) !== 1) {
            $next();
            return;
        }
        
        // Owner is view-only for write actions
        if ($req->method === 'POST' && in_array($req->action, $writeActions)) {
            $msg = 'Your account (Owner) is restricted to view-only access. Operations are not allowed.';
            if ($req->isApi || $req->isAjax) {
                $res->error($msg, 403);
            }
            $res->redirect('../pages/dashboard.php?error=' . urlencode($msg));
        }
        
        $next();
    };
}

/**
 * Module Access Middleware Factory
 * Checks if user has access to specific module
 */
function moduleAccessMiddleware(PDO $conn, string $moduleKey) {
    return function(ApiRequest $req, ApiResponse $res, callable $next) use ($conn, $moduleKey) {
        if (!isModuleAllowedForUser($conn, (int)$req->userId, $moduleKey, true)) {
            $msg = "Access to this module is currently restricted for your account.";
            if ($req->isApi || $req->isAjax) {
                $res->error($msg, 403);
            }
            $res->redirect('../pages/dashboard.php?error=' . urlencode($msg));
        }
        $next();
    };
}

/**
 * JSON Body Parser Middleware
 * Parses JSON request bodies
 */
function jsonBodyMiddleware(ApiRequest $req, ApiResponse $res, callable $next) {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        $input = file_get_contents('php://input');
        $json = json_decode($input, true);
        if ($json) {
            $req->params = array_merge($req->params, $json);
        }
    }
    $next();
}

/**
 * Logging Middleware
 * Logs API requests for audit trail
 */
function loggingMiddleware(ApiRequest $req, ApiResponse $res, callable $next) {
    $startTime = microtime(true);
    
    $next();
    
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    // Log slow requests (> 5 seconds)
    if ($duration > 5000) {
        error_log("[SLOW REQUEST] {$req->method} {$req->action} took {$duration}ms");
    }
}

/**
 * Helper: Create standard API handler with middleware
 * Reduces boilerplate code in API files
 */
function createApiHandler(array $middlewares, callable $handler) {
    return function() use ($middlewares, $handler) {
        global $conn;
        
        $request = new ApiRequest();
        $response = new ApiResponse();
        
        $chain = new MiddlewareChain();
        
        foreach ($middlewares as $middleware) {
            $chain->use($middleware);
        }
        
        $chain->use(function($req, $res) use ($handler) {
            $handler($req, $res);
        });
        
        $chain->run($request, $response);
    };
}

/**
 * Standard API Response Helpers
 * Eliminates duplicate response formatting
 */
function apiSuccess($data = null, $message = null) {
    $res = new ApiResponse();
    $res->success($data, $message);
}

function apiError($message, $code = 400, $data = null) {
    $res = new ApiResponse();
    $res->error($message, $code, $data);
}

function apiRedirect($url, $message = null, $type = 'success') {
    $res = new ApiResponse();
    $res->redirect($url, $message, $type);
}
