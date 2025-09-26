<?php
/**
 * МАЛЯРНИЙ Products API
 * Виправлений API для роботи з товарами
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
    $priceMin = floatval($_GET['price_min'] ?? 0);
    $priceMax = floatval($_GET['price_max'] ?? 0);
    $limit = (int)($_GET['limit'] ?? 0);
    $offset = (int)($_GET['offset'] ?? 0);
    
    $products = $data['products'] ?? [];
    
    // Фільтрація по пошуковому терміну
    if (!empty($searchTerm)) {
        $products = array_filter($products, function($product) use ($searchTerm) {
            $searchIn = [
                $product['name'] ?? '',
                $product['description'] ?? '',
                implode(' ', $product['features'] ?? [])
            ];
            
            return stripos(implode(' ', $searchIn), $searchTerm) !== false;
        });
    }
    
    // Фільтрація по бренду
    if (!empty($brand)) {
        $products = array_filter($products, function($product) use ($brand) {
            return isset($product['brand']) && $product['brand'] === $brand;
        });
    }
    
    // Фільтрація по категорії
    if (!empty($category)) {
        $products = array_filter($products, function($product) use ($category) {
            return isset($product['category']) && $product['category'] === $category;
        });
    }
    
    // Фільтрація по наявності
    if ($inStock === 'true') {
        $products = array_filter($products, function($product) {
            return ($product['inStock'] ?? false) === true && ($product['quantity'] ?? 0) > 0;
        });
    } elseif ($inStock === 'false') {
        $products = array_filter($products, function($product) {
            return ($product['inStock'] ?? false) === false || ($product['quantity'] ?? 0) <= 0;
        });
    }
    
    // Фільтрація по ціні
    if ($priceMin > 0) {
        $products = array_filter($products, function($product) use ($priceMin) {
            return ($product['price'] ?? 0) >= $priceMin;
        });
    }
    
    if ($priceMax > 0) {
        $products = array_filter($products, function($product) use ($priceMax) {
            return ($product['price'] ?? 0) <= $priceMax;
        });
    }
    
    // Сортування
    $sortBy = $_GET['sort'] ?? 'updatedAt';
    $sortOrder = $_GET['order'] ?? 'desc';
    
    usort($products, function($a, $b) use ($sortBy, $sortOrder) {
        $aValue = $a[$sortBy] ?? '';
        $bValue = $b[$sortBy] ?? '';
        
        // Спеціальна обробка для цін та дат
        if ($sortBy === 'price') {
            $aValue = floatval($aValue);
            $bValue = floatval($bValue);
        } elseif (in_array($sortBy, ['createdAt', 'updatedAt'])) {
            $aValue = strtotime($aValue);
            $bValue = strtotime($bValue);
        }
        
        if ($aValue == $bValue) return 0;
        
        $result = ($aValue < $bValue) ? -1 : 1;
        return $sortOrder === 'desc' ? -$result : $result;
    });
    
    // Підрахунок статистики
    $totalProducts = count($products);
    $inStockCount = count(array_filter($products, function($p) {
        return ($p['inStock'] ?? false) && ($p['quantity'] ?? 0) > 0;
    }));
    
    // Пагінація
    if ($limit > 0) {
        $products = array_slice($products, $offset, $limit);
    }
    
    // Перевіряємо чи запитуються дані одного товару
    if (isset($_GET['id'])) {
        $productId = (int)$_GET['id'];
        $product = array_filter($products, function($p) use ($productId) {
            return ($p['id'] ?? 0) === $productId;
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
        'pagination' => [
            'total' => $totalProducts,
            'count' => count($products),
            'offset' => $offset,
            'limit' => $limit,
            'hasMore' => ($limit > 0) && (($offset + $limit) < $totalProducts)
        ],
        'statistics' => [
            'total' => $totalProducts,
            'inStock' => $inStockCount,
            'outOfStock' => $totalProducts - $inStockCount
        ],
        'filters' => [
            'search' => $searchTerm,
            'brand' => $brand,
            'category' => $category,
            'in_stock' => $inStock,
            'price_range' => [
                'min' => $priceMin,
                'max' => $priceMax
            ]
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
    
    // Додаткова валідація
    $validationErrors = [];
    
    // Валідація ціни
    if (!is_numeric($input['price']) || floatval($input['price']) <= 0) {
        $validationErrors[] = 'Price must be a positive number';
    }
    
    // Валідація бренду
    $allowedBrands = ['teknos', 'aura'];
    if (!in_array(strtolower($input['brand']), $allowedBrands)) {
        $validationErrors[] = 'Invalid brand. Allowed: ' . implode(', ', $allowedBrands);
    }
    
    // Валідація категорії
    $allowedCategories = ['interior', 'exterior', 'wood', 'eco', 'special'];
    if (isset($input['category']) && !in_array($input['category'], $allowedCategories)) {
        $validationErrors[] = 'Invalid category. Allowed: ' . implode(', ', $allowedCategories);
    }
    
    // Валідація кількості
    if (isset($input['quantity']) && (!is_numeric($input['quantity']) || intval($input['quantity']) < 0)) {
        $validationErrors[] = 'Quantity must be a non-negative integer';
    }
    
    if (!empty($validationErrors)) {
        jsonResponse(['error' => 'Validation failed', 'details' => $validationErrors], 400);
    }
    
    // Санітізація даних
    $input = sanitizeData($input);
    
    // Читаємо поточні дані
    $data = readJsonFile(PRODUCTS_DB);
    if (isset($data['error'])) {
        jsonResponse($data, 500);
    }
    
    // Створюємо новий товар
    $newProduct = [
        'id' => (int)generateId(),
        'name' => $input['name'],
        'brand' => strtolower($input['brand']),
        'category' => $input['category'] ?? 'interior',
        'package' => $input['package'],
        'price' => floatval($input['price']),
        'description' => $input['description'] ?? '',
        'features' => is_array($input['features'] ?? null) ? $input['features'] : [],
        'image' => $input['image'] ?? '',
        'inStock' => $input['inStock'] ?? true,
        'quantity' => intval($input['quantity'] ?? 0),
        'slug' => createSlug($input['name']),
        'sku' => $input['sku'] ?? generateSKU($input['brand'], $input['name']),
        'weight' => floatval($input['weight'] ?? 0),
        'dimensions' => $input['dimensions'] ?? null,
        'metadata' => [
            'views' => 0,
            'purchases' => 0,
            'rating' => 0,
            'reviews_count' => 0
        ],
        'seo' => [
            'title' => $input['seo_title'] ?? $input['name'],
            'description' => $input['seo_description'] ?? $input['description'] ?? '',
            'keywords' => $input['seo_keywords'] ?? []
        ],
        'createdAt' => date('c'),
        'updatedAt' => date('c')
    ];
    
    // Оновлюємо inStock статус на основі кількості
    if ($newProduct['quantity'] <= 0) {
        $newProduct['inStock'] = false;
    }
    
    // Додаємо товар до масиву
    $data['products'][] = $newProduct;
    
    // Оновлюємо метадані
    updateProductsMetadata($data);
    
    // Зберігаємо дані
    if (!writeJsonFile(PRODUCTS_DB, $data)) {
        jsonResponse(['error' => 'Failed to save product'], 500);
    }
    
    logAction("Product added: ID {$newProduct['id']}, Name: {$newProduct['name']}, SKU: {$newProduct['sku']}");
    
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
        if (($product['id'] ?? 0) === $productId) {
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
    
    // Дозволені поля для оновлення
    $allowedFields = [
        'name', 'brand', 'category', 'package', 'price', 'description', 
        'features', 'image', 'inStock', 'quantity', 'weight', 'dimensions',
        'sku', 'seo_title', 'seo_description', 'seo_keywords'
    ];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            if ($field === 'price') {
                if (!is_numeric($input[$field]) || floatval($input[$field]) <= 0) {
                    jsonResponse(['error' => 'Price must be a positive number'], 400);
                }
                $updatedProduct[$field] = floatval($input[$field]);
            } elseif ($field === 'quantity') {
                if (!is_numeric($input[$field]) || intval($input[$field]) < 0) {
                    jsonResponse(['error' => 'Quantity must be a non-negative integer'], 400);
                }
                $updatedProduct[$field] = intval($input[$field]);
            } elseif ($field === 'brand') {
                $allowedBrands = ['teknos', 'aura'];
                if (!in_array(strtolower($input[$field]), $allowedBrands)) {
                    jsonResponse(['error' => 'Invalid brand'], 400);
                }
                $updatedProduct[$field] = strtolower($input[$field]);
            } elseif ($field === 'category') {
                $allowedCategories = ['interior', 'exterior', 'wood', 'eco', 'special'];
                if (!in_array($input[$field], $allowedCategories)) {
                    jsonResponse(['error' => 'Invalid category'], 400);
                }
                $updatedProduct[$field] = $input[$field];
            } elseif (strpos($field, 'seo_') === 0) {
                // Обробка SEO полів
                $seoField = str_replace('seo_', '', $field);
                if (!isset($updatedProduct['seo'])) {
                    $updatedProduct['seo'] = [];
                }
                $updatedProduct['seo'][$seoField] = $input[$field];
            } else {
                $updatedProduct[$field] = $input[$field];
            }
        }
    }
    
    // Оновлюємо slug якщо змінилося ім'я
    if (isset($input['name'])) {
        $updatedProduct['slug'] = createSlug($input['name']);
    }
    
    // Автоматично оновлюємо inStock статус
    if (isset($input['quantity'])) {
        $updatedProduct['inStock'] = intval($input['quantity']) > 0;
    }
    
    $updatedProduct['updatedAt'] = date('c');
    
    // Зберігаємо оновлений товар
    $data['products'][$productIndex] = $updatedProduct;
    
    // Оновлюємо метадані
    updateProductsMetadata($data);
    
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
    $productSku = '';
    
    foreach ($data['products'] as $index => $product) {
        if (($product['id'] ?? 0) === $productId) {
            $productName = $product['name'] ?? '';
            $productSku = $product['sku'] ?? '';
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
    
    // Оновлюємо метадані
    updateProductsMetadata($data);
    
    if (!writeJsonFile(PRODUCTS_DB, $data)) {
        jsonResponse(['error' => 'Failed to delete product'], 500);
    }
    
    logAction("Product deleted: ID {$productId}, Name: {$productName}, SKU: {$productSku}");
    
    jsonResponse([
        'success' => true,
        'message' => 'Product deleted successfully',
        'deletedId' => $productId
    ]);
}

/**
 * Оновлення метаданих товарів
 */
