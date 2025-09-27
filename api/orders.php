<?php
/**
 * МАЛЯРНИЙ Orders API
 * Покращений API для роботи з замовленнями
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
    $paymentStatus = $_GET['payment_status'] ?? '';
    $deliveryMethod = $_GET['delivery_method'] ?? '';
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $minAmount = floatval($_GET['min_amount'] ?? 0);
    $maxAmount = floatval($_GET['max_amount'] ?? 0);
    $manager = $_GET['manager'] ?? '';
    $limit = (int)($_GET['limit'] ?? 0);
    $offset = (int)($_GET['offset'] ?? 0);
    
    $orders = $data['orders'] ?? [];
    
    // Фільтрація по пошуковому терміну
    if (!empty($searchTerm)) {
        $orders = array_filter($orders, function($order) use ($searchTerm) {
            $searchIn = [
                $order['orderNumber'] ?? '',
                $order['customerInfo']['name'] ?? $order['customerName'] ?? '',
                $order['customerInfo']['phone'] ?? $order['phone'] ?? '',
                $order['customerInfo']['email'] ?? $order['email'] ?? '',
                $order['notes'] ?? ''
            ];
            
            return stripos(implode(' ', $searchIn), $searchTerm) !== false;
        });
    }
    
    // Фільтрація по статусу замовлення
    if (!empty($status)) {
        $orders = array_filter($orders, function($order) use ($status) {
            return ($order['status'] ?? '') === $status;
        });
    }
    
    // Фільтрація по статусу оплати
    if (!empty($paymentStatus)) {
        $orders = array_filter($orders, function($order) use ($paymentStatus) {
            return ($order['paymentInfo']['status'] ?? '') === $paymentStatus;
        });
    }
    
    // Фільтрація по способу доставки
    if (!empty($deliveryMethod)) {
        $orders = array_filter($orders, function($order) use ($deliveryMethod) {
            return ($order['deliveryInfo']['method'] ?? '') === $deliveryMethod;
        });
    }
    
    // Фільтрація по менеджеру
    if (!empty($manager)) {
        $orders = array_filter($orders, function($order) use ($manager) {
            return stripos($order['assignedManager'] ?? '', $manager) !== false;
        });
    }
    
    // Фільтрація по даті
    if (!empty($dateFrom)) {
        $orders = array_filter($orders, function($order) use ($dateFrom) {
            $orderDate = $order['createdAt'] ?? '';
            return $orderDate >= $dateFrom;
        });
    }
    
    if (!empty($dateTo)) {
        $orders = array_filter($orders, function($order) use ($dateTo) {
            $orderDate = $order['createdAt'] ?? '';
            return $orderDate <= $dateTo;
        });
    }
    
    // Фільтрація по сумі замовлення
    if ($minAmount > 0) {
        $orders = array_filter($orders, function($order) use ($minAmount) {
            $total = $order['orderSummary']['total'] ?? $order['total'] ?? 0;
            return floatval($total) >= $minAmount;
        });
    }
    
    if ($maxAmount > 0) {
        $orders = array_filter($orders, function($order) use ($maxAmount) {
            $total = $order['orderSummary']['total'] ?? $order['total'] ?? 0;
            return floatval($total) <= $maxAmount;
        });
    }
    
    // Сортування
    $sortBy = $_GET['sort'] ?? 'createdAt';
    $sortOrder = $_GET['order'] ?? 'desc';
    
    usort($orders, function($a, $b) use ($sortBy, $sortOrder) {
        $aValue = $a[$sortBy] ?? '';
        $bValue = $b[$sortBy] ?? '';
        
        // Спеціальна обробка для дат
        if (in_array($sortBy, ['createdAt', 'updatedAt', 'completedAt'])) {
            $aValue = strtotime($aValue);
            $bValue = strtotime($bValue);
        } elseif ($sortBy === 'total') {
            $aValue = floatval($a['orderSummary']['total'] ?? $a['total'] ?? 0);
            $bValue = floatval($b['orderSummary']['total'] ?? $b['total'] ?? 0);
        }
        
        if ($aValue == $bValue) return 0;
        
        $result = ($aValue < $bValue) ? -1 : 1;
        return $sortOrder === 'desc' ? -$result : $result;
    });
    
    // Підрахунок статистики
    $totalOrders = count($orders);
    
    // Пагінація
    if ($limit > 0) {
        $orders = array_slice($orders, $offset, $limit);
    }
    
    // Перевіряємо чи запитуються дані одного замовлення
    if (isset($_GET['id'])) {
        $orderId = (int)$_GET['id'];
        $order = array_filter($orders, function($o) use ($orderId) {
            return ($o['id'] ?? 0) === $orderId;
        });
        
        if (empty($order)) {
            jsonResponse(['error' => 'Order not found'], 404);
        }
        
        jsonResponse([
            'success' => true,
            'order' => array_values($order)[0]
        ]);
    }
    
    // Пошук по номеру замовлення
    if (isset($_GET['number'])) {
        $orderNumber = $_GET['number'];
        $order = array_filter($orders, function($o) use ($orderNumber) {
            return ($o['orderNumber'] ?? '') === $orderNumber;
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
        'pagination' => [
            'total' => $totalOrders,
            'count' => count($orders),
            'offset' => $offset,
            'limit' => $limit,
            'hasMore' => ($limit > 0) && (($offset + $limit) < $totalOrders)
        ],
        'filters' => [
            'search' => $searchTerm,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'delivery_method' => $deliveryMethod,
            'manager' => $manager,
            'date_range' => [
                'from' => $dateFrom,
                'to' => $dateTo
            ],
            'amount_range' => [
                'min' => $minAmount,
                'max' => $maxAmount
            ]
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
    
    // Додаткова валідація
    $validationErrors = [];
    
    // Валідація телефону
    if (!validatePhone($input['phone'])) {
        $validationErrors[] = 'Invalid phone number format';
    }
    
    // Валідація email (якщо вказаний)
    if (!empty($input['email']) && !validateEmail($input['email'])) {
        $validationErrors[] = 'Invalid email format';
    }
    
    // Валідація товарів
    if (!is_array($input['items']) || empty($input['items'])) {
        $validationErrors[] = 'Items must be a non-empty array';
    } else {
        foreach ($input['items'] as $index => $item) {
            if (empty($item['name'])) {
                $validationErrors[] = "Item {$index}: name is required";
            }
            if (!isset($item['quantity']) || !is_numeric($item['quantity']) || $item['quantity'] <= 0) {
                $validationErrors[] = "Item {$index}: quantity must be positive number";
            }
            if (!isset($item['unitPrice']) || !is_numeric($item['unitPrice']) || $item['unitPrice'] <= 0) {
                $validationErrors[] = "Item {$index}: unitPrice must be positive number";
            }
        }
    }
    
    if (!empty($validationErrors)) {
        jsonResponse(['error' => 'Validation failed', 'details' => $validationErrors], 400);
    }
    
    // Санітізація даних
    $input = sanitizeData($input);
    
    // Нормалізація телефону
    $input['phone'] = normalizePhone($input['phone']);
    
    // Читаємо поточні дані
    $data = readJsonFile(ORDERS_DB);
    if (isset($data['error'])) {
        jsonResponse($data, 500);
    }
    
    // Розрахунок сум
    $subtotal = 0;
    $processedItems = [];
    
    foreach ($input['items'] as $item) {
        $quantity = floatval($item['quantity']);
        $unitPrice = floatval($item['unitPrice']);
        $totalPrice = $quantity * $unitPrice;
        $subtotal += $totalPrice;
        
        $processedItems[] = [
            'productId' => $item['productId'] ?? null,
            'name' => $item['name'],
            'brand' => $item['brand'] ?? '',
            'package' => $item['package'] ?? '',
            'quantity' => $quantity,
            'unitPrice' => $unitPrice,
            'totalPrice' => $totalPrice,
            'notes' => $item['notes'] ?? ''
        ];
    }
    
    $delivery = floatval($input['delivery'] ?? 0);
    $discount = floatval($input['discount'] ?? 0);
    $tax = floatval($input['tax'] ?? 0);
    $total = $subtotal + $delivery + $tax - $discount;
    
    // Генеруємо номер замовлення
    $orderNumber = generateOrderNumber($data['orders'] ?? []);
    
    // Створюємо нове замовлення
    $newOrder = [
        'id' => (int)generateId(),
        'orderNumber' => $orderNumber,
        'customerInfo' => [
            'name' => $input['customerName'],
            'phone' => $input['phone'],
            'email' => $input['email'] ?? '',
            'address' => $input['address'] ?? '',
            'company' => $input['company'] ?? '',
            'taxId' => $input['taxId'] ?? ''
        ],
        'items' => $processedItems,
        'orderSummary' => [
            'subtotal' => $subtotal,
            'delivery' => $delivery,
            'tax' => $tax,
            'discount' => $discount,
            'total' => $total,
            'currency' => 'UAH'
        ],
        'paymentInfo' => [
            'method' => $input['paymentMethod'] ?? 'cash',
            'status' => 'pending',
            'paidAmount' => 0.00,
            'paymentDate' => null,
            'transactionId' => null
        ],
        'deliveryInfo' => [
            'method' => $input['deliveryMethod'] ?? 'courier',
            'address' => $input['deliveryAddress'] ?? $input['address'] ?? '',
            'preferredDate' => $input['preferredDate'] ?? '',
            'preferredTime' => $input['preferredTime'] ?? '',
            'instructions' => $input['deliveryInstructions'] ?? '',
            'trackingNumber' => null,
            'estimatedDelivery' => null
        ],
        'status' => 'new',
        'priority' => $input['priority'] ?? 'normal',
        'source' => $input['source'] ?? 'website',
        'notes' => $input['notes'] ?? '',
        'internalNotes' => '',
        'tags' => $input['tags'] ?? [],
        'metadata' => [
            'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'ip' => getClientIP(),
            'referrer' => $_SERVER['HTTP_REFERER'] ?? '',
            'utm' => [
                'source' => $input['utm_source'] ?? '',
                'medium' => $input['utm_medium'] ?? '',
                'campaign' => $input['utm_campaign'] ?? ''
            ]
        ],
        'timeline' => [
            [
                'status' => 'new',
                'timestamp' => date('c'),
                'note' => 'Замовлення створено',
                'user' => 'system'
            ]
        ],
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
        'assignedManager' => null
    ];
    
    // Додаємо замовлення до масиву
    if (!isset($data['orders'])) {
        $data['orders'] = [];
    }
    $data['orders'][] = $newOrder;
    
    // Оновлюємо статистику
    updateOrderStatistics($data);
    
    // Зберігаємо дані
    if (!writeJsonFile(ORDERS_DB, $data)) {
        jsonResponse(['error' => 'Failed to save order'], 500);
    }
    
    // Відправляємо сповіщення (електронна пошта, SMS)
    sendOrderNotifications($newOrder);
    
    logAction("Order created: ID {$newOrder['id']}, Number: {$newOrder['orderNumber']}, Total: {$newOrder['orderSummary']['total']} UAH");
    
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
        if (($order['id'] ?? 0) === $orderId) {
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
    $oldStatus = $updatedOrder['status'] ?? 'new';
    
    // Дозволені поля для оновлення
    $allowedFields = [
        'status', 'priority', 'assignedManager', 'notes', 'internalNotes', 'tags'
    ];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            if ($field === 'status') {
                $allowedStatuses = ['new', 'confirmed', 'processing', 'shipped', 'delivered', 'completed', 'cancelled'];
                if (!in_array($input[$field], $allowedStatuses)) {
                    jsonResponse(['error' => 'Invalid status'], 400);
                }
            } elseif ($field === 'priority') {
                $allowedPriorities = ['low', 'normal', 'high', 'urgent'];
                if (!in_array($input[$field], $allowedPriorities)) {
                    jsonResponse(['error' => 'Invalid priority'], 400);
                }
            }
            
            $updatedOrder[$field] = $input[$field];
        }
    }
    
    // Оновлення інформації про оплату
    if (isset($input['paymentInfo'])) {
        foreach ($input['paymentInfo'] as $key => $value) {
            if (in_array($key, ['status', 'paidAmount', 'paymentDate', 'transactionId'])) {
                $updatedOrder['paymentInfo'][$key] = $value;
                
                if ($key === 'status' && $value === 'paid') {
                    $updatedOrder['paymentInfo']['paymentDate'] = date('c');
                }
            }
        }
    }
    
    // Оновлення інформації про доставку
    if (isset($input['deliveryInfo'])) {
        foreach ($input['deliveryInfo'] as $key => $value) {
            if (in_array($key, ['trackingNumber', 'estimatedDelivery', 'deliveredAt', 'receivedBy'])) {
                $updatedOrder['deliveryInfo'][$key] = $value;
            }
        }
    }
    
    // Додавання запису в timeline при зміні статусу
    if (isset($input['status']) && $input['status'] !== $oldStatus) {
        $statusNames = [
            'new' => 'Нове замовлення',
            'confirmed' => 'Підтверджено',
            'processing' => 'В обробці',
            'shipped' => 'Відправлено',
            'delivered' => 'Доставлено',
            'completed' => 'Завершено',
            'cancelled' => 'Скасовано'
        ];
        
        $updatedOrder['timeline'][] = [
            'status' => $input['status'],
            'timestamp' => date('c'),
            'note' => $statusNames[$input['status']] ?? $input['status'],
            'user' => $input['user'] ?? 'admin'
        ];
        
        // Встановлюємо спеціальні дати
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
    
    // Відправляємо сповіщення про зміну статусу
    if (isset($input['status']) && $input['status'] !== $oldStatus) {
        sendStatusUpdateNotification($updatedOrder, $oldStatus, $input['status']);
    }
    
    logAction("Order updated: ID {$orderId}, Status: {$updatedOrder['status']}, Total: {$updatedOrder['orderSummary']['total']} UAH");
    
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
        if (($order['id'] ?? 0) === $orderId) {
            $orderNumber = $order['orderNumber'] ?? '';
            
            // Перевіряємо чи можна видаляти замовлення
            if (in_array($order['status'] ?? '', ['processing', 'shipped', 'delivered'])) {
                jsonResponse(['error' => 'Cannot delete order in current status'], 400);
            }
            
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
    $orders = $data['orders'] ?? [];
    
    $statistics = [
        'total' => count($orders),
        'new' => 0,
        'confirmed' => 0,
        'processing' => 0,
        'shipped' => 0,
        'delivered' => 0,
        'completed' => 0,
        'cancelled' => 0,
        'totalRevenue' => 0,
        'paidRevenue' => 0,
        'averageOrderValue' => 0,
        'topProducts' => [],
        'paymentMethods' => [],
        'deliveryMethods' => [],
        'monthlyStats' => []
    ];
    
    $productStats = [];
    $paymentStats = [];
    $deliveryStats = [];
    $monthlyRevenue = [];
    
    foreach ($orders as $order) {
        $status = $order['status'] ?? 'new';
        $total = floatval($order['orderSummary']['total'] ?? $order['total'] ?? 0);
        $paymentStatus = $order['paymentInfo']['status'] ?? 'pending';
        $paymentMethod = $order['paymentInfo']['method'] ?? 'cash';
        $deliveryMethod = $order['deliveryInfo']['method'] ?? 'courier';
        $orderDate = date('Y-m', strtotime($order['createdAt'] ?? 'now'));
        
        // Підрахунок по статусах
        if (isset($statistics[$status])) {
            $statistics[$status]++;
        }
        
        // Підрахунок доходу
        if (in_array($status, ['completed', 'delivered'])) {
            $statistics['totalRevenue'] += $total;
        }
        
        if ($paymentStatus === 'paid') {
            $statistics['paidRevenue'] += $total;
        }
        
        // Статистика товарів
        foreach ($order['items'] ?? [] as $item) {
            $productName = $item['name'] ?? 'Unknown';
            $quantity = floatval($item['quantity'] ?? 0);
            
            if (!isset($productStats[$productName])) {
                $productStats[$productName] = ['quantity' => 0, 'revenue' => 0];
            }
            
            $productStats[$productName]['quantity'] += $quantity;
            $productStats[$productName]['revenue'] += floatval($item['totalPrice'] ?? 0);
        }
        
        // Статистика методів оплати
        if (!isset($paymentStats[$paymentMethod])) {
            $paymentStats[$paymentMethod] = 0;
        }
        $paymentStats[$paymentMethod]++;
        
        // Статистика методів доставки
        if (!isset($deliveryStats[$deliveryMethod])) {
            $deliveryStats[$deliveryMethod] = 0;
        }
        $deliveryStats[$deliveryMethod]++;
        
        // Місячна статистика
        if (!isset($monthlyRevenue[$orderDate])) {
            $monthlyRevenue[$orderDate] = ['orders' => 0, 'revenue' => 0];
        }
        $monthlyRevenue[$orderDate]['orders']++;
        if (in_array($status, ['completed', 'delivered'])) {
            $monthlyRevenue[$orderDate]['revenue'] += $total;
        }
    }
    
    // Топ товари
    arsort($productStats);
    $statistics['topProducts'] = array_slice($productStats, 0, 10, true);
    
    // Методи оплати
    $statistics['paymentMethods'] = $paymentStats;
    
    // Методи доставки
    $statistics['deliveryMethods'] = $deliveryStats;
    
    // Місячна статистика
    ksort($monthlyRevenue);
    $statistics['monthlyStats'] = $monthlyRevenue;
    
    // Середня сума замовлення
    if ($statistics['completed'] > 0) {
        $statistics['averageOrderValue'] = $statistics['totalRevenue'] / $statistics['completed'];
    }
    
    $data['orderStatistics'] = $statistics;
    $data['lastUpdated'] = date('c');
}

/**
 * Генерація номера замовлення
 */
