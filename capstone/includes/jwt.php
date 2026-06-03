<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function jwtSecret(): string {
    $secret = getenv('JWT_SECRET');
    if (is_string($secret) && strlen(trim($secret)) >= 32) {
        return trim($secret);
    }

    throw new RuntimeException('JWT_SECRET must be configured with at least 32 characters in .env');
}

function jwtIssue(array $claims, int $ttlSeconds = 86400): string {
    $now = time();
    $payload = array_merge([
        'iat' => $now,
        'nbf' => $now - 5,
        'exp' => $now + max(60, $ttlSeconds),
        'iss' => 'VIP-system',
        'aud' => 'VIP-system',
    ], $claims);

    return JWT::encode($payload, jwtSecret(), 'HS256');
}

function jwtVerify(string $token): array {
    $decoded = JWT::decode($token, new Key(jwtSecret(), 'HS256'));
    // Convert stdClass -> array
    return json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
}

function getBearerToken(): ?string {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? '';
    if (!$hdr || !is_string($hdr)) return null;
    if (stripos($hdr, 'Bearer ') !== 0) return null;
    $t = trim(substr($hdr, 7));
    return $t !== '' ? $t : null;
}

