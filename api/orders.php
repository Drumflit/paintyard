<?php
/**
 * PaintYard Orders API
 * API для роботи з замовленнями
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
 * Обробка GET запитів (отримання замовлень)
 */
function handleGet() {
    $data = readJsonFile(ORDERS_DB);
    
    if (isset($data['error'])) {
        jsonResponse($data, 500);
    }
    
    // Параметри пошуку та фільтрації
    $searchTerm = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? '';
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $limit = (int)($_GET['limit'] ?? 0);
    $offset = (int)($_GET['offset'] ?? 0);
    
    $orders = $data['orders'];
    
    // Фільтрація по пошуковому терміну
    if (!empty($searchTerm)) {
        $orders = array_filter($orders, function($order) use ($searchTerm) {
            return stripos($order['customerInfo']['name'], $searchTerm) !== false ||
                   stripos($order['customerInfo']['phone'], $searchTerm) !== false ||
                   stripos($order['orderNumber'], $searchTerm) !== false;
        });
    }
    
    // Фільтрація по статусу
    if (!empty($status)) {
        $orders = array_filter($orders, function($order) use ($status) {
            return $order['status'] === $status;
        });
    }
    
    // Фільтрація по даті
    if (!empty($dateFrom)) {
        $orders = array_filter($orders, function($order) use ($dateFrom) {
            return $order['createdAt'] >= $dateFrom;
        });
    }
    
    if (!empty($dateTo)) {
        $orders = array_filter($orders, function($order) use ($dateTo) {
            return $order['createdAt'] <= $dateTo;
        });
    }
    
    // Сортування (за замовчуванням по даті створення)
    $sortBy = $_GET['sort'] ?? 'createdAt';
    $sortOrder = $_GET['order'] ?? 'desc';
    
    usort($orders, function($a, $b) use ($sortBy, $sortOrder) {
        $aValue = $a[$sortBy] ?? '';
        $bValue = $b[$sortBy] ?? '';
        
        $comparison = strcmp($aValue, $bValue);
        
        return $sortOrder === 'desc' ? -$comparison : $comparison;
    });
    
    // Пагінація
    $totalOrders = count($orders);
    if ($limit > 0) {
        $orders = array_slice($orders, $offset, $limit);
    }
    
    // Перевіряємо чи запитуються дані одного замовлення
    if (isset($_GET['id'])) {
        $orderId = (int)$_GET['id'];
        $order = array_filter($orders, function($o) use ($orderId) {
            return $o['id'] === $orderId;
        });
        
        if (empty($order)) {
            jsonResponse(['error' => 'Order not found'], 404);
        }
        
        jsonResponse([
            'success' => true,
            'order' => array_values($order)[0]
        ]);
    }
    
    jsonResponse([
        'success' => true,
        'orders' => array_values($orders),
        'total' => $totalOrders,
        'count' => count($orders),
        'filters' => [
            'search' => $searchTerm,
            'status' => $status,
            'date_from' => $dateFrom,
            'date_to' => $dateTo
        ]
    ]);
}

/**
 * Обробка POST запитів (створення замовлення)
 */
