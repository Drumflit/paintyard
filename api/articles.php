<?php
/**
 * МАЛЯРНИЙ Articles API
 * API для роботи зі статтями
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
 * Обробка GET запитів (отримання статей)
 */
function handleGet() {
    $data = readJsonFile(ARTICLES_DB);
    
    if (isset($data['error'])) {
        jsonResponse($data, 500);
    }
    
    // Параметри пошуку та фільтрації
    $searchTerm = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? '';
    $limit = (int)($_GET['limit'] ?? 0);
    $offset = (int)($_GET['offset'] ?? 0);
    
    $articles = $data['articles'];
    
    // Фільтрація по пошуковому терміну
    if (!empty($searchTerm)) {
        $articles = array_filter($articles, function($article) use ($searchTerm) {
            return stripos($article['title'], $searchTerm) !== false ||
                   stripos($article['content'], $searchTerm) !== false;
        });
    }
    
    // Фільтрація по статусу
    if (!empty($status)) {
        $articles = array_filter($articles, function($article) use ($status) {
            return $article['status'] === $status;
        });
    }
    
    // Сортування (за замовчуванням по даті створення)
    $sortBy = $_GET['sort'] ?? 'createdAt';
    $sortOrder = $_GET['order'] ?? 'desc';
    
    usort($articles, function($a, $b) use ($sortBy, $sortOrder) {
        $aValue = $a[$sortBy] ?? '';
        $bValue = $b[$sortBy] ?? '';
        
        $comparison = strcmp($aValue, $bValue);
        
        return $sortOrder === 'desc' ? -$comparison : $comparison;
    });
    
    // Пагінація
    $totalArticles = count($articles);
    if ($limit > 0) {
        $articles = array_slice($articles, $offset, $limit);
    }
    
    // Перевіряємо чи запитуються дані однієї статті
    if (isset($_GET['id'])) {
        $articleId = (int)$_GET['id'];
        $article = array_filter($articles, function($a) use ($articleId) {
            return $a['id'] === $articleId;
        });
        
        if (empty($article)) {
            jsonResponse(['error' => 'Article not found'], 404);
        }
        
        jsonResponse([
            'success' => true,
            'article' => array_values($article)[0]
        ]);
    }
    
    jsonResponse([
        'success' => true,
        'articles' => array_values($articles),
        'total' => $totalArticles,
        'count' => count($articles),
        'filters' => [
            'search' => $searchTerm,
            'status' => $status
        ]
    ]);
}

/**
 * Обробка POST запитів (додавання статті)
 */
function handlePost($input) {
    if (!$input) {
        jsonResponse(['error' => 'Invalid input data'], 400);
    }
    
    // Валідація обов'язкових полів
    $requiredFields = ['title', 'content', 'status'];
    $errors = validateRequired($input, $requiredFields);
    
    if (!empty($errors)) {
        jsonResponse(['error' => 'Validation failed', 'details' => $errors], 400);
    }
    
    // Санітізація даних
    $input = sanitizeData($input);
    
    // Додаткова валідація
    if (!in_array($input['status'], ['published', 'draft'])) {
        jsonResponse(['error' => 'Invalid status. Allowed: published, draft'], 400);
    }
    
    // Читаємо поточні дані
    $data = readJsonFile(ARTICLES_DB);
    if (isset($data['error'])) {
        jsonResponse($data, 500);
    }
    
    // Генеруємо slug з заголовку
    $slug = generateSlug($input['title']);
    
    // Створюємо нову статтю
    $newArticle = [
        'id' => (int)generateId(),
        'title' => $input['title'],
        'slug' => $slug,
        'excerpt' => $input['excerpt'] ?? substr($input['content'], 0, 150) . '...',
        'content' => $input['content'],
        'image' => $input['image'] ?? '',
        'author' => $input['author'] ?? 'Експерт МАЛЯРНИЙ',
        'status' => $input['status'],
        'tags' => $input['tags'] ?? [],
        'views' => 0,
        'likes' => 0,
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
        'publishedAt' => $input['status'] === 'published' ? date('c') : null
    ];
    
    // Додаємо статтю до масиву
    $data['articles'][] = $newArticle;
    $data['totalArticles'] = count($data['articles']);
    $data['publishedArticles'] = count(array_filter($data['articles'], function($a) {
        return $a['status'] === 'published';
    }));
    $data['draftArticles'] = count(array_filter($data['articles'], function($a) {
        return $a['status'] === 'draft';
    }));
    $data['lastUpdated'] = date('c');
    
    // Зберігаємо дані
    if (!writeJsonFile(ARTICLES_DB, $data)) {
        jsonResponse(['error' => 'Failed to save article'], 500);
    }
    
    logAction("Article added: ID {$newArticle['id']}, Title: {$newArticle['title']}");
    
    jsonResponse([
        'success' => true,
        'message' => 'Article added successfully',
        'article' => $newArticle
    ], 201);
}

/**
 * Обробка PUT запитів (оновлення статті)
 */
