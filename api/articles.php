<?php
/**
 * МАЛЯРНИЙ Articles API
 * Покращений API для роботи зі статтями
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
    $author = $_GET['author'] ?? '';
    $tag = $_GET['tag'] ?? '';
    $dateFrom = $_GET['date_from'] ?? '';
    $dateTo = $_GET['date_to'] ?? '';
    $limit = (int)($_GET['limit'] ?? 0);
    $offset = (int)($_GET['offset'] ?? 0);
    $featured = $_GET['featured'] ?? '';
    
    $articles = $data['articles'] ?? [];
    
    // Фільтрація по пошуковому терміну
    if (!empty($searchTerm)) {
        $articles = array_filter($articles, function($article) use ($searchTerm) {
            $searchIn = [
                $article['title'] ?? '',
                $article['excerpt'] ?? '',
                $article['content'] ?? '',
                $article['author'] ?? '',
                implode(' ', $article['tags'] ?? [])
            ];
            
            return stripos(implode(' ', $searchIn), $searchTerm) !== false;
        });
    }
    
    // Фільтрація по статусу
    if (!empty($status)) {
        $articles = array_filter($articles, function($article) use ($status) {
            return ($article['status'] ?? '') === $status;
        });
    }
    
    // Фільтрація по автору
    if (!empty($author)) {
        $articles = array_filter($articles, function($article) use ($author) {
            return stripos($article['author'] ?? '', $author) !== false;
        });
    }
    
    // Фільтрація по тегу
    if (!empty($tag)) {
        $articles = array_filter($articles, function($article) use ($tag) {
            $tags = $article['tags'] ?? [];
            return in_array($tag, $tags) || array_filter($tags, function($t) use ($tag) {
                return stripos($t, $tag) !== false;
            });
        });
    }
    
    // Фільтрація по даті
    if (!empty($dateFrom)) {
        $articles = array_filter($articles, function($article) use ($dateFrom) {
            $articleDate = $article['publishedAt'] ?? $article['createdAt'] ?? '';
            return $articleDate >= $dateFrom;
        });
    }
    
    if (!empty($dateTo)) {
        $articles = array_filter($articles, function($article) use ($dateTo) {
            $articleDate = $article['publishedAt'] ?? $article['createdAt'] ?? '';
            return $articleDate <= $dateTo;
        });
    }
    
    // Фільтрація рекомендованих статей
    if ($featured === 'true') {
        $articles = array_filter($articles, function($article) {
            return ($article['featured'] ?? false) === true;
        });
    }
    
    // Сортування
    $sortBy = $_GET['sort'] ?? 'publishedAt';
    $sortOrder = $_GET['order'] ?? 'desc';
    
    usort($articles, function($a, $b) use ($sortBy, $sortOrder) {
        $aValue = $a[$sortBy] ?? $a['createdAt'] ?? '';
        $bValue = $b[$sortBy] ?? $b['createdAt'] ?? '';
        
        // Спеціальна обробка для дат
        if (in_array($sortBy, ['createdAt', 'updatedAt', 'publishedAt'])) {
            $aValue = strtotime($aValue);
            $bValue = strtotime($bValue);
        } elseif ($sortBy === 'views' || $sortBy === 'likes') {
            $aValue = intval($aValue);
            $bValue = intval($bValue);
        }
        
        if ($aValue == $bValue) return 0;
        
        $result = ($aValue < $bValue) ? -1 : 1;
        return $sortOrder === 'desc' ? -$result : $result;
    });
    
    // Підрахунок статистики
    $totalArticles = count($articles);
    $publishedCount = count(array_filter($articles, function($a) {
        return ($a['status'] ?? '') === 'published';
    }));
    
    // Пагінація
    if ($limit > 0) {
        $articles = array_slice($articles, $offset, $limit);
    }
    
    // Перевіряємо чи запитуються дані однієї статті
    if (isset($_GET['id'])) {
        $articleId = (int)$_GET['id'];
        $article = array_filter($articles, function($a) use ($articleId) {
            return ($a['id'] ?? 0) === $articleId;
        });
        
        if (empty($article)) {
            jsonResponse(['error' => 'Article not found'], 404);
        }
        
        $foundArticle = array_values($article)[0];
        
        // Збільшуємо лічильник переглядів
        incrementArticleViews($articleId);
        $foundArticle['views'] = ($foundArticle['views'] ?? 0) + 1;
        
        jsonResponse([
            'success' => true,
            'article' => $foundArticle
        ]);
    }
    
    // Отримання статей по slug
    if (isset($_GET['slug'])) {
        $slug = $_GET['slug'];
        $article = array_filter($articles, function($a) use ($slug) {
            return ($a['slug'] ?? '') === $slug;
        });
        
        if (empty($article)) {
            jsonResponse(['error' => 'Article not found'], 404);
        }
        
        $foundArticle = array_values($article)[0];
        
        // Збільшуємо лічильник переглядів
        incrementArticleViews($foundArticle['id']);
        $foundArticle['views'] = ($foundArticle['views'] ?? 0) + 1;
        
        jsonResponse([
            'success' => true,
            'article' => $foundArticle
        ]);
    }
    
    jsonResponse([
        'success' => true,
        'articles' => array_values($articles),
        'pagination' => [
            'total' => $totalArticles,
            'count' => count($articles),
            'offset' => $offset,
            'limit' => $limit,
            'hasMore' => ($limit > 0) && (($offset + $limit) < $totalArticles)
        ],
        'statistics' => [
            'total' => $totalArticles,
            'published' => $publishedCount,
            'draft' => $totalArticles - $publishedCount
        ],
        'filters' => [
            'search' => $searchTerm,
            'status' => $status,
            'author' => $author,
            'tag' => $tag,
            'date_range' => [
                'from' => $dateFrom,
                'to' => $dateTo
            ],
            'featured' => $featured
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
    $requiredFields = ['title', 'content'];
    $errors = validateRequired($input, $requiredFields);
    
    if (!empty($errors)) {
        jsonResponse(['error' => 'Validation failed', 'details' => $errors], 400);
    }
    
    // Додаткова валідація
    $validationErrors = [];
    
    // Валідація статусу
    $allowedStatuses = ['published', 'draft'];
    if (isset($input['status']) && !in_array($input['status'], $allowedStatuses)) {
        $validationErrors[] = 'Invalid status. Allowed: ' . implode(', ', $allowedStatuses);
    }
    
    // Валідація довжини заголовку
    if (strlen($input['title']) > 200) {
        $validationErrors[] = 'Title is too long (max 200 characters)';
    }
    
    // Валідація довжини контенту
    if (strlen($input['content']) < 100) {
        $validationErrors[] = 'Content is too short (min 100 characters)';
    }
    
    if (!empty($validationErrors)) {
        jsonResponse(['error' => 'Validation failed', 'details' => $validationErrors], 400);
    }
    
    // Санітізація даних
    $input = sanitizeData($input);
    
    // Читаємо поточні дані
    $data = readJsonFile(ARTICLES_DB);
    if (isset($data['error'])) {
        jsonResponse($data, 500);
    }
    
    // Генеруємо slug з заголовку
    $slug = createSlug($input['title']);
    
    // Перевіряємо унікальність slug
    $existingSlug = array_filter($data['articles'] ?? [], function($a) use ($slug) {
        return ($a['slug'] ?? '') === $slug;
    });
    
    if (!empty($existingSlug)) {
        $slug .= '-' . time();
    }
    
    // Створюємо нову статтю
    $status = $input['status'] ?? 'draft';
    $newArticle = [
        'id' => (int)generateId(),
        'title' => $input['title'],
        'slug' => $slug,
        'excerpt' => $input['excerpt'] ?? generateExcerpt($input['content']),
        'content' => $input['content'],
        'image' => $input['image'] ?? '',
        'author' => $input['author'] ?? 'Експерт МАЛЯРНИЙ',
        'status' => $status,
        'featured' => $input['featured'] ?? false,
        'tags' => is_array($input['tags'] ?? null) ? $input['tags'] : 
                 (is_string($input['tags']) ? explode(',', $input['tags']) : []),
        'category' => $input['category'] ?? 'general',
        'readingTime' => calculateReadingTime($input['content']),
        'views' => 0,
        'likes' => 0,
        'seo' => [
            'title' => $input['seo_title'] ?? $input['title'],
            'description' => $input['seo_description'] ?? generateExcerpt($input['content']),
            'keywords' => $input['seo_keywords'] ?? []
        ],
        'metadata' => [
            'source' => $input['source'] ?? 'internal',
            'language' => 'uk',
            'version' => 1
        ],
        'createdAt' => date('c'),
        'updatedAt' => date('c'),
        'publishedAt' => $status === 'published' ? date('c') : null
    ];
    
    // Очищуємо порожні теги
    $newArticle['tags'] = array_filter(array_map('trim', $newArticle['tags']));
    
    // Додаємо статтю до масиву
    if (!isset($data['articles'])) {
        $data['articles'] = [];
    }
    $data['articles'][] = $newArticle;
    
    // Оновлюємо метадані
    updateArticlesMetadata($data);
    
    // Зберігаємо дані
    if (!writeJsonFile(ARTICLES_DB, $data)) {
        jsonResponse(['error' => 'Failed to save article'], 500);
    }
    
    logAction("Article added: ID {$newArticle['id']}, Title: {$newArticle['title']}, Status: {$newArticle['status']}");
    
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
        if (($article['id'] ?? 0) === $articleId) {
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
    $originalStatus = $updatedArticle['status'] ?? 'draft';
    
    // Дозволені поля для оновлення
    $allowedFields = [
        'title', 'content', 'excerpt', 'status', 'image', 'author', 'tags', 
        'category', 'featured', 'seo_title', 'seo_description', 'seo_keywords'
    ];
    
    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            if ($field === 'tags') {
                $updatedArticle[$field] = is_array($input[$field]) ? $input[$field] : 
                                        (is_string($input[$field]) ? explode(',', $input[$field]) : []);
                $updatedArticle[$field] = array_filter(array_map('trim', $updatedArticle[$field]));
            } elseif ($field === 'status') {
                $allowedStatuses = ['published', 'draft'];
                if (!in_array($input[$field], $allowedStatuses)) {
                    jsonResponse(['error' => 'Invalid status'], 400);
                }
                $updatedArticle[$field] = $input[$field];
            } elseif (strpos($field, 'seo_') === 0) {
                // Обробка SEO полів
                $seoField = str_replace('seo_', '', $field);
                if (!isset($updatedArticle['seo'])) {
                    $updatedArticle['seo'] = [];
                }
                $updatedArticle['seo'][$seoField] = $input[$field];
            } else {
                $updatedArticle[$field] = $input[$field];
            }
        }
    }
    
    // Оновлюємо slug якщо змінився заголовок
    if (isset($input['title'])) {
        $newSlug = createSlug($input['title']);
        
        // Перевіряємо унікальність нового slug
        $existingSlug = array_filter($data['articles'], function($a) use ($newSlug, $articleId) {
            return ($a['slug'] ?? '') === $newSlug && ($a['id'] ?? 0) !== $articleId;
        });
        
        if (!empty($existingSlug)) {
            $newSlug .= '-' . time();
        }
        
        $updatedArticle['slug'] = $newSlug;
    }
    
    // Оновлюємо час читання якщо змінився контент
    if (isset($input['content'])) {
        $updatedArticle['readingTime'] = calculateReadingTime($input['content']);
        
        // Автоматично генеруємо excerpt якщо він не заданий
        if (empty($updatedArticle['excerpt'])) {
            $updatedArticle['excerpt'] = generateExcerpt($input['content']);
        }
    }
    
    // Оновлюємо дату публікації
    if (isset($input['status'])) {
        if ($input['status'] === 'published' && $originalStatus !== 'published') {
            $updatedArticle['publishedAt'] = date('c');
        } elseif ($input['status'] === 'draft') {
            $updatedArticle['publishedAt'] = null;
        }
    }
    
    // Збільшуємо версію
    $updatedArticle['metadata']['version'] = ($updatedArticle['metadata']['version'] ?? 1) + 1;
    $updatedArticle['updatedAt'] = date('c');
    
    // Зберігаємо оновлену статтю
    $data['articles'][$articleIndex] = $updatedArticle;
    
    // Оновлюємо метадані
    updateArticlesMetadata($data);
    
    if (!writeJsonFile(ARTICLES_DB, $data)) {
        jsonResponse(['error' => 'Failed to update article'], 500);
    }
    
    logAction("Article updated: ID {$articleId}, Title: {$updatedArticle['title']}, Status: {$updatedArticle['status']}");
    
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
        if (($article['id'] ?? 0) === $articleId) {
            $articleTitle = $article['title'] ?? '';
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
    
    // Оновлюємо метадані
    updateArticlesMetadata($data);
    
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
 * Оновлення метаданих статей
 */
