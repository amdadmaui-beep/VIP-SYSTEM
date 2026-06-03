<?php
/**
 * Simple Query Caching Layer
 * File-based caching for database queries and computed data
 * 
 * Location: capstone/includes/cache.php
 * Performance Fix: Reduces database load for frequently accessed data
 */

if (!defined('CACHE_ENABLED')) {
    define('CACHE_ENABLED', true);
}

if (!defined('CACHE_DIR')) {
    define('CACHE_DIR', __DIR__ . '/../cache');
}

if (!defined('CACHE_TTL')) {
    define('CACHE_TTL', 300); // 5 minutes default
}

/**
 * Ensure cache directory exists
 */
if (!is_dir(CACHE_DIR)) {
    @mkdir(CACHE_DIR, 0755, true);
}

/**
 * Generate cache key from query/parameters
 */
function cacheKey(string $prefix, array $params = []): string {
    return $prefix . '_' . md5(serialize($params));
}

/**
 * Get cached data
 * 
 * @param string $key Cache key
 * @return mixed|null Cached data or null if not found/expired
 */
function cacheGet(string $key) {
    if (!CACHE_ENABLED) {
        return null;
    }
    
    $file = CACHE_DIR . '/' . $key . '.cache';
    
    if (!file_exists($file)) {
        return null;
    }
    
    $raw = file_get_contents($file);
    if ($raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['expires'])) {
        return null;
    }
    
    // Check expiration
    if ($data['expires'] < time()) {
        @unlink($file);
        return null;
    }
    
    return $data['value'] ?? null;
}

/**
 * Store data in cache
 * 
 * @param string $key Cache key
 * @param mixed $value Data to cache
 * @param int $ttl Time to live in seconds (default 300 = 5 minutes)
 * @return bool Success status
 */
function cacheSet(string $key, $value, int $ttl = null): bool {
    if (!CACHE_ENABLED) {
        return false;
    }
    
    $file = CACHE_DIR . '/' . $key . '.cache';
    
    $data = [
        'expires' => time() + ($ttl ?? CACHE_TTL),
        'value' => $value
    ];
    
    return file_put_contents($file, json_encode($data), LOCK_EX) !== false;
}

/**
 * Delete cached data
 * 
 * @param string $key Cache key (supports wildcards with *)
 * @return int Number of files deleted
 */
function cacheDelete(string $key): int {
    $deleted = 0;
    
    if (strpos($key, '*') !== false) {
        // Wildcard delete
        $pattern = str_replace('*', '.*', $key);
        $files = glob(CACHE_DIR . '/*.cache');
        foreach ($files as $file) {
            $basename = basename($file, '.cache');
            if (preg_match('/^' . $pattern . '$/', $basename)) {
                @unlink($file);
                $deleted++;
            }
        }
    } else {
        // Single delete
        $file = CACHE_DIR . '/' . $key . '.cache';
        if (file_exists($file)) {
            @unlink($file);
            $deleted++;
        }
    }
    
    return $deleted;
}

/**
 * Clear all cache
 * 
 * @return int Number of files deleted
 */
function cacheClear(): int {
    $deleted = 0;
    $files = glob(CACHE_DIR . '/*.cache');
    
    foreach ($files as $file) {
        @unlink($file);
        $deleted++;
    }
    
    return $deleted;
}

/**
 * Get or set cache with callback
 * 
 * @param string $key Cache key
 * @param callable $callback Function to generate data if not cached
 * @param int $ttl Time to live in seconds
 * @return mixed Cached or fresh data
 */
function cacheRemember(string $key, callable $callback, int $ttl = null) {
    $value = cacheGet($key);
    
    if ($value !== null) {
        return $value;
    }
    
    $value = $callback();
    cacheSet($key, $value, $ttl);
    
    return $value;
}

/**
 * Cache database query results
 * 
 * @param PDO $conn Database connection
 * @param string $sql SQL query
 * @param array $params Query parameters
 * @param int $ttl Cache time in seconds
 * @return array Query results
 */
function cacheQuery(PDO $conn, string $sql, array $params = [], int $ttl = null): array {
    $key = cacheKey('query', [$sql, $params]);
    
    return cacheRemember($key, function() use ($conn, $sql, $params) {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }, $ttl);
}

/**
 * Invalidate cached queries (call after INSERT/UPDATE/DELETE)
 * 
 * Since query cache keys are hashed and don't embed table names,
 * we clear all query_* entries when any table changes.
 * 
 * @param string $table_name Unused — clears all query cache
 * @return int Number of cache entries cleared
 */
function cacheInvalidateTable(string $table_name = ''): int {
    return cacheDelete('query_*');
}
