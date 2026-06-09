<?php
declare(strict_types=1);

/**
 * File-based product cache for the POS catalog.
 * Refreshes from DB every 60 seconds to reduce load.
 */

if (!function_exists('getCachedProducts')) {
    function getCachedProducts(PDO $conn, int $ttl = 60): array {
        $cacheDir = __DIR__ . '/../cache/products';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $cacheFile = $cacheDir . '/pos_catalog.json';
        $cacheMeta = $cacheDir . '/pos_catalog.meta';

        // Check if cache is fresh
        $mtime = file_exists($cacheMeta) ? (int) file_get_contents($cacheMeta) : 0;
        if (time() - $mtime < $ttl) {
            $cached = @file_get_contents($cacheFile);
            if ($cached !== false) {
                $data = json_decode($cached, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }

        // Cache expired or missing — query DB
        $query = "SELECT p.Product_ID, p.product_name, u.unit_name, p.retail_price, p.product_image
                   FROM products p
                   LEFT JOIN units u ON p.unit_id = u.unit_id
                   WHERE p.is_discontinued = 0
                   ORDER BY p.product_name";
        $rows = $conn->query($query)->fetchAll(PDO::FETCH_ASSOC);

        // Write cache
        @file_put_contents($cacheFile, json_encode($rows), LOCK_EX);
        @file_put_contents($cacheMeta, (string) time(), LOCK_EX);

        return $rows;
    }
}

if (!function_exists('clearProductCache')) {
    function clearProductCache(): void {
        $files = [
            __DIR__ . '/../cache/products/pos_catalog.json',
            __DIR__ . '/../cache/products/pos_catalog.meta',
        ];
        foreach ($files as $f) {
            if (file_exists($f)) {
                @unlink($f);
            }
        }
    }
}

if (!function_exists('productImageUrl')) {
    function productImageUrl(?string $filename): ?string {
        if (empty($filename)) {
            return null;
        }
        $path = '../uploads/products/' . rawurlencode($filename);
        return $path;
    }
}