function updateArticlesMetadata(&$data) {
    $articles = $data['articles'] ?? [];
    
    $statistics = [
        'total' => count($articles),
        'published' => 0,
        'draft' => 0,
        'totalViews' => 0,
        'totalLikes' => 0,
        'authors' => [],
        'tags' => [],
        'categories' => []
    ];
    
    foreach ($articles as $article) {
        // Підрахунок по статусах
        if (($article['status'] ?? '') === 'published') {
            $statistics['published']++;
        } else {
            $statistics['draft']++;
        }
        
        // Підрахунок переглядів та лайків
        $statistics['totalViews'] += intval($article['views'] ?? 0);
        $statistics['totalLikes'] += intval($article['likes'] ?? 0);
        
        // Збір авторів
        $author = $article['author'] ?? 'Unknown';
        if (!in_array($author, $statistics['authors'])) {
            $statistics['authors'][] = $author;
        }
        
        // Збір тегів
        $tags = $article['tags'] ?? [];
        foreach ($tags as $tag) {
            if (!in_array($tag, $statistics['tags'])) {
                $statistics['tags'][] = $tag;
            }
        }
        
        // Збір категорій
        $category = $article['category'] ?? 'general';
        if (!in_array($category, $statistics['categories'])) {
            $statistics['categories'][] = $category;
        }
    }
    
    $data['statistics'] = $statistics;
    $data['lastUpdated'] = date('c');
    $data['totalArticles'] = $statistics['total'];
    $data['publishedArticles'] = $statistics['published'];
    $data['draftArticles'] = $statistics['draft'];
}

