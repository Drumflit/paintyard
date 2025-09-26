<?php
/**
 * PaintYard Products API
 * API для роботи з товарами
 */

define('API_ACCESS', true);
require_once 'config.php';

// Отримуємо метод запиту
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

// Маршрутизація запитів
switch ($method) {
    case 'GET':
        handleGet();
        break;
    case 'POST':
        handlePost($input);
        break;
    case 'PUT':
        handlePut($input);
        break;
    case 'DELETE':
        handleDelete();
        break;
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}

/**
 * Обробка GET запитів (отримання товарів)
 */
function handleGet() {
    $data = readJsonFile(PRODUCTS_DB);
    
    if (isset($data['error'])) {
        jsonResponse($data, 500);
    }
    
    // Параметри пошуку та фільтрації
    $searchTerm = $_GET['search'] ?? '';
    $brand = $_GET['brand'] ?? '';
    $category = $_GET['category'] ?? '';
    $inStock = $_GET['in_stock'] ?? '';
    $limit = (int)($_GET['limit'] ?? 0);
    $offset = (int)($_GET['offset'] ?? 0);
    
    $products = $data['products'];
    
    // Фільтрація по пошуковому терміну
    if (!empty($searchTerm)) {
        $products = array_filter($products, function($product) use ($searchTerm) {
            return stripos($product['name'], $searchTerm) !== false ||
                   stripos($product['description'], $searchTerm) !== false;
        });
    }
    
    // Фільтрація по бренду
    if (!empty($brand)) {
        $products = array_filter($products, function($product) use ($brand) {
            return $product['brand'] === $brand;
        });
    }
    
    // Фільтрація по категорії
    if (!empty($category)) {
        $products = array_filter($products, function($product) use ($category) {
            return $product['category'] === $category;
        });
    }
    
    // Фільтрація по наявності
    if ($inStock === 'true') {
        $products = array_filter($products, function($product) {
            return $product['inStock'] === true && $product['quantity'] > 0;
        });
    } elseif ($inStock === 'false') {
        $products = array_filter($products, function($product) {
            return $product['inStock'] === false || $product['quantity'] <= 0;
        });
    }
    
    // Сортування (за замовчуванням по даті оновлення)
    $sortBy = $_GET['sort'] ?? 'updatedAt';
    $sortOrder = $_GET['order'] ?? 'desc';
    
    usort($products, function($a, $b) use ($sortBy, $sortOrder) {
        $aValue = $a[$sortBy] ?? '';
        $bValue = $b[$sortBy] ?? '';
        
        $comparison = strcmp($aValue, $bValue);
        
        return $sortOrder === 'desc' ? -$comparison : $comparison;
    });
    
    // Пагінація
    $totalProducts = count($products);
    if ($limit > 0) {
        $products = array_slice($products, $offset, $limit);
    }
    
    // Перевіряємо чи запитуються дані одного товару
    if (isset($_GET['id'])) {
        $productId = (int)$_GET['id'];
        $product = array_filter($products, function($p) use ($productId) {
            return $p['id'] === $productId;
        });
        
        if (empty($product)) {
            jsonResponse(['error' => 'Product not found'], 404);
        }
        
        jsonResponse([
            'success' => true,
            'product' => array_values($product)[0]
        ]);
    }
    
    jsonResponse([
        'success' => true,
        'products' => array_values($products),
        'total' => $totalProducts,
        'count' => count($products),
        'filters' => [
            'search' => $searchTerm,
            'brand' => $brand,
            'category' => $category,
            'in_stock' => $inStock
        ]
    ]);
}

/**
 * Обробка POST запитів (додавання товару)
 */
function handlePost($input) {
    if (!$input) {
        jsonResponse(['error' => 'Invalid input data'], 400);
    }
    
    // Валідація обов'язкових полів
    $requiredFields = ['name', 'brand', 'package', 'price'];
    $errors = validateRequired($input, $requiredFields);
    
    if (!empty($errors)) {
        jsonResponse(['error' => 'Validation failed', 'details' => $errors], 400);
    }
    
    // Санітізація даних
    $input = sanitizeData($input);
    
    // Додаткова валідація
    if (!is_numeric($input['price']) || $input['price'] <= 0) {
        jsonResponse(['error' => 'Price must be a positive number'], 400);
    }
    
    if (!in_array($input['brand'], ['teknos', 'aura'])) {
        jsonResponse(['error' => 'Invalid brand. Allowed: teknos, aura'], 400);
    }
    
    // Читаємо поточні дані
    $data = readJsonFile(PRODUCTS_DB);
    if (isset($data['error'])) {
        jsonResponse($data, 500);
    }
    
    // Створюємо новий товар
    $newProduct = [
        'id' => (int)generateId(),
        'name' => $input['name'],
        'brand' => $input['brand'],
        'category' => $input['category'] ?? 'general',
        'package' => $input['package'],
        'price' => (float)$input['price'],
        'description' => $input['description'] ?? '',
        'features' => $input['features'] ?? [],
        'image' => $input['image'] ?? '',
        'inStock' => $input['inStock'] ?? true,
        'quantity' => (int)($input['quantity'] ?? 0),
        'createdAt' => date('c'),
        'updatedAt' => date('c')
    ];
    
    // Додаємо товар до масиву
    $data['products'][] = $newProduct;
    $data['totalProducts'] = count($data['products']);
    $data['lastUpdated'] = date('c');
    
    // Зберігаємо дані
    if (!writeJsonFile(PRODUCTS_DB, $data)) {
        jsonResponse(['error' => 'Failed to save product'], 500);
    }
    
    logAction("Product added: ID {$newProduct['id']}, Name: {$newProduct['name']}");
    
    jsonResponse([
        'success' => true,
        'message' => 'Product added successfully',
        'product' => $newProduct
    ], 201);
}

