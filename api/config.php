<?php
/**
 * МАЛЯРНИЙ API Configuration
 * Конфігурація API для роботи з базами даних
 */

// Заборона прямого доступу
if (!defined('API_ACCESS')) {
    die('Direct access forbidden');
}

// Основні налаштування
define('API_VERSION', '1.0');

// Шляхи до файлів баз даних
define('DB_PATH', '../database/');
define('BACKUP_PATH', '../database/backup/');

// Файли баз даних
define('PRODUCTS_DB', DB_PATH . 'products.json');
define('ARTICLES_DB', DB_PATH . 'articles.json');
define('ORDERS_DB', DB_PATH . 'orders.json');
define('SETTINGS_DB', DB_PATH . 'settings.json');

// Налаштування безпеки
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD', 'malyarnyj2024'); // В реальному проекті використовуйте хешування!
define('SESSION_TIMEOUT', 3600); // 1 година
define('MAX_LOGIN_ATTEMPTS', 5);

// CORS налаштування
define('ALLOWED_ORIGINS', [
    'http://localhost',
    'http://127.0.0.1',
    'https://malyarnyj.ua',
    'https://www.malyarnyj.ua',
    'file://' // Для локальної розробки
]);

// Налаштування логування
define('LOG_LEVEL', 'info'); // debug, info, warning, error
define('LOG_FILE', '../logs/api.log');

// Налаштування резервного копіювання
define('AUTO_BACKUP', true);
define('BACKUP_FREQUENCY', 'daily'); // hourly, daily, weekly
define('MAX_BACKUPS', 30);

// Функція для читання JSON файлу
function readJsonFile($filePath) {
    if (!file_exists($filePath)) {
        // Створюємо базову структуру файлу якщо його немає
        $defaultStructures = [
            'products.json' => [
                'products' => [],
                'lastUpdated' => date('c'),
                'totalProducts' => 0
            ],
            'articles.json' => [
                'articles' => [],
                'lastUpdated' => date('c'),
                'totalArticles' => 0,
                'publishedArticles' => 0,
                'draftArticles' => 0
            ],
            'orders.json' => [
                'orders' => [],
                'orderStatistics' => [
                    'total' => 0,
                    'new' => 0,
                    'processing' => 0,
                    'completed' => 0,
                    'cancelled' => 0,
                    'totalRevenue' => 0,
                    'averageOrderValue' => 0
                ],
                'lastUpdated' => date('c')
            ]
        ];
        
        $fileName = basename($filePath);
        if (isset($defaultStructures[$fileName])) {
            // Створюємо директорію якщо потрібно
            $dir = dirname($filePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            $defaultData = $defaultStructures[$fileName];
            if (writeJsonFile($filePath, $defaultData)) {
                return $defaultData;
            }
        }
        
        return ['error' => 'File not found and could not be created: ' . $filePath];
    }
    
    $content = file_get_contents($filePath);
    if ($content === false) {
        return ['error' => 'Cannot read file: ' . $filePath];
    }
    
    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Invalid JSON in file: ' . $filePath . ' - ' . json_last_error_msg()];
    }
    
    return $data;
}

// Функція для запису JSON файлу
function writeJsonFile($filePath, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        logAction('Failed to encode JSON for file: ' . $filePath, 'error');
        return false;
    }
    
    // Створюємо директорію якщо потрібно
    $dir = dirname($filePath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    // Створюємо резервну копію перед записом
    if (file_exists($filePath) && AUTO_BACKUP) {
        createBackup($filePath);
    }
    
    $result = file_put_contents($filePath, $json, LOCK_EX);
    
    // Логуємо операцію
    if ($result !== false) {
        logAction('File updated: ' . $filePath);
        return true;
    } else {
        logAction('Failed to update file: ' . $filePath, 'error');
        return false;
    }
}

// Функція створення резервної копії
function createBackup($filePath) {
    if (!file_exists($filePath)) {
        return false;
    }
    
    $fileName = basename($filePath, '.json');
    $timestamp = date('Y-m-d_H-i-s');
    $backupFile = BACKUP_PATH . $fileName . '_backup_' . $timestamp . '.json';
    
    // Створюємо папку backup якщо не існує
    if (!is_dir(BACKUP_PATH)) {
        mkdir(BACKUP_PATH, 0755, true);
    }
    
    $result = copy($filePath, $backupFile);
    
    if ($result) {
        logAction('Backup created: ' . $backupFile);
        cleanOldBackups($fileName);
    } else {
        logAction('Failed to create backup: ' . $backupFile, 'error');
    }
    
    return $result;
}

// Функція очищення старих резервних копій
function cleanOldBackups($fileName) {
    $backupFiles = glob(BACKUP_PATH . $fileName . '_backup_*.json');
    
    if (count($backupFiles) > MAX_BACKUPS) {
        // Сортуємо по даті створення
        array_multisort(array_map('filemtime', $backupFiles), SORT_ASC, $backupFiles);
        
        // Видаляємо найстаріші файли
        $filesToDelete = array_slice($backupFiles, 0, count($backupFiles) - MAX_BACKUPS);
        
        foreach ($filesToDelete as $file) {
            if (unlink($file)) {
                logAction('Old backup deleted: ' . $file);
            }
        }
    }
}