/**
 * Збільшення лічильника переглядів статті
 */
function incrementArticleViews($articleId) {
    $data = readJsonFile(ARTICLES_DB);
    if (isset($data['error'])) {
        return false;
    }
    
    foreach ($data['articles'] as $index => $article) {
        if (($article['id'] ?? 0) === $articleId) {
            $data['articles'][$index]['views'] = intval($article['views'] ?? 0) + 1;
            writeJsonFile(ARTICLES_DB, $data);
            return true;
        }
    }
    
    return false;
}

/**
 * Генерація витягу зі статті
 */
function generateExcerpt($content, $length = 150) {
    // Видаляємо HTML теги та форматування
    $text = strip_tags($content);
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    
    if (strlen($text) <= $length) {
        return $text;
    }
    
    // Обрізаємо по словах
    $excerpt = substr($text, 0, $length);
    $lastSpace = strrpos($excerpt, ' ');
    
    if ($lastSpace !== false) {
        $excerpt = substr($excerpt, 0, $lastSpace);
    }
    
    return $excerpt . '...';
}

/**
 * Розрахунок часу читання
 */
function calculateReadingTime($content) {
    $wordsPerMinute = 200; // Середня швидкість читання українською
    $words = str_word_count(strip_tags($content));
    return max(1, ceil($words / $wordsPerMinute));
}