function handlePut($input) {
    if (!isset($_GET['id'])) {
        jsonResponse(['error' => 'Article ID is required'], 400);
    }
    
    $articleId = (int)$_GET['id'];
    
    if (!$input) {
        jsonResponse(['error' => 'Invalid input data'], 400);
    }
    
    // Читаємо поточні дані
    $data = readJsonFile(ARTICLES_DB);
    if (isset($data['error'])) {
        jsonResponse($data, 500);
    }
    
    // Знаходимо статтю
    $articleIndex = null;
    foreach ($data['articles'] as $index => $article) {
        if ($article['id'] === $articleId) {
            $articleIndex = $index;
            break;
        }
    }
    
    if ($articleIndex === null) {
        jsonResponse(['error' => 'Article not found'], 404);
    }
    
    // Санітізація даних
    $input = sanitizeData($input);
    
    // Оновлюємо статтю
    $updatedArticle = $data['articles'][$articleIndex];
    
    // Оновлюємо тільки передані поля
    $allowedFields = ['title', 'content', 'excerpt', 'status', 'image', 'author', 'tags'];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updatedArticle[$field] = $input[$field];
        }
    }
    
    // Оновлюємо slug якщо змінився заголовок
    if (isset($input['title'])) {
        $updatedArticle['slug'] = generateSlug($input['title']);
    }
    
    // Валідація оновлених даних
    if (isset($input['status']) && !in_array($input['status'], ['published', 'draft'])) {
        jsonResponse(['error' => 'Invalid status. Allowed: published, draft'], 400);
    }
    
    // Оновлюємо дату публікації
    if (isset($input['status'])) {
        if ($input['status'] === 'published' && $updatedArticle['publishedAt'] === null) {
            $updatedArticle['publishedAt'] = date('c');
        } elseif ($input['status'] === 'draft') {
            $updatedArticle['publishedAt'] = null;
        }
    }
    
    $updatedArticle['updatedAt'] = date('c');
    
    // Зберігаємо оновлену статтю
    $data['articles'][$articleIndex] = $updatedArticle;
    $data['publishedArticles'] = count(array_filter($data['articles'], function($a) {
        return $a['status'] === 'published';
    }));
    $data['draftArticles'] = count(array_filter($data['articles'], function($a) {
        return $a['status'] === 'draft';
    }));
    $data['lastUpdated'] = date('c');
    
    if (!writeJsonFile(ARTICLES_DB, $data)) {
        jsonResponse(['error' => 'Failed to update article'], 500);
    }
    
    logAction("Article updated: ID {$articleId}, Title: {$updatedArticle['title']}");
    
    jsonResponse([
        'success' => true,
        'message' => 'Article updated successfully',
        'article' => $updatedArticle
    ]);
}

/**
 * Обробка DELETE запитів (видалення статті)
 */
function handleDelete() {
    if (!isset($_GET['id'])) {
        jsonResponse(['error' => 'Article ID is required'], 400);
    }
    
    $articleId = (int)$_GET['id'];
    
    // Читаємо поточні дані
    $data = readJsonFile(ARTICLES_DB);
    if (isset($data['error'])) {
        jsonResponse($data, 500);
    }
    
    // Знаходимо та видаляємо статтю
    $articleFound = false;
    $articleTitle = '';
    
    foreach ($data['articles'] as $index => $article) {
        if ($article['id'] === $articleId) {
            $articleTitle = $article['title'];
            unset($data['articles'][$index]);
            $articleFound = true;
            break;
        }
    }
    
    if (!$articleFound) {
        jsonResponse(['error' => 'Article not found'], 404);
    }
    
    // Переіндексуємо масив
    $data['articles'] = array_values($data['articles']);
    $data['totalArticles'] = count($data['articles']);
    $data['publishedArticles'] = count(array_filter($data['articles'], function($a) {
        return $a['status'] === 'published';
    }));
    $data['draftArticles'] = count(array_filter($data['articles'], function($a) {
        return $a['status'] === 'draft';
    }));
    $data['lastUpdated'] = date('c');
    
    if (!writeJsonFile(ARTICLES_DB, $data)) {
        jsonResponse(['error' => 'Failed to delete article'], 500);
    }
    
    logAction("Article deleted: ID {$articleId}, Title: {$articleTitle}");
    
    jsonResponse([
        'success' => true,
        'message' => 'Article deleted successfully',
        'deletedId' => $articleId
    ]);
}

/**
 * Генерація slug з заголовку
 */
function generateSlug($title) {
    // Транслітерація українських символів
    $transliteration = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ye', 'ж' => 'zh', 'з' => 'z',
        'и' => 'y', 'і' => 'i', 'ї' => 'yi', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p',
        'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh',
        'щ' => 'shch', 'ь' => '', 'ю' => 'yu', 'я' => 'ya'
    ];
    
    $slug = mb_strtolower($title, 'UTF-8');
    $slug = strtr($slug, $transliteration);
    $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');
    
    return $slug;
}

?>
