<?php
declare(strict_types=1);

if (!function_exists('vipPasswordHash')) {
    function vipPasswordHash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }
}

if (!function_exists('vipPasswordUsesLegacySha256')) {
    function vipPasswordUsesLegacySha256(string $storedHash): bool
    {
        return preg_match('/^[a-f0-9]{64}$/i', $storedHash) === 1;
    }
}

if (!function_exists('vipPasswordVerify')) {
    function vipPasswordVerify(string $password, string $storedHash): bool
    {
        if ($storedHash === '') {
            return false;
        }

        if (vipPasswordUsesLegacySha256($storedHash)) {
            return hash_equals(strtolower($storedHash), hash('sha256', $password));
        }

        return password_verify($password, $storedHash);
    }
}

if (!function_exists('vipPasswordNeedsRehash')) {
    function vipPasswordNeedsRehash(string $storedHash): bool
    {
        if (vipPasswordUsesLegacySha256($storedHash)) {
            return true;
        }

        return password_needs_rehash($storedHash, PASSWORD_DEFAULT);
    }
}

if (!function_exists('vipUpgradePasswordHashIfNeeded')) {
    function vipUpgradePasswordHashIfNeeded(PDO $conn, int $userId, string $password, string $storedHash): void
    {
        if ($userId <= 0 || !vipPasswordNeedsRehash($storedHash)) {
            return;
        }

        try {
            $stmt = $conn->prepare('UPDATE user SET password = ? WHERE User_ID = ?');
            $stmt->execute([vipPasswordHash($password), $userId]);
        } catch (Throwable $e) {
            error_log('Password rehash failed for user ' . $userId . ': ' . $e->getMessage());
        }
    }
}