/**
 * Лайк статті
 */
function likeArticle($articleId) {
    $data = readJsonFile(ARTICLES_DB);
    if (isset($data['error'])) {
        return false;
    }
    
    foreach ($data['articles'] as $index => $article) {
        if (($article['id'] ?? 0) === $articleId) {
            $data['articles'][$index]['likes'] = intval($article['likes'] ?? 0) + 1;
            writeJsonFile(ARTICLES_DB, $data);
            return $data['articles'][$index]['likes'];
        }
    }
    
    return false;
}

// Додаткові ендпойнти для лайків та переглядів
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'like':
            if (isset($_GET['id'])) {
                $likes = likeArticle((int)$_GET['id']);
                if ($likes !== false) {
                    jsonResponse(['success' => true, 'likes' => $likes]);
                } else {
                    jsonResponse(['error' => 'Article not found'], 404);
                }
            } else {
                jsonResponse(['error' => 'Article ID required'], 400);
            }
            break;
            
        case 'view':
            if (isset($_GET['id'])) {
                $success = incrementArticleViews((int)$_GET['id']);
                if ($success) {
                    jsonResponse(['success' => true]);
                } else {
                    jsonResponse(['error' => 'Article not found'], 404);
                }
            } else {
                jsonResponse(['error' => 'Article ID required'], 400);
            }
            break;
            
        default:
            jsonResponse(['error' => 'Unknown action'], 400);
    }
}

?>
