# Новая логика индексирования

## Обзор изменений

Исправлена логика индексирования полей в Elasticsearch. Теперь пакет правильно обрабатывает:
- Простые поля (обычные и translatable)
- Relation поля (одиночные и множественные)
- Вложенные relations
- Многоязычные поля

## Логика обработки полей

### 1. Простые поля

**Конфигурация:**
```php
'searchable_fields' => [
    'title', 'description', 'is_active', 'created_at'
],
'translatable' => [
    'locales' => ['en', 'lv'],
    'translatable_fields' => ['title', 'description'],
],
```

**Данные в БД:**
```php
$record = [
    'title' => '{"en":"Product Name","lv":"Produkta nosaukums"}',
    'description' => '{"en":"Product description","lv":"Produkta apraksts"}',
    'is_active' => true,
    'created_at' => '2024-01-01 00:00:00',
];
```

**Результат в Elasticsearch:**
```json
{
    "title_en": "Product Name",
    "title_lv": "Produkta nosaukums",
    "description_en": "Product description", 
    "description_lv": "Produkta apraksts",
    "is_active": true,
    "created_at": "2024-01-01 00:00:00"
}
```

### 2. Relation поля

**Конфигурация:**
```php
'searchable_fields' => [
    'title', 'description',
    'category' => ['title', 'description', 'is_active'],
    'brand' => ['name', 'slug', 'logo'],
],
'translatable' => [
    'locales' => ['en', 'lv'],
    'translatable_fields' => [
        'title', 'description',
        'category' => ['title', 'description'],
        'brand' => ['name'],
    ],
],
```

**Данные в БД:**
```php
$record = [
    'title' => '{"en":"Product Name","lv":"Produkta nosaukums"}',
    'category' => [
        'title' => '{"en":"Electronics","lv":"Elektronika"}',
        'description' => '{"en":"Electronics category","lv":"Elektronikas kategorija"}',
        'is_active' => true,
    ],
    'brand' => [
        'name' => '{"en":"Apple Inc","lv":"Apple Inc"}',
        'slug' => 'apple',
        'logo' => 'apple-logo.png',
    ],
];
```

**Результат в Elasticsearch:**
```json
{
    "title_en": "Product Name",
    "title_lv": "Produkta nosaukums",
    "category.title_en": "Electronics",
    "category.title_lv": "Elektronika", 
    "category.description_en": "Electronics category",
    "category.description_lv": "Elektronikas kategorija",
    "category.is_active": true,
    "brand.name_en": "Apple Inc",
    "brand.name_lv": "Apple Inc",
    "brand.slug": "apple",
    "brand.logo": "apple-logo.png"
}
```

### 3. Вложенные relations

**Конфигурация:**
```php
'searchable_fields' => [
    'title',
    'category' => [
        'title', 'is_active',
        'manufacturer' => ['name', 'code'],
    ],
],
'translatable' => [
    'locales' => ['en', 'lv'],
    'translatable_fields' => [
        'title',
        'category' => [
            'title',
            'manufacturer' => ['name'],
        ],
    ],
],
```

**Данные в БД:**
```php
$record = [
    'title' => '{"en":"Product Name","lv":"Produkta nosaukums"}',
    'category' => [
        'title' => '{"en":"Electronics","lv":"Elektronika"}',
        'is_active' => true,
        'manufacturer' => [
            'name' => '{"en":"Apple Inc","lv":"Apple Inc"}',
            'code' => 'APPLE',
        ],
    ],
];
```

**Результат в Elasticsearch:**
```json
{
    "title_en": "Product Name",
    "title_lv": "Produkta nosaukums",
    "category.title_en": "Electronics",
    "category.title_lv": "Elektronika",
    "category.is_active": true,
    "category.manufacturer.name_en": "Apple Inc",
    "category.manufacturer.name_lv": "Apple Inc", 
    "category.manufacturer.code": "APPLE"
}
```

### 4. Множественные relations (коллекции)

**Конфигурация:**
```php
'searchable_fields' => [
    'title',
    'images' => ['url', 'alt'],
],
'translatable' => [
    'locales' => ['en', 'lv'],
    'translatable_fields' => [
        'title',
        'images' => ['alt'],
    ],
],
```