/**
 * Обробка PUT запитів (оновлення товару)
 */
function handlePut($input) {
    if (!isset($_GET['id'])) {
        jsonResponse(['error' => 'Product ID is required'], 400);
    }
    
    $productId = (int)$_GET['id'];
    
    if (!$input) {
        jsonResponse(['error' => 'Invalid input data'], 400);
    }
    
    // Читаємо поточні дані
    $data = readJsonFile(PRODUCTS_DB);
    if (isset($data['error'])) {
        jsonResponse($data, 500);
    }
    
    // Знаходимо товар
    $productIndex = null;
    foreach ($data['products'] as $index => $product) {
        if ($product['id'] === $productId) {
            $productIndex = $index;
            break;
        }
    }
    
    if ($productIndex === null) {
        jsonResponse(['error' => 'Product not found'], 404);
    }
    
    // Санітізація даних
    $input = sanitizeData($input);
    
    // Оновлюємо товар
    $updatedProduct = $data['products'][$productIndex];
    
    // Оновлюємо тільки передані поля
    $allowedFields = ['name', 'brand', 'category', 'package', 'price', 'description', 'features', 'image', 'inStock', 'quantity'];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updatedProduct[$field] = $input[$field];
        }
    }
    
    // Валідація оновлених даних
    if (isset($input['price']) && (!is_numeric($input['price']) || $input['price'] <= 0)) {
        jsonResponse(['error' => 'Price must be a positive number'], 400);
    }
    
    if (isset($input['brand']) && !in_array($input['brand'], ['teknos', 'aura'])) {
        jsonResponse(['error' => 'Invalid brand. Allowed: teknos, aura'], 400);
    }
    
    $updatedProduct['updatedAt'] = date('c');
    
    // Зберігаємо оновлений товар
    $data['products'][$productIndex] = $updatedProduct;
    $data['lastUpdated'] = date('c');
    
    if (!writeJsonFile(PRODUCTS_DB, $data)) {
        jsonResponse(['error' => 'Failed to update product'], 500);
    }
    
    logAction("Product updated: ID {$productId}, Name: {$updatedProduct['name']}");
    
    jsonResponse([
        'success' => true,
        'message' => 'Product updated successfully',
        'product' => $updatedProduct
    ]);
}

/**
 * Обробка DELETE запитів (видалення товару)
 */
function handleDelete() {
    if (!isset($_GET['id'])) {
        jsonResponse(['error' => 'Product ID is required'], 400);
    }
    
    $productId = (int)$_GET['id'];
    
    // Читаємо поточні дані
    $data = readJsonFile(PRODUCTS_DB);
    if (isset($data['error'])) {
        jsonResponse($data, 500);
    }
    
    // Знаходимо та видаляємо товар
    $productFound = false;
    $productName = '';
    
    foreach ($data['products'] as $index => $product) {
        if ($product['id'] === $productId) {
            $productName = $product['name'];
            unset($data['products'][$index]);
            $productFound = true;
            break;
        }
    }
    
    if (!$productFound) {
        jsonResponse(['error' => 'Product not found'], 404);
    }
    
    // Переіндексуємо масив
    $data['products'] = array_values($data['products']);
    $data['totalProducts'] = count($data['products']);
    $data['lastUpdated'] = date('c');
    
    if (!writeJsonFile(PRODUCTS_DB, $data)) {
        jsonResponse(['error' => 'Failed to delete product'], 500);
    }
    
    logAction("Product deleted: ID {$productId}, Name: {$productName}");
    
    jsonResponse([
        'success' => true,
        'message' => 'Product deleted successfully',
        'deletedId' => $productId
    ]);
}

?>
