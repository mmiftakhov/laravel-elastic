# Laravel Elastic

Пакет для упрощения интеграции Elasticsearch в Laravel проекты. Предоставляет готовые команды для индексации моделей и удобный API для поиска.

## Установка

```bash
composer require maratmiftahov/laravel-elastic
```

## Публикация конфигурации

```bash
php artisan vendor:publish --provider="Maratmiftahov\LaravelElastic\ElasticServiceProvider" --tag="config"
```

## Конфигурация

### Основные настройки

В файле `config/elastic.php` настройте подключение к Elasticsearch:

```php
'hosts' => [
    env('ELASTICSEARCH_HOST_FULL', 'http://localhost:9200'),
],

'connection' => [
    'timeout' => env('ELASTICSEARCH_TIMEOUT', 30),
    'connect_timeout' => env('ELASTICSEARCH_CONNECT_TIMEOUT', 10),
    'retries' => env('ELASTICSEARCH_RETRIES', 3),
],
```

### Настройка моделей

Добавьте модели в секцию `models`:

```php
'models' => [
    'App\\Models\\Product' => [
        'index' => 'products',
        
        // Поля для поиска
        'searchable_fields' => [
            'name' => [
                'type' => 'text',
                'analyzer' => 'russian',
                'boost' => 3.0,
                'fields' => [
                    'exact' => [
                        'type' => 'text',
                        'analyzer' => 'exact_match',
                        'boost' => 5.0,
                    ],
                    'autocomplete' => [
                        'type' => 'text',
                        'analyzer' => 'autocomplete',
                        'boost' => 1.5,
                    ],
                ],
            ],
            'description' => [
                'type' => 'text',
                'analyzer' => 'russian',
                'boost' => 1.0,
            ],
            'category' => [
                'type' => 'keyword',
                'boost' => 2.0,
            ],
        ],
        
        // Поля для хранения
        'stored_fields' => [
            'id', 'name', 'price', 'category', 'is_active',
        ],
        
        // Отношения для загрузки
        'relations' => [
            'images', 'specifications', 'category',
        ],
        
        // Вычисляемые поля
        'computed_fields' => [
            'search_text' => [
                'type' => 'text',
                'analyzer' => 'full_text',
                'source' => ['name', 'description', 'category'],
            ],
            'price_range' => [
                'type' => 'keyword',
                'source' => 'price',
                'transform' => 'price_range',
            ],
        ],
        
        // Условия фильтрации
        'query_conditions' => [
            'where' => [
                'is_active' => true,
                'deleted_at' => null,
            ],
            'where_in' => [
                'status' => ['published', 'approved'],
            ],
        ],
        
        'chunk_size' => 1000,
        'soft_deletes' => true,
    ],
],
```

## Команды

### Индексация

```bash
# Индексация всех моделей
php artisan elastic:index

# Индексация конкретной модели
php artisan elastic:index --model="App\\Models\\Product"

# Принудительная переиндексация
php artisan elastic:index --force

# Только создание индексов без данных
php artisan elastic:index --create-only

# Только удаление индексов
php artisan elastic:index --delete-only

# Переиндексация (удаление + создание + индексация)
php artisan elastic:index --reindex

# Настройка размера чанка
php artisan elastic:index --chunk=500
```

### Поиск

```bash
# Поиск во всех моделях
php artisan elastic:search "iphone"

# Поиск в конкретной модели
php artisan elastic:search "iphone" --model="App\\Models\\Product"

# Ограничение результатов
php artisan elastic:search "iphone" --limit=20 --offset=10

# Поиск в конкретных полях
php artisan elastic:search "iphone" --fields="name,description"

# Использование конкретного анализатора
php artisan elastic:search "iphone" --analyzer="english"
```

## Использование в коде

### Поиск

```php
use Maratmiftahov\LaravelElastic\ElasticSearch;

class ProductController extends Controller
{
    public function search(Request $request, ElasticSearch $elasticSearch)
    {
        $query = $request->get('q');
        
        // Поиск в конкретной модели
        $results = $elasticSearch->search('App\\Models\\Product', $query, [
            'limit' => 20,
            'offset' => 0,
            'sort' => ['_score' => 'desc'],
        ]);
        
        // Получение метаданных
        $total = $results->get('_meta')['total'];
        $maxScore = $results->get('_meta')['max_score'];
        
        // Поиск во всех моделях
        $allResults = $elasticSearch->searchAll($query);
        
        return response()->json([
            'results' => $results->forget('_meta'),
            'meta' => $results->get('_meta'),
        ]);
    }
}
```

### Автодополнение

```php
public function autocomplete(Request $request, ElasticSearch $elasticSearch)
{
    $query = $request->get('q');
    
    $suggestions = $elasticSearch->autocomplete('App\\Models\\Product', $query, [
        'limit' => 10,
    ]);
    
    return response()->json($suggestions);
}
```

## Анализаторы

Пакет поддерживает следующие анализаторы:

### Языковые анализаторы
- `russian` - для русского языка
- `english` - для английского языка  
- `latvian` - для латышского языка

### Специальные анализаторы
- `exact_match` - для точного совпадения
- `partial_match` - для частичного совпадения
- `autocomplete` - для автодополнения
- `full_text` - для поиска по всему тексту

## Трансформации

Поддерживаются следующие трансформации для computed_fields:

- `price_range` - группировка цен по диапазонам
- `popularity_score` - расчет популярности
- `availability_status` - статус доступности

## Настройки поиска

В конфигурации можно настроить параметры поиска:

```php
'search' => [
    'default' => [
        'operator' => 'OR',
        'fuzziness' => 'AUTO',
        'minimum_should_match' => '75%',
    ],
    'autocomplete' => [
        'min_score' => 0.1,
        'max_suggestions' => 10,
    ],
],
```

## Многоязычная поддержка

Для многоязычных проектов можно настроить поля для разных языков:

```php
'searchable_fields' => [
    'name' => [
        'type' => 'text',
        'analyzer' => 'russian',
    ],
    'name_en' => [
        'type' => 'text',
        'analyzer' => 'english',
    ],
    'name_lv' => [
        'type' => 'text',
        'analyzer' => 'latvian',
    ],
],
```

### Приоритет полей (Boost)

⚠️ **Важно**: В Elasticsearch 8.x `boost` в маппингах индекса устарел и удален. Приоритет полей должен применяться только в поисковых запросах:

```php
// Правильное использование boost в поисковых запросах
$results = $elasticSearch->search('App\\Models\\Product', $query, [
    'boost' => [
        'name' => 3.0,           // Высокий приоритет для названия
        'name.exact' => 5.0,     // Очень высокий приоритет для точного совпадения
        'description' => 1.0,    // Базовый приоритет для описания
    ],
    'boost_mode' => 'multiply',  // Режим применения boost
    'score_mode' => 'sum',       // Режим подсчета скора
]);
```

## Логирование

Включите логирование для отладки:

```php
'logging' => [
    'enabled' => env('ELASTICSEARCH_LOGGING', false),
    'level' => env('ELASTICSEARCH_LOG_LEVEL', 'info'),
],
```

## Требования

- PHP 8.1+
- Laravel 10.0+ или 11.0+
- Elasticsearch 7.0+

## Лицензия

MIT License