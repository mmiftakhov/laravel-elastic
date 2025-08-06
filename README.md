# Laravel Elastic Package

Пакет для интеграции Elasticsearch с Laravel, который упрощает индексацию моделей и поиск по ним.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/maratmiftahov/laravel-elastic.svg)](https://packagist.org/packages/maratmiftahov/laravel-elastic)
[![Total Downloads](https://img.shields.io/packagist/dt/maratmiftahov/laravel-elastic.svg)](https://packagist.org/packages/maratmiftahov/laravel-elastic)
[![License](https://img.shields.io/packagist/l/maratmiftahov/laravel-elastic.svg)](https://packagist.org/packages/maratmiftahov/laravel-elastic)

## 🚀 Новое в версии 0.2.4

- ✅ **Исправлена логика индексирования** - теперь все поля корректно индексируются
- ✅ **Полная поддержка translatable полей в relations** - `category.title_en`, `category.title_lv`
- ✅ **Поддержка вложенных relations** - `category.manufacturer.name_en`
- ✅ **Поддержка множественных relations** - `images.alt_en`, `images.alt_lv`
- ✅ **Автоматическое определение translatable полей** в любой структуре
- 🔧 **Улучшения конфигурации** - исправлена структура searchable_fields

[Подробнее об изменениях →](CHANGELOG.md#024---2024-12-19)

## Установка

```bash
composer require maratmiftahov/laravel-elastic
```

## Конфигурация

Опубликуйте конфигурационный файл:

```bash
php artisan vendor:publish --provider="Maratmiftahov\LaravelElastic\ElasticServiceProvider"
```

## Настройка моделей

В файле `config/elastic.php` настройте модели для индексации:

```php
'models' => [
    'App\\Models\\Product' => [
        'index' => 'products',
        
        // Настройки translatable полей
        'translatable' => [
            'locales' => ['en', 'lv'],
            'fallback_locale' => 'en',
            'index_localized_fields' => true,
            'auto_detect_translatable' => true,
            'translatable_fields' => [
                'title', 'slug', 'description',
                'category' => ['title', 'description'],
                'brand' => ['name', 'description'],
            ],
        ],
        
        // Поля для поиска
        'searchable_fields' => [
            // Простые поля модели
            'title', 'slug', 'description', 'is_active',
            
            // Поля из relations
            'category' => [
                'title', 'slug', 'is_active',
                'manufacturer' => ['name', 'code']
            ],
            'brand' => ['name', 'slug', 'logo'],
            
            // Поля из коллекций
            'images' => ['url', 'alt'],
        ],
        
        // Поля для возврата после поиска
        'return_fields' => [
            'id', 'title', 'slug', 'is_active',
            'category' => ['id', 'title', 'slug'],
            'brand' => ['id', 'name', 'logo'],
        ],
        
        // Вычисляемые поля
        'computed_fields' => [
            'search_text' => [
                'type' => 'text',
                'analyzer' => 'standard',
                'source' => ['title', 'title_en', 'title_lv', 'description'],
            ],
        ],
        
        'chunk_size' => 1000,
    ],
],
```

## Использование

### Индексация

```bash
# Индексация всех моделей
php artisan elastic:index

# Индексация конкретной модели
php artisan elastic:index --model="App\\Models\\Product"

# Переиндексация (удаление + создание + индексация)
php artisan elastic:index --reindex

# Только создание индексов без данных
php artisan elastic:index --create-only

# Только удаление индексов
php artisan elastic:index --delete-only

# Настройка размера чанка
php artisan elastic:index --chunk=500
```

### Поиск

```bash
# Поиск по всем индексированным моделям
php artisan elastic:search "поисковый запрос"

# Поиск с фильтрами
php artisan elastic:search "запрос" --filters="category:electronics,price:100-1000"
```

## Логика индексирования

### Простые поля

- **Обычные поля**: индексируются как есть (`title`, `is_active`)
- **Translatable поля**: создаются поля для каждого языка (`title_en`, `title_lv`)

### Relation поля

- **Обычные relation поля**: `category.title`, `brand.name`
- **Translatable relation поля**: `category.title_en`, `category.title_lv`
- **Вложенные relations**: `category.manufacturer.name`, `category.manufacturer.name_en`

### Множественные relations (коллекции)

- **Обычные**: объединяются в одно поле (`images.url`)
- **Translatable**: создаются поля для каждого языка (`images.url_en`, `images.url_lv`)

## Поддерживаемые типы данных

- **Text**: текстовые поля с анализом
- **Keyword**: точные совпадения
- **Number**: числовые поля
- **Date**: даты
- **Boolean**: логические значения

## Анализаторы

Пакет поддерживает различные анализаторы для разных языков:

- `english` - для английского языка
- `latvian` - для латышского языка  
- `russian` - для русского языка
- `standard` - стандартный анализатор

## Требования

- PHP 8.0+
- Laravel 8.0+
- Elasticsearch 8.18+
- elasticsearch/elasticsearch ^8.18