function updateProductsMetadata(&$data) {
    $products = $data['products'] ?? [];
    
    // Підрахунок статистики
    $statistics = [
        'total' => count($products),
        'inStock' => 0,
        'outOfStock' => 0,
        'brands' => [],
        'categories' => [],
        'priceRange' => ['min' => 0, 'max' => 0]
    ];
    
    $prices = [];
    
    foreach ($products as $product) {
        // Підрахунок наявності
        if (($product['inStock'] ?? false) && ($product['quantity'] ?? 0) > 0) {
            $statistics['inStock']++;
        } else {
            $statistics['outOfStock']++;
        }
        
        // Збір брендів
        if (isset($product['brand']) && !in_array($product['brand'], $statistics['brands'])) {
            $statistics['brands'][] = $product['brand'];
        }
        
        // Збір категорій
        if (isset($product['category']) && !in_array($product['category'], $statistics['categories'])) {
            $statistics['categories'][] = $product['category'];
        }
        
        // Збір цін для діапазону
        if (isset($product['price']) && $product['price'] > 0) {
            $prices[] = floatval($product['price']);
        }
    }
    
    // Розрахунок діапазону цін
    if (!empty($prices)) {
        $statistics['priceRange']['min'] = min($prices);
        $statistics['priceRange']['max'] = max($prices);
    }
    
    $data['statistics'] = $statistics;
    $data['lastUpdated'] = date('c');
    $data['totalProducts'] = $statistics['total'];
    $data['inStockProducts'] = $statistics['inStock'];
    $data['outOfStockProducts'] = $statistics['outOfStock'];
}

/**
 * Генерація SKU для товару
 */
function generateSKU($brand, $name) {
    $brandCode = strtoupper(substr($brand, 0, 3));
    $nameCode = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $name), 0, 6));
    $randomCode = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
    
    return $brandCode . '-' . $nameCode . '-' . $randomCode;
}

?>