function handlePost($input) {
    if (!$input) {
        jsonResponse(['error' => 'Invalid input data'], 400);
    }
    
    // Валідація обов'язкових полів
    $requiredFields = ['customerName', 'phone', 'items'];
    $errors = validateRequired($input, $requiredFields);
    
    if (!empty($errors)) {
        jsonResponse(['error' => 'Validation failed', 'details' => $errors], 400);
    }
    
    // Санітізація даних
    $input = sanitizeData($input);
    
    // Додаткова валідація
    if (!is_array($input['items']) || empty($input['items'])) {
        jsonResponse(['error' => 'Items must be a non-empty array'], 400);
    }
    
    // Читаємо поточні дані
    $data = readJsonFile(ORDERS_DB);
    if (isset($data['error'])) {
        jsonResponse($data, 500);
    }
    
    // Розрахунок загальної суми
    $subtotal = 0;
    foreach ($input['items'] as $item) {
        $subtotal += ($item['unitPrice'] ?? 0) * ($item['quantity'] ?? 1);
    }
    
    $delivery = $input['delivery'] ?? 0;
    $discount = $input['discount'] ?? 0;
    $total = $subtotal + $delivery - $discount;
    
    // Генеруємо номер замовлення
    $orderNumber = 'PY-' . date('Y') . '-' . str_pad(count($data['orders']) + 1, 3, '0', STR_PAD_LEFT);
    
    // Створюємо нове замовлення
    $newOrder = [
        'id' => (int)generateId(),
        'orderNumber' => $orderNumber,
        'customerInfo' => [
            'name' => $input['customerName'],
            'phone' => $input['phone'],
            'email' => $input['email'] ?? '',
            'address' => $input['address'] ?? ''
        ],
        'items' => $input['items'],
        'orderSummary' => [
            'subtotal' => $subtotal,
            'delivery' => $delivery,
            'discount' => $discount,
            'total' => $total
        ],
        'paymentInfo' => [
            'method' => $input['paymentMethod'] ?? 'cash',
            'status' => 'pending',
            'paidAmount' => 0.00
        ],
        'deliveryInfo' => [
            'method' => $input['deliveryMethod'] ?? 'courier',
            'address' => $input['address'] ?? '',
            'preferredDate' => $input['preferredDate'] ?? '',
            'preferredTime' => $input['preferredTime'] ?? '',
            'instructions' => $input['instructions'] ?? ''
        ],
        'status' => 'new',
        'priority' => 'normal',
        'source' => $input['source'] ?? 'website',
        'notes' => $input['notes'] ?? '',
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
        'assignedManager' => null
    ];
    
    // Додаємо замовлення до масиву
    $data['orders'][] = $newOrder;
    
    // Оновлюємо статистику
    updateOrderStatistics($data);
    
    // Зберігаємо дані
    if (!writeJsonFile(ORDERS_DB, $data)) {
        jsonResponse(['error' => 'Failed to save order'], 500);
    }
    
    logAction("Order created: ID {$newOrder['id']}, Number: {$newOrder['orderNumber']}");
    
    jsonResponse([
        'success' => true,
        'message' => 'Order created successfully',
        'order' => $newOrder
    ], 201);
}

/**
 * Обробка PUT запитів (оновлення замовлення)
 */
