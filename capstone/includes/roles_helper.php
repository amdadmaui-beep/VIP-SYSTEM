<?php
/**
 * Role helpers - fetch role IDs from database (no hardcoding)
 * Uses roles.role_name to determine which Role_IDs map to rider, inventory_staff, etc.
 */

/**
 * Get Role_IDs for delivery rider (role_name contains 'rider' or is 'delivery_rider')
 * @param PDO $conn
 * @return int[]
 */
function getRiderRoleIds($conn) {
    try {
        $stmt = $conn->prepare("SELECT Role_ID FROM roles WHERE LOWER(role_name) LIKE '%rider%' OR LOWER(role_name) = 'delivery_rider'");
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get Role_IDs for inventory staff (role_name contains 'inventory')
 * @param PDO $conn
 * @return int[]
 */
function getInventoryStaffRoleIds($conn) {
    try {
        $stmt = $conn->prepare("SELECT Role_ID FROM roles WHERE LOWER(role_name) LIKE '%inventory%'");
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get Role_IDs for cashier / POS users (role_name contains 'cashier')
 * @param PDO $conn
 * @return int[]
 */
function getCashierRoleIds($conn) {
    try {
        $stmt = $conn->prepare("SELECT Role_ID FROM roles WHERE LOWER(role_name) LIKE '%cashier%'");
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get Role_IDs for owner (role_name contains 'owner')
 * @param PDO $conn
 * @return int[]
 */
function getOwnerRoleIds($conn) {
    try {
        $stmt = $conn->prepare("SELECT Role_ID FROM roles WHERE LOWER(role_name) = 'owner' OR LOWER(role_name) LIKE '%owner%'");
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get Role_IDs for manager (role_name contains 'manager')
 * @param PDO $conn
 * @return int[]
 */
function getManagerRoleIds($conn) {
    try {
        $stmt = $conn->prepare("SELECT Role_ID FROM roles WHERE LOWER(role_name) = 'manager' OR LOWER(role_name) LIKE '%manager%'");
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Get Role_IDs for management access (owner + manager)
 * @param PDO $conn
 * @return int[]
 */
function getManagementRoleIds($conn) {
    $owner_ids = getOwnerRoleIds($conn);
    $manager_ids = getManagerRoleIds($conn);
    return array_values(array_unique(array_merge($owner_ids, $manager_ids)));
}

/**
 * Get Role_IDs for dashboard access (owner + manager only)
 * @param PDO $conn
 * @return int[]
 */
function getDashboardRoleIds($conn) {
    return getManagementRoleIds($conn);
}
