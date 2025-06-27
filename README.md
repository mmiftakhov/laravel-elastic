# Laravel Elastic

Laravel пакет для упрощенной интеграции с Elasticsearch. Предоставляет команды для индексации и ре-индексации таблиц/моделей, а также поиск по ним.

## Установка

1. Установите пакет через Composer:

```bash
composer require maratmiftahov/laravel-elastic
```

2. Опубликуйте конфигурационный файл:

```bash
php artisan vendor:publish --tag=config
```

## Обновление пакета

При обновлении пакета, если вы хотите получить новые настройки конфигурации:

```bash
# Принудительно обновить конфигурацию (перезапишет ваши изменения!)
php artisan vendor:publish --tag=config --force

# Или сначала сделайте резервную копию вашего config/elastic.php
```

**Внимание**: Использование `--force` перезапишет все ваши локальные изменения в конфигурации!

## Конфигурация

После публикации конфигурации, файл `config/elastic.php` будет создан в вашем приложении. Вы можете настроить подключение к Elasticsearch:

```php
return [
    'hosts' => [
        env('ELASTICSEARCH_HOST', 'localhost:9200')
    ],
    'username' => env('ELASTICSEARCH_USERNAME'),
    'password' => env('ELASTICSEARCH_PASSWORD'),
    // Другие настройки...
];
```

## Использование

### Команды

Пакет предоставляет следующие Artisan команды:

- `php artisan elastic:index` - Индексация моделей

### Индексация моделей

Для индексации моделей используйте команду:

```bash
php artisan elastic:index
```

## Лицензия

MIT License