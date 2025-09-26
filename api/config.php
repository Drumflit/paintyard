<?php
/**
 * PaintYard API Configuration
 * Конфігурація API для роботи з базами даних
 */

// Заборона прямого доступу
if (!defined('API_ACCESS')) {
    die('Direct access forbidden');
}

// Основні налаштування
define('API_VERSION', '1.0');
define('API_ACCESS', true);

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
define('ADMIN_PASSWORD', 'paintyard2024'); // В реальному проекті використовуйте хешування!
define('SESSION_TIMEOUT', 3600); // 1 година
define('MAX_LOGIN_ATTEMPTS', 5);

// CORS налаштування
define('ALLOWED_ORIGINS', [
    'http://localhost',
    'http://127.0.0.1',
    'https://paintyard.ua',
    'https://www.paintyard.ua'
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
        return ['error' => 'File not found: ' . $filePath];
    }
    
    $content = file_get_contents($filePath);
    if ($content === false) {
        return ['error' => 'Cannot read file: ' . $filePath];
    }
    
    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Invalid JSON in file: ' . $filePath];
    }
    
    return $data;
}

// Функція для запису JSON файлу
function writeJsonFile($filePath, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
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
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    if (in_array($origin, ALLOWED_ORIGINS)) {
        header('Access-Control-Allow-Origin: ' . $origin);
    }
    
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
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
        if (!isset($data[$field]) || empty($data[$field])) {
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
    
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

// Автоматичне налаштування при включенні файлу
validateCORS();

// Налаштування PHP для JSON
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');

// Логуємо початок роботи API
logAction('API initialized - ' . $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI']);

?>