<?php
/**
 * Helpers for product add/edit category assignment.
 */

function productsTableHasCategoryId(PDO $conn): bool
{
    try {
        $result = $conn->query("SHOW COLUMNS FROM products LIKE 'category_id'");
        return $result && $result->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function fetchAssignableProductCategories(PDO $conn): array
{
    try {
        $stmt = $conn->query(
            "SELECT category_id, category_name
             FROM product_categories
             WHERE deleted_at IS NULL AND category_id != 1
             ORDER BY category_name"
        );
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {
        return [];
    }
}

function validateProductCategoryId(PDO $conn, ?int $categoryId): ?string
{
    if ($categoryId === null || $categoryId <= 0) {
        return 'Please select a product category.';
    }

    $stmt = $conn->prepare(
        "SELECT category_id FROM product_categories
         WHERE category_id = ? AND deleted_at IS NULL AND category_id != 1"
    );
    $stmt->execute([$categoryId]);
    if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
        return 'Invalid category selected.';
    }

    return null;
}

function renderProductCategoryPicker(array $categories, int $selectedCategoryId = 0): void
{
    if (empty($categories)) {
        echo '<div class="category-picker-empty">';
        echo '<i class="fas fa-folder-open"></i>';
        echo '<p>No categories available yet. <a href="categories.php">Create categories</a> first, then assign them here.</p>';
        echo '</div>';
        return;
    }

    echo '<select id="category_id" name="category_id" class="form-input" required>';
    echo '<option value="">Select Category</option>';
    foreach ($categories as $cat) {
        $cid = (int)$cat['category_id'];
        $selected = $selectedCategoryId === $cid ? ' selected' : '';
        echo '<option value="' . $cid . '"' . $selected . '>';
        echo htmlspecialchars($cat['category_name']);
        echo '</option>';
    }
    echo '</select>';
}