**Данные в БД:**
```php
$record = [
    'title' => '{"en":"Product Name","lv":"Produkta nosaukums"}',
    'images' => [
        [
            'url' => 'image1.jpg',
            'alt' => '{"en":"Image 1","lv":"Attēls 1"}',
        ],
        [
            'url' => 'image2.jpg', 
            'alt' => '{"en":"Image 2","lv":"Attēls 2"}',
        ],
    ],
];
```

**Результат в Elasticsearch:**
```json
{
    "title_en": "Product Name",
    "title_lv": "Produkta nosaukums",
    "images.url": "image1.jpg image2.jpg",
    "images.alt_en": "Image 1 Image 2",
    "images.alt_lv": "Attēls 1 Attēls 2"
}
```

## Структура конфигурации

### Правильная структура searchable_fields

```php
'searchable_fields' => [
    // Простые поля (числовые ключи)
    'title', 'description', 'is_active', 'created_at',
    
    // Relation поля (строковые ключи с массивами)
    'category' => [
        'title', 'description', 'is_active',
        'manufacturer' => ['name', 'code']  // Вложенные relations
    ],
    'brand' => ['name', 'slug', 'logo'],
    
    // Множественные relations
    'images' => ['url', 'alt'],
],
```

### Правильная структура translatable_fields

```php
'translatable_fields' => [
    // Простые translatable поля
    'title', 'description',
    
    // Translatable поля в relations
    'category' => [
        'title', 'description',
        'manufacturer' => ['name']  // Вложенные relations
    ],
    'brand' => ['name'],
    
    // Translatable поля в множественных relations
    'images' => ['alt'],
],
```

## Исправленные проблемы

1. **Дублирование в конфигурации**: Убрано дублирование `category` в `searchable_fields`
2. **Неправильная обработка структуры**: Исправлена логика обработки числовых и строковых ключей
3. **Translatable поля в relations**: Добавлена поддержка translatable полей в relations
4. **Множественные relations**: Исправлена обработка коллекций с translatable полями
5. **Вложенные relations**: Добавлена поддержка многоуровневых relations

## Примеры использования

### Простая модель

```php
'App\\Models\\Product' => [
    'index' => 'products',
    'translatable' => [
        'locales' => ['en', 'lv'],
        'translatable_fields' => ['title', 'description'],
    ],
    'searchable_fields' => [
        'title', 'description', 'is_active', 'price',
    ],
],
```

### Модель с relations

```php
'App\\Models\\Product' => [
    'index' => 'products',
    'translatable' => [
        'locales' => ['en', 'lv'],
        'translatable_fields' => [
            'title', 'description',
            'category' => ['title', 'description'],
            'brand' => ['name'],
        ],
    ],
    'searchable_fields' => [
        'title', 'description', 'is_active', 'price',
        'category' => ['title', 'description', 'is_active'],
        'brand' => ['name', 'slug', 'logo'],
    ],
],
```

### Сложная модель с вложенными relations

```php
'App\\Models\\Product' => [
    'index' => 'products',
    'translatable' => [
        'locales' => ['en', 'lv'],
        'translatable_fields' => [
            'title', 'description',
            'category' => [
                'title', 'description',
                'manufacturer' => ['name'],
            ],
        ],
    ],
    'searchable_fields' => [
        'title', 'description', 'is_active', 'price',
        'category' => [
            'title', 'description', 'is_active',
            'manufacturer' => ['name', 'code'],
        ],
        'images' => ['url', 'alt'],
    ],
],
```

## Команды для тестирования

```bash
# Создание индексов
php artisan elastic:index --create-only

# Индексация данных
php artisan elastic:index --model="App\\Models\\Product"

# Переиндексация
php artisan elastic:index --reindex --model="App\\Models\\Product"
```

## Проверка результатов

После индексации в Elasticsearch должны появиться поля:

- Простые поля: `title_en`, `title_lv`, `is_active`
- Relation поля: `category.title_en`, `category.title_lv`, `category.is_active`
- Вложенные relations: `category.manufacturer.name_en`, `category.manufacturer.code`
- Множественные relations: `images.url`, `images.alt_en`, `images.alt_lv` 