// Функція логування
function logAction($message, $level = 'info') {
    if (!defined('LOG_LEVEL')) return;
    
    $levels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
    $currentLevel = $levels[LOG_LEVEL] ?? 1;
    $messageLevel = $levels[$level] ?? 1;
    
    if ($messageLevel < $currentLevel) return;
    
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    
    // Створюємо папку logs якщо не існує
    $logDir = dirname(LOG_FILE);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    file_put_contents(LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
}

// Функція валідації CORS
function validateCORS() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    
    // Дозволяємо всі локальні запити
    if (strpos($origin, 'http://localhost') === 0 || 
        strpos($origin, 'http://127.0.0.1') === 0 || 
        strpos($origin, 'file://') === 0 ||
        empty($origin)) {
        header('Access-Control-Allow-Origin: *');
    } elseif (in_array($origin, ALLOWED_ORIGINS)) {
        header('Access-Control-Allow-Origin: ' . $origin);
    } else {
        header('Access-Control-Allow-Origin: *');
    }
    
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Credentials: true');
    
    // Обробка preflight запитів
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

// Функція відповіді JSON
function jsonResponse($data, $httpCode = 200) {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Функція генерації унікального ID
function generateId() {
    return time() . rand(100, 999);
}

// Функція валідації даних
function validateRequired($data, $requiredFields) {
    $errors = [];
    
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
            $errors[] = "Field '{$field}' is required";
        }
    }
    
    return $errors;
}

// Функція санітізації даних
function sanitizeData($data) {
    if (is_array($data)) {
        return array_map('sanitizeData', $data);
    }
    
    if (is_string($data)) {
        return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
    }
    
    return $data;
}

// Функція валідації email
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Функція валідації телефону (українські номери)
function validatePhone($phone) {
    // Очищуємо номер від всіх символів крім цифр і +
    $cleanPhone = preg_replace('/[^\d+]/', '', $phone);
    
    // Перевіряємо формати українських номерів
    $patterns = [
        '/^\+380\d{9}$/',           // +380xxxxxxxxx
        '/^380\d{9}$/',             // 380xxxxxxxxx  
        '/^0\d{9}$/'                // 0xxxxxxxxx
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $cleanPhone)) {
            return true;
        }
    }
    
    return false;
}

// Функція нормалізації телефонного номера
function normalizePhone($phone) {
    $cleanPhone = preg_replace('/[^\d+]/', '', $phone);
    
    // Приводимо до формату +380xxxxxxxxx
    if (preg_match('/^0(\d{9})$/', $cleanPhone, $matches)) {
        return '+380' . $matches[1];
    } elseif (preg_match('/^380(\d{9})$/', $cleanPhone, $matches)) {
        return '+380' . $matches[1];
    } elseif (preg_match('/^\+380\d{9}$/', $cleanPhone)) {
        return $cleanPhone;
    }
    
    return $phone; // Повертаємо оригінал якщо не вдалося нормалізувати
}

// Функція створення slug з тексту
function createSlug($text) {
    // Таблиця транслітерації українських символів
    $transliteration = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 
        'е' => 'e', 'є' => 'ye', 'ж' => 'zh', 'з' => 'z', 'и' => 'y', 'і' => 'i', 
        'ї' => 'yi', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 
        'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 
        'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 
        'щ' => 'shch', 'ь' => '', 'ю' => 'yu', 'я' => 'ya',
        'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'H', 'Ґ' => 'G', 'Д' => 'D',
        'Е' => 'E', 'Є' => 'Ye', 'Ж' => 'Zh', 'З' => 'Z', 'И' => 'Y', 'І' => 'I',
        'Ї' => 'Yi', 'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N',
        'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T', 'У' => 'U',
        'Ф' => 'F', 'Х' => 'Kh', 'Ц' => 'Ts', 'Ч' => 'Ch', 'Ш' => 'Sh',
        'Щ' => 'Shch', 'Ь' => '', 'Ю' => 'Yu', 'Я' => 'Ya'
    ];
    
    // Транслітеруємо
    $slug = strtr($text, $transliteration);
    
    // Приводимо до нижнього регістру
    $slug = mb_strtolower($slug, 'UTF-8');
    
    // Замінюємо всі не-алфавітно-цифрові символи на дефіси
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    
    // Видаляємо повторювані дефіси
    $slug = preg_replace('/-+/', '-', $slug);
    
    // Очищуємо дефіси з початку і кінця
    $slug = trim($slug, '-');
    
    return $slug;
}

// Функція перевірки прав доступу (заглушка для майбутнього розвитку)
function checkAccess($resource, $action = 'read') {
    // В майбутньому тут буде повноцінна система прав доступу
    return true;
}

// Функція отримання IP адреси клієнта
function getClientIP() {
    $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    
    foreach ($ipKeys as $key) {
        if (!empty($_SERVER[$key])) {
            $ips = explode(',', $_SERVER[$key]);
            $ip = trim($ips[0]);
            
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

// Автоматичне налаштування при включенні файлу
validateCORS();

// Налаштування PHP для JSON
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');

// Налаштування часової зони
date_default_timezone_set('Europe/Kiev');

// Логуємо початок роботи API
logAction('API initialized - ' . $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' from ' . getClientIP());

?>
