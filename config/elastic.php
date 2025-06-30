<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Elasticsearch Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your Elasticsearch connection settings.
    | You can specify multiple hosts for high availability.
    |
    */

    'hosts' => [
        env('ELASTICSEARCH_HOST_FULL', 'http://localhost:9200'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Connection Settings
    |--------------------------------------------------------------------------
    |
    | Additional connection settings for the Elasticsearch client.
    |
    */

    'connection' => [
        'timeout' => env('ELASTICSEARCH_TIMEOUT', 30),
        'connect_timeout' => env('ELASTICSEARCH_CONNECT_TIMEOUT', 10),
        'retries' => env('ELASTICSEARCH_RETRIES', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Index Settings
    |--------------------------------------------------------------------------
    |
    | Default index settings for your Elasticsearch indices.
    |
    */

    'index' => [
        'prefix' => env('ELASTICSEARCH_INDEX_PREFIX', ''),
        'number_of_shards' => env('ELASTICSEARCH_NUMBER_OF_SHARDS', 1),
        'number_of_replicas' => env('ELASTICSEARCH_NUMBER_OF_REPLICAS', 0),
        
        // Настройки для zero-downtime reindexing
        'versioning' => [
            'enabled' => true,
            'alias_suffix' => '_current',
            'version_format' => 'v{number}', // v1, v2, v3...
            'keep_old_versions' => 2, // Сколько старых версий хранить
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Analysis Configuration
    |--------------------------------------------------------------------------
    |
    | Настройки анализа текста для Elasticsearch. Анализ текста определяет,
    | как обрабатывать и индексировать текстовые данные для поиска.
    |
    | Документация:
    | - https://www.elastic.co/guide/en/elasticsearch/reference/current/analysis.html
    | - https://www.elastic.co/guide/en/elasticsearch/reference/current/analysis-analyzers.html
    | - https://www.elastic.co/guide/en/elasticsearch/reference/current/analysis-tokenizers.html
    | - https://www.elastic.co/guide/en/elasticsearch/reference/current/analysis-tokenfilters.html
    |
    | Основные компоненты:
    | - analyzer: определяет как разбивать и обрабатывать текст
    | - tokenizer: разбивает текст на токены (слова)
    | - filter: применяет преобразования к токенам
    |
    */

    'analysis' => [
        // Анализаторы - определяют полный процесс обработки текста
        'analyzer' => [
            // Стандартный анализатор для английского языка
            'standard' => [
                'type' => 'standard',
                'stopwords' => '_english_', // Встроенный список английских стоп-слов
            ],
            
            // Кастомный анализатор для русского языка
            'russian' => [
                'type' => 'custom',
                'tokenizer' => 'standard', // Разбивает по пробелам и знакам препинания
                'filter' => [
                    'lowercase',           // Приводит к нижнему регистру
                    'russian_stop',        // Удаляет стоп-слова
                    'russian_stemmer',     // Приводит к основе слова
                ],
            ],
            
            // Анализатор для поиска по точному совпадению
            'exact_match' => [
                'type' => 'custom',
                'tokenizer' => 'keyword',  // Обрабатывает весь текст как один токен
                'filter' => ['lowercase'],
            ],
            
            // Анализатор для поиска по частичному совпадению
            'partial_match' => [
                'type' => 'custom',
                'tokenizer' => 'standard',
                'filter' => [
                    'lowercase',
                    'edge_ngram',          // Создает n-граммы для автодополнения
                ],
            ],
        ],
        
        // Токенизаторы - разбивают текст на токены
        'tokenizer' => [
            // Можно добавить кастомные токенизаторы
            'custom_tokenizer' => [
                'type' => 'pattern',
                'pattern' => '[\\s,]+', // Разбивает по пробелам и запятым
            ],
        ],
        
        // Фильтры - применяют преобразования к токенам
        'filter' => [
            // Фильтр для удаления русских стоп-слов
            'russian_stop' => [
                'type' => 'stop',
                'stopwords' => '_russian_', // Встроенный список русских стоп-слов
            ],
            
            // Стеммер для русского языка (приводит к основе слова)
            'russian_stemmer' => [
                'type' => 'stemmer',
                'language' => 'russian',
            ],
            
            // Фильтр для создания n-грамм (для автодополнения)
            'edge_ngram' => [
                'type' => 'edge_ngram',
                'min_gram' => 2,
                'max_gram' => 20,
            ],
            
            // Фильтр для удаления дубликатов
            'unique' => [
                'type' => 'unique',
                'only_on_same_position' => true,
            ],
            
            // Фильтр для нормализации текста
            'word_delimiter' => [
                'type' => 'word_delimiter',
                'generate_word_parts' => true,
                'generate_number_parts' => true,
                'catenate_words' => true,
                'catenate_numbers' => true,
                'catenate_all' => false,
                'split_on_case_change' => true,
                'preserve_original' => false,
                'split_on_numerics' => true,
                'stem_english_possessive' => true,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Indexing Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which models should be indexed and how their data should be
    | structured in Elasticsearch. Each model can have its own indexing rules.
    |
    | Query Conditions:
    | ----------------
    | Используйте 'query_conditions' для фильтрации записей перед индексацией.
    | Это позволяет индексировать только нужные данные (например, только активные записи).
    |
    | Примеры использования:
    |
    | // Только активные записи
    | 'query_conditions' => [
    |     'where' => ['is_active' => true],
    | ],
    |
    | // Только записи с определенными статусами
    | 'query_conditions' => [
    |     'where_in' => ['status' => ['published', 'approved']],
    | ],
    |
    | // Только записи в определенном диапазоне
    | 'query_conditions' => [
    |     'where_between' => ['price' => [100, 10000]],
    | ],
    |
    | // Только записи с активными категориями
    | 'query_conditions' => [
    |     'where_has' => [
    |         'category' => function($query) {
    |             $query->where('is_active', true);
    |         },
    |     ],
    | ],
    |
    | // Сложные условия через замыкание
    | 'query_conditions' => [
    |     'where_callback' => function($query) {
    |         $query->where('stock_quantity', '>', 0)
    |               ->where('expires_at', '>', now());
    |     },
    | ],
    |
    */

    'models' => [
        // Example configuration for a Product model
        'App\\Models\\Product' => [
            // Index name (will be prefixed with the configured prefix)
            'index' => 'products',
            
            // Fields that should be indexed for searching
            'searchable_fields' => [
                'name' => [
                    'type' => 'text',
                    'analyzer' => 'russian',
                ],
                'description' => [
                    'type' => 'text',
                    'analyzer' => 'russian',
                ],
                'category' => [
                    'type' => 'keyword',
                ],
                'brand' => [
                    'type' => 'keyword',
                ],
                'tags' => [
                    'type' => 'keyword',
                ],
                'sku' => [
                    'type' => 'text',
                    'analyzer' => 'exact_match',
                ],
                'search_suggestions' => [
                    'type' => 'text',
                    'analyzer' => 'partial_match',
                ],
            ],
            
            // Fields that should be stored and returned directly from Elasticsearch
            'stored_fields' => [
                'id',
                'name',
                'price',
                'category',
                'brand',
                'is_active',
                'created_at',
            ],
            
            // Fields that should be loaded from the database after search
            'relations' => [
                'images',
                'specifications',
                'reviews',
            ],
            
            // Additional fields to include in the index (computed or transformed)
            'computed_fields' => [
                'search_text' => [
                    'type' => 'text',
                    'analyzer' => 'russian',
                    'source' => ['name', 'description', 'category', 'brand', 'tags'],
                ],
                'price_range' => [
                    'type' => 'keyword',
                    'source' => 'price',
                    'transform' => 'price_range',
                ],
            ],
            
            // Query settings for this model
            'query' => [
                'default_operator' => 'OR',
                'fuzziness' => 'AUTO',
                'minimum_should_match' => '75%',
            ],
            
            // Chunk size for bulk indexing
            'chunk_size' => 1000,
            
            // Whether to use soft deletes for this model
            'soft_deletes' => true,
            
            // Query conditions to filter records before indexing
            // Эти условия применяются к запросам при индексации
            // Позволяют индексировать только нужные записи (например, только активные)
            'query_conditions' => [
                // Базовые условия WHERE для всех запросов
                // Поддерживаются: обычные значения, 'null', 'not_null'
                'where' => [
                    'is_active' => true,        // Только активные записи
                    'deleted_at' => null,       // Исключить мягко удаленные
                    'email_verified_at' => 'not_null', // Только с подтвержденным email
                ],
                
                // Условия для определенных статусов (WHERE IN)
                'where_in' => [
                    'status' => ['published', 'approved'], // Только опубликованные и одобренные
                ],
                
                // Условия для диапазонов (WHERE BETWEEN)
                'where_between' => [
                    'price' => [100, 10000],    // Товары в определенном ценовом диапазоне
                ],
                
                // Условия для отношений (WHERE HAS)
                // Можно передать замыкание или массив условий
                'where_has' => [
                    'category' => function($query) {
                        $query->where('is_active', true);
                    },
                    // Или простое условие:
                    // 'category' => ['is_active' => true],
                ],
                
                // Условия для отсутствия отношений (WHERE DOESN'T HAVE)
                'where_doesnt_have' => [
                    'blocked_reviews', // Исключить товары с заблокированными отзывами
                ],
                
                // Дополнительные условия через замыкание
                // Позволяет использовать любые методы Query Builder
                'where_callback' => function($query) {
                    $query->where('stock_quantity', '>', 0)  // Только товары в наличии
                          ->where('expires_at', '>', now()); // Не истекшие
                },
            ],
            
            // Custom index settings for this specific model (optional)
            'index_settings' => [
                // Можно добавить кастомные настройки для индекса
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Enable logging for Elasticsearch operations.
    |
    */

    'logging' => [
        'enabled' => env('ELASTICSEARCH_LOGGING', false),
        'level' => env('ELASTICSEARCH_LOG_LEVEL', 'info'),
    ],
]; 