function generateOrderNumber($existingOrders) {
    $year = date('Y');
    $maxNumber = 0;
    
    // Знаходимо найбільший номер за поточний рік
    foreach ($existingOrders as $order) {
        $orderNumber = $order['orderNumber'] ?? '';
        if (preg_match("/PY-{$year}-(\d+)/", $orderNumber, $matches)) {
            $maxNumber = max($maxNumber, intval($matches[1]));
        }
    }
    
    $nextNumber = $maxNumber + 1;
    return "PY-{$year}-" . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
}

/**
 * Відправка сповіщень про замовлення
 */
function sendOrderNotifications($order) {
    // Тут буде логіка відправки email та SMS
    // Поки що тільки логуємо
    logAction("Order notifications sent for order: {$order['orderNumber']}");
    
    // TODO: Інтеграція з email сервісом та SMS API
    // sendEmail($order['customerInfo']['email'], 'order-confirmation', $order);
    // sendSMS($order['customerInfo']['phone'], $order);
}

/**
 * Відправка сповіщення про зміну статусу
 */
function sendStatusUpdateNotification($order, $oldStatus, $newStatus) {
    logAction("Status update notification sent: Order {$order['orderNumber']} changed from {$oldStatus} to {$newStatus}");
    
    // TODO: Відправка сповіщень клієнту
}

// Додаткові ендпойнти
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'statistics':
            $data = readJsonFile(ORDERS_DB);
            if (isset($data['error'])) {
                jsonResponse($data, 500);
            }
            
            jsonResponse([
                'success' => true,
                'statistics' => $data['orderStatistics'] ?? []
            ]);
            break;
            
        case 'export':
            // Експорт замовлень у CSV або Excel
            $data = readJsonFile(ORDERS_DB);
            if (isset($data['error'])) {
                jsonResponse($data, 500);
            }
            
            $format = $_GET['format'] ?? 'csv';
            exportOrders($data['orders'] ?? [], $format);
            break;
            
        default:
            jsonResponse(['error' => 'Unknown action'], 400);
    }
}

/**
 * Експорт замовлень
 */
function exportOrders($orders, $format) {
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="orders-' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // BOM для правильного відображення українських символів в Excel
        fwrite($output, "\xEF\xBB\xBF");
        
        // Заголовки
        fputcsv($output, [
            'Номер замовлення',
            'Дата створення',
            'Клієнт',
            'Телефон',
            'Email',
            'Статус',
            'Сума',
            'Оплата',
            'Доставка'
        ]);
        
        // Дані
        foreach ($orders as $order) {
            fputcsv($output, [
                $order['orderNumber'] ?? '',
                date('d.m.Y H:i', strtotime($order['createdAt'] ?? 'now')),
                $order['customerInfo']['name'] ?? $order['customerName'] ?? '',
                $order['customerInfo']['phone'] ?? $order['phone'] ?? '',
                $order['customerInfo']['email'] ?? $order['email'] ?? '',
                $order['status'] ?? '',
                number_format($order['orderSummary']['total'] ?? $order['total'] ?? 0, 2, ',', ' ') . ' грн',
                $order['paymentInfo']['status'] ?? '',
                $order['deliveryInfo']['method'] ?? ''
            ]);
        }
        
        fclose($output);
        exit;
    } else {
        jsonResponse(['error' => 'Unsupported export format'], 400);
    }
}

?>