function handlePut($input) {
    if (!isset($_GET['id'])) {
        jsonResponse(['error' => 'Order ID is required'], 400);
    }
    
    $orderId = (int)$_GET['id'];
    
    if (!$input) {
        jsonResponse(['error' => 'Invalid input data'], 400);
    }
    
    // Читаємо поточні дані
    $data = readJsonFile(ORDERS_DB);
    if (isset($data['error'])) {
        jsonResponse($data, 500);
    }
    
    // Знаходимо замовлення
    $orderIndex = null;
    foreach ($data['orders'] as $index => $order) {
        if ($order['id'] === $orderId) {
            $orderIndex = $index;
            break;
        }
    }
    
    if ($orderIndex === null) {
        jsonResponse(['error' => 'Order not found'], 404);
    }
    
    // Санітізація даних
    $input = sanitizeData($input);
    
    // Оновлюємо замовлення
    $updatedOrder = $data['orders'][$orderIndex];
    
    // Дозволені поля для оновлення
    $allowedFields = ['status', 'notes', 'assignedManager', 'paymentInfo', 'deliveryInfo', 'priority'];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            if ($field === 'paymentInfo' || $field === 'deliveryInfo') {
                // Оновлюємо вкладені об'єкти
                foreach ($input[$field] as $key => $value) {
                    $updatedOrder[$field][$key] = $value;
                }
            } else {
                $updatedOrder[$field] = $input[$field];
            }
        }
    }
    
    // Валідація статусу
    if (isset($input['status'])) {
        $allowedStatuses = ['new', 'processing', 'completed', 'cancelled'];
        if (!in_array($input['status'], $allowedStatuses)) {
            jsonResponse(['error' => 'Invalid status'], 400);
        }
        
        // Встановлюємо дату завершення
        if ($input['status'] === 'completed' && !isset($updatedOrder['completedAt'])) {
            $updatedOrder['completedAt'] = date('c');
        } elseif ($input['status'] === 'cancelled' && !isset($updatedOrder['cancelledAt'])) {
            $updatedOrder['cancelledAt'] = date('c');
            $updatedOrder['cancelReason'] = $input['cancelReason'] ?? 'Не вказано';
        }
    }
    
    $updatedOrder['updatedAt'] = date('c');
    
    // Зберігаємо оновлене замовлення
    $data['orders'][$orderIndex] = $updatedOrder;
    
    // Оновлюємо статистику
    updateOrderStatistics($data);
    
    if (!writeJsonFile(ORDERS_DB, $data)) {
        jsonResponse(['error' => 'Failed to update order'], 500);
    }
    
    logAction("Order updated: ID {$orderId}, Status: {$updatedOrder['status']}");
    
    jsonResponse([
        'success' => true,
        'message' => 'Order updated successfully',
        'order' => $updatedOrder
    ]);
}

/**
 * Обробка DELETE запитів (видалення замовлення)
 */
function handleDelete() {
    if (!isset($_GET['id'])) {
        jsonResponse(['error' => 'Order ID is required'], 400);
    }
    
    $orderId = (int)$_GET['id'];
    
    // Читаємо поточні дані
    $data = readJsonFile(ORDERS_DB);
    if (isset($data['error'])) {
        jsonResponse($data, 500);
    }
    
    // Знаходимо та видаляємо замовлення
    $orderFound = false;
    $orderNumber = '';
    
    foreach ($data['orders'] as $index => $order) {
        if ($order['id'] === $orderId) {
            $orderNumber = $order['orderNumber'];
            unset($data['orders'][$index]);
            $orderFound = true;
            break;
        }
    }
    
    if (!$orderFound) {
        jsonResponse(['error' => 'Order not found'], 404);
    }
    
    // Переіндексуємо масив
    $data['orders'] = array_values($data['orders']);
    
    // Оновлюємо статистику
    updateOrderStatistics($data);
    
    if (!writeJsonFile(ORDERS_DB, $data)) {
        jsonResponse(['error' => 'Failed to delete order'], 500);
    }
    
    logAction("Order deleted: ID {$orderId}, Number: {$orderNumber}");
    
    jsonResponse([
        'success' => true,
        'message' => 'Order deleted successfully',
        'deletedId' => $orderId
    ]);
}

/**
 * Оновлення статистики замовлень
 */
function updateOrderStatistics(&$data) {
    $orders = $data['orders'];
    
    $statistics = [
        'total' => count($orders),
        'new' => 0,
        'processing' => 0,
        'completed' => 0,
        'cancelled' => 0,
        'totalRevenue' => 0,
        'averageOrderValue' => 0
    ];
    
    foreach ($orders as $order) {
        // Підрахунок по статусах
        if (isset($statistics[$order['status']])) {
            $statistics[$order['status']]++;
        }
        
        // Підрахунок доходу тільки для завершених замовлень
        if ($order['status'] === 'completed') {
            $statistics['totalRevenue'] += $order['orderSummary']['total'];
        }
    }
    
    // Розрахунок середньої суми замовлення
    if ($statistics['completed'] > 0) {
        $statistics['averageOrderValue'] = $statistics['totalRevenue'] / $statistics['completed'];
    }
    
    $data['orderStatistics'] = $statistics;
    $data['lastUpdated'] = date('c');
}

?>
