<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Elasticsearch Configuration
    |--------------------------------------------------------------------------
    |
    | Конфигурация подключения к Elasticsearch 8.18.
    | Поддерживает множественные хосты для высокой доступности.
    |
    | Документация Elasticsearch 8.18:
    | - https://www.elastic.co/guide/en/elasticsearch/reference/8.18/
    | - https://www.elastic.co/guide/en/elasticsearch/client/php-api/8.18/
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Hosts Configuration
    |--------------------------------------------------------------------------
    |
    | Настройки хостов Elasticsearch. Поддерживает множественные хосты
    | для балансировки нагрузки и высокой доступности.
    |
    | Формат: 'protocol://host:port' или просто 'host:port'
    | Примеры:
    | - 'http://localhost:9200'
    | - 'https://elasticsearch.example.com:9200'
    | - 'http://es1:9200,http://es2:9200,http://es3:9200'
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
    | Дополнительные настройки подключения для клиента Elasticsearch 8.18.
    | Все настройки соответствуют официальному PHP клиенту.
    |
    | Документация:
    | - https://www.elastic.co/guide/en/elasticsearch/client/php-api/8.18/configuration.html
    |
    */
    'connection' => [
        // Количество попыток повторного подключения при ошибках
        'retries' => env('ELASTICSEARCH_RETRIES', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Index Settings
    |--------------------------------------------------------------------------
    |
    | Настройки индексов по умолчанию для Elasticsearch 8.18.
    | Эти настройки применяются ко всем создаваемым индексам,
    | если не переопределены в конфигурации конкретной модели.
    |
    | Документация:
    | - https://www.elastic.co/guide/en/elasticsearch/reference/8.18/indices-create-index.html
    | - https://www.elastic.co/guide/en/elasticsearch/reference/8.18/index-modules.html
    |
    */
    'index' => [
        // Префикс для всех индексов (например, 'prod_' -> 'prod_products')
        'prefix' => env('ELASTICSEARCH_INDEX_PREFIX', ''),
        
        // Количество шардов (секций) для каждого индекса
        // В Elasticsearch 8.18 по умолчанию 1 шард для новых индексов
        'number_of_shards' => env('ELASTICSEARCH_NUMBER_OF_SHARDS', 1),
        
        // Количество реплик (копий) каждого шарда
        // 0 = без реплик (только для разработки), 1+ = для продакшена
        'number_of_replicas' => env('ELASTICSEARCH_NUMBER_OF_REPLICAS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Analysis Configuration
    |--------------------------------------------------------------------------
    |
    | Настройки анализа текста для Elasticsearch 8.18. Анализ текста определяет,
    | как обрабатывать и индексировать текстовые данные для поиска.
    |
    | Основные компоненты:
    | - analyzer: определяет полный процесс обработки текста
    | - tokenizer: разбивает текст на токены (слова)
    | - filter: применяет преобразования к токенам
    |
    | Документация:
    | - https://www.elastic.co/guide/en/elasticsearch/reference/8.18/analysis.html
    | - https://www.elastic.co/guide/en/elasticsearch/reference/8.18/analysis-analyzers.html
    | - https://www.elastic.co/guide/en/elasticsearch/reference/8.18/analysis-tokenizers.html
    | - https://www.elastic.co/guide/en/elasticsearch/reference/8.18/analysis-tokenfilters.html
    |
    */
    'analysis' => [
        // Анализаторы - определяют полный процесс обработки текста
        // ⚠️ ВНИМАНИЕ: Каждый анализатор увеличивает размер индекса и время индексации
        'analyzer' => [
            // Анализатор для английского языка
            'english' => [
                'type' => 'custom',
                'tokenizer' => 'standard',          // Разбивает по пробелам и знакам препинания
                'filter' => [
                    'lowercase',                    // Приводит к нижнему регистру
                    'english_stop',                 // Удаляет английские стоп-слова
                    'english_stemmer',              // Приводит к основе слова (стемминг)
                ],
            ],
            
            // Анализатор для латышского языка
            'latvian' => [
                'type' => 'custom',
                'tokenizer' => 'standard',          // Разбивает по пробелам и знакам препинания
                'filter' => [
                    'lowercase',                    // Приводит к нижнему регистру
                    'latvian_stop',                 // Удаляет латышские стоп-слова
                    'latvian_stemmer',              // Приводит к основе слова (стемминг)
                ],
            ],
            
            // Анализатор для автодополнения
            'autocomplete' => [
                'type' => 'custom',
                'tokenizer' => 'autocomplete_tokenizer',  // Специальный токенизатор
                'filter' => [
                    'lowercase',                    // Приводит к нижнему регистру
                    'autocomplete_filter',          // Фильтр для автодополнения
                ],
            ],
            
            // Анализатор для поиска по точному совпадению
            'exact_match' => [
                'type' => 'custom',
                'tokenizer' => 'keyword',           // Обрабатывает весь текст как один токен
                'filter' => ['lowercase'],          // Только приведение к нижнему регистру
            ],
            
            // Анализатор для поиска по всему тексту (multi-field)
            'full_text' => [
                'type' => 'custom',
                'tokenizer' => 'standard',
                'filter' => [
                    'lowercase',                    // Приводит к нижнему регистру
                    'english_stop',                 // Удаляет английские стоп-слова
                    'latvian_stop',                 // Удаляет латышские стоп-слова
                    'english_stemmer',              // Стемминг для английского
                    'latvian_stemmer',              // Стемминг для латышского
                    'unique',                       // Удаляет дубликаты токенов
                ],
            ],
        ],
        
        // Токенизаторы - разбивают текст на токены (слова)
        // ⚠️ ВНИМАНИЕ: Каждый токенизатор влияет на размер индекса
        'tokenizer' => [
            // Токенизатор для автодополнения
            // ОБЯЗАТЕЛЬНО для проектов с автодополнением
            'autocomplete_tokenizer' => [
                'type' => 'edge_ngram',             // Создает n-граммы с начала
                'min_gram' => 1,                    // Минимальная длина n-граммы
                'max_gram' => 20,                   // Максимальная длина n-граммы
                'token_chars' => ['letter', 'digit'], // Только буквы и цифры
            ],
            
            // ДОПОЛНИТЕЛЬНЫЕ ТОКЕНИЗАТОРЫ (раскомментировать при необходимости)
            
            // Кастомный токенизатор для разбивки по пробелам и запятым
            // Полезен для обработки тегов или категорий
            // 'custom_tokenizer' => [
            //     'type' => 'pattern',                // Регулярное выражение
            //     'pattern' => '[\\s,]+',             // Разбивает по пробелам и запятым
            // ],
        ],
        
        // Фильтры - применяют преобразования к токенам
        'filter' => [
            // ФИЛЬТРЫ для английского языка
            'english_stop' => [
                'type' => 'stop',
                'stopwords' => '_english_',         // Встроенный список английских стоп-слов
            ],
            
            'english_stemmer' => [
                'type' => 'stemmer',
                'language' => 'english',
            ],
            
            // ФИЛЬТРЫ для латышского языка
            'latvian_stop' => [
                'type' => 'stop',
                'stopwords' => '_latvian_',         // Встроенный список латышских стоп-слов
            ],
            
            'latvian_stemmer' => [
                'type' => 'stemmer',
                'language' => 'latvian',
            ],
            
            // ФИЛЬТРЫ для автодополнения
            'autocomplete_filter' => [
                'type' => 'edge_ngram',
                'min_gram' => 1,                    // Минимальная длина
                'max_gram' => 20,                   // Максимальная длина
            ],
            
            // ФИЛЬТР для удаления дубликатов
            'unique' => [
                'type' => 'unique',
                'only_on_same_position' => true,    // Только в одной позиции
            ],
        ],
        
        // Кастомные стоп-слова (можно переопределить встроенные)
        'stopwords' => [
            // Английские стоп-слова (дополнительные к встроенным)
            'english_custom' => [
                'this', 'that', 'these', 'those',
                'which', 'what', 'where', 'when', 'why', 'how',
                'our', 'your', 'their', 'his', 'her', 'its',
                'very', 'really', 'quite', 'rather',
            ],
            
            // Латышские стоп-слова (дополнительные к встроенным)
            'latvian_custom' => [
                'šis', 'šī', 'šie', 'šīs',
                'tas', 'tā', 'tie', 'tās',
                'kurš', 'kura', 'kuri', 'kuras',
                'mūsu', 'jūsu', 'viņu', 'viņa', 'viņas',
                'ļoti', 'daudz', 'maz', 'vairāk', 'mazāk',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Quality Settings
    |--------------------------------------------------------------------------
    |
    | Настройки для улучшения качества поиска и релевантности результатов
    | в Elasticsearch 8.18. Эти настройки влияют на то, как формируются
    | поисковые запросы и рассчитывается релевантность.
    |
    | Документация:
    | - https://www.elastic.co/guide/en/elasticsearch/reference/8.18/query-dsl.html
    | - https://www.elastic.co/guide/en/elasticsearch/reference/8.18/query-dsl-multi-match-query.html
    | - https://www.elastic.co/guide/en/elasticsearch/reference/8.18/query-dsl-fuzzy-query.html
    |
    */
    'search' => [
        // Настройки по умолчанию для поисковых запросов
        // Применяются ко всем запросам, если не переопределены
        'default' => [
            'operator' => 'OR',                    // OR или AND - логика объединения условий
            'fuzziness' => 'AUTO',                 // AUTO, 0, 1, 2 - уровень нечеткости
            'minimum_should_match' => '75%',       // Минимальное совпадение условий
            'max_expansions' => 50,                // Максимальное количество вариантов для fuzzy search
            'prefix_length' => 0,                  // Минимальная длина префикса для fuzzy search
        ],
        
        // Настройки для автодополнения (suggestions)
        // Влияют на качество предложений при вводе текста
        'autocomplete' => [
            'min_score' => 0.1,                    // Минимальный скор для показа предложения
            'max_suggestions' => 10,               // Максимальное количество предложений
            'highlight' => true,                   // Подсветка совпадающих частей
            'size' => 5,                           // Размер каждого предложения
        ],
        
        // Настройки для нечеткого поиска (fuzzy search)
        // Позволяет находить результаты с опечатками
        'fuzzy' => [
            'enabled' => true,                     // Включить нечеткий поиск
            'fuzziness' => 'AUTO',                 // Уровень нечеткости (AUTO, 0, 1, 2)
            'max_expansions' => 50,                // Максимальное количество вариантов
            'prefix_length' => 0,                  // Минимальная длина префикса
            'transpositions' => true,              // Разрешить транспозиции букв
            'max_edits' => 2,                      // Максимальное количество правок
        ],
        
        // Настройки для поиска по точному совпадению
        // Высокий приоритет для точных совпадений
        'exact_match' => [
            'analyzer' => 'exact_match',           // Анализатор для точного совпадения
            'type' => 'phrase',                    // Тип запроса - фразовый поиск
        ],
        
        // Настройки для поиска по частичному совпадению
        // Средний приоритет для частичных совпадений
        'partial_match' => [
            'analyzer' => 'autocomplete',          // Используем autocomplete анализатор
            'type' => 'best_fields',               // Тип запроса - лучшие поля
        ],
        
        // Настройки для поиска по всему тексту
        // Универсальный поиск по всем текстовым полям
        'full_text' => [
            'analyzer' => 'full_text',             // Анализатор для всего текста
            'fields' => [
                'title', 'title_en', 'title_lv',
                'short_description', 'short_description_en', 'short_description_lv',
                'specification', 'specification_en', 'specification_lv'
            ],   // Поля для поиска по всему тексту
            'type' => 'most_fields',               // Тип запроса - большинство полей
        ],
        
        // Настройки для геопространственного поиска
        // Для поиска по координатам и расстояниям
        'geo' => [
            'enabled' => false,                    // Включить геопоиск
            'distance_type' => 'arc',              // Тип расчета расстояния (arc, plane)
            'unit' => 'km',                        // Единица измерения расстояния
            'max_distance' => 100,                 // Максимальное расстояние
        ],
        
        // Настройки для агрегаций (группировки результатов)
        // Для создания фильтров и фасетов
        'aggregations' => [
            'enabled' => true,                     // Включить агрегации
            'size' => 10,                          // Размер каждой агрегации
            'min_doc_count' => 1,                  // Минимальное количество документов
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Translatable Fields Configuration
    |--------------------------------------------------------------------------
    |
    | Глобальные настройки для обработки translatable полей (JSON структур с переводами).
    | Эти настройки применяются ко всем моделям, если не переопределены в конфигурации модели.
    |
    | Документация:
    | - https://www.elastic.co/guide/en/elasticsearch/reference/8.18/mapping.html
    | - https://www.elastic.co/guide/en/elasticsearch/reference/8.18/mapping-types.html
    |
    */
    'translatable' => [
        // Поддерживаемые языки для translatable полей
        'locales' => ['en', 'lv'],
        
        // Основной язык для fallback (если перевод не найден)
        'fallback_locale' => 'en',
        
        // Создавать ли отдельные поля для каждого языка (fieldName_localeName)
        'index_localized_fields' => true,
        
        // Автоматически определять translatable поля по типу данных
        'auto_detect_translatable' => true,
        
        // Список полей, которые всегда считаются translatable (если auto_detect = false)
        'translatable_fields' => [
            'title', 'slug', 'short_description', 'specification', 'description', 'content'
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Indexing Configuration
    |--------------------------------------------------------------------------
    |
    | Конфигурация индексации моделей в Elasticsearch 8.18.
    | Определяет, какие модели должны индексироваться и как их данные
    | должны структурироваться в Elasticsearch.
    |
    | Каждая модель может иметь свои собственные правила индексации,
    | включая маппинг полей, условия запросов и настройки производительности.
    |
    | Translatable Fields (Переводимые поля):
    | ---------------------------------------
    | Для многоязычных проектов можно настроить автоматическую обработку
    | translatable полей (JSON структуры с переводами).
    |
    | Настройки translatable полей:
    | - locales: массив поддерживаемых языков (например, ['en', 'lv', 'ru'])
    | - fallback_locale: основной язык для fallback (например, 'en')
    | - index_localized_fields: создавать ли отдельные поля для каждого языка
    | - auto_detect_translatable: автоматически определять translatable поля
    |
    | Query Conditions (Условия запросов):
    | ------------------------------------
    | Используйте 'query_conditions' для фильтрации записей перед индексацией.
    | Это позволяет индексировать только нужные данные (например, только активные записи).
    |
    | Поддерживаемые типы условий:
    | - where: простые условия WHERE
    | - where_in: условия WHERE IN
    | - where_between: условия WHERE BETWEEN
    | - where_has: условия для отношений (WHERE HAS)
    | - where_doesnt_have: условия для отсутствия отношений
    | - where_callback: кастомные условия через замыкание
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
    | Документация:
    | - https://www.elastic.co/guide/en/elasticsearch/reference/8.18/mapping.html
    | - https://www.elastic.co/guide/en/elasticsearch/reference/8.18/mapping-types.html
    | - https://www.elastic.co/guide/en/elasticsearch/reference/8.18/mapping-params.html
    |
    */
    'models' => [
        // Пример конфигурации для модели Product
        // Показывает полный набор возможностей индексации
        'App\\Models\\Product\\Product' => [
            // Имя индекса (будет добавлен префикс из конфигурации)
            'index' => 'products',
            
            // Настройки translatable полей для этой модели (переопределяют глобальные)
            'translatable' => [
                'locales' => ['en', 'lv'],           // Поддерживаемые языки для этой модели
                'fallback_locale' => 'en',                 // Основной язык для fallback
                'index_localized_fields' => true,          // Создавать отдельные поля для каждого языка
                'auto_detect_translatable' => true,        // Автоматически определять translatable поля
                'translatable_fields' => [                 // Список полей (если auto_detect = false)
                    'title', 'slug', 'short_description', 'specification', 'description'
                ],
            ],
            
            // Поля для поиска - определяют как индексировать данные для поиска
            // Каждое поле может иметь свой тип, анализатор и настройки
            'searchable_fields' => [
                // Translatable поля - извлекаются из JSON структуры
                // Поле заголовка (translatable)
                'title' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'english',                   // Анализатор для английского языка
                    'fields' => [
                        // Подполе для точного совпадения
                        'exact' => [
                            'type' => 'text',
                            'analyzer' => 'exact_match',       // Анализатор точного совпадения
                        ],
                        // Подполе для автодополнения
                        'autocomplete' => [
                            'type' => 'text',
                            'analyzer' => 'autocomplete',      // Анализатор автодополнения
                        ],
                    ],
                ],
                
                // Поле slug (translatable)
                'slug' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'exact_match',               // Анализатор точного совпадения
                ],
                
                // Поле краткого описания (translatable)
                'short_description' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'english',                   // Анализатор для английского языка
                ],
                
                // Поле спецификации (translatable)
                'specification' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'english',                   // Анализатор для английского языка
                ],
                
                // Языковые версии полей (извлекаются из translatable JSON)
                // Английский язык
                'title_en' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'english',                   // Анализатор для английского языка
                    'fields' => [
                        'exact' => [
                            'type' => 'text',
                            'analyzer' => 'exact_match',
                        ],
                        'autocomplete' => [
                            'type' => 'text',
                            'analyzer' => 'autocomplete',
                        ],
                    ],
                ],
                
                'slug_en' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'exact_match',               // Анализатор точного совпадения
                ],
                
                'short_description_en' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'english',                   // Анализатор для английского языка
                ],
                
                'specification_en' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'english',                   // Анализатор для английского языка
                ],
                
                // Латышский язык
                'title_lv' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'latvian',                   // Анализатор для латышского языка
                    'fields' => [
                        'exact' => [
                            'type' => 'text',
                            'analyzer' => 'exact_match',
                        ],
                        'autocomplete' => [
                            'type' => 'text',
                            'analyzer' => 'autocomplete',
                        ],
                    ],
                ],
                
                'slug_lv' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'exact_match',               // Анализатор точного совпадения
                ],
                
                'short_description_lv' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'latvian',                   // Анализатор для латышского языка
                ],
                
                'specification_lv' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'latvian',                   // Анализатор для латышского языка
                ],
            ],
            
            // Поля для хранения - возвращаются напрямую из Elasticsearch
            // Эти поля не участвуют в поиске, но доступны в результатах
            'stored_fields' => [
                'id',                           // ID записи
                'title',                        // Заголовок (translatable)
                'title_en',                     // Заголовок на английском
                'title_lv',                     // Заголовок на латышском
                'slug',                         // Slug (translatable)
                'slug_en',                      // Slug на английском
                'slug_lv',                      // Slug на латышском
                'short_description',            // Краткое описание (translatable)
                'short_description_en',         // Краткое описание на английском
                'short_description_lv',         // Краткое описание на латышском
                'specification',                // Спецификация (translatable)
                'specification_en',             // Спецификация на английском
                'specification_lv',             // Спецификация на латышском
                'is_active',                    // Статус активности
                'created_at',                   // Дата создания
                'updated_at',                   // Дата обновления
            ],
            
            // Отношения для загрузки из базы данных после поиска
            // Эти данные загружаются отдельным запросом к БД
            'relations' => [
                'images',                       // Изображения товара
                'specifications',               // Характеристики
                'reviews',                      // Отзывы
                'category',                     // Категория (полная модель)
                'brand',                        // Бренд (полная модель)
            ],
            
            // Вычисляемые поля - создаются на основе других полей
            // Могут объединять несколько полей или применять трансформации
            'computed_fields' => [
                // Поле для поиска по всему тексту (все translatable поля)
                'search_text' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'full_text',                 // Анализатор для всего текста
                    'source' => [
                        'title', 'title_en', 'title_lv',
                        'short_description', 'short_description_en', 'short_description_lv',
                        'specification', 'specification_en', 'specification_lv'
                    ], // Источники данных
                ],
                
                // Поле для поиска по заголовкам (все языки)
                'title_search' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'full_text',                 // Анализатор для всего текста
                    'source' => ['title', 'title_en', 'title_lv'], // Источники данных
                ],
                
                // Поле для поиска по описаниям (все языки)
                'description_search' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'full_text',                 // Анализатор для всего текста
                    'source' => [
                        'short_description', 'short_description_en', 'short_description_lv',
                        'specification', 'specification_en', 'specification_lv'
                    ], // Источники данных
                ],
            ],
            
            // ⚠️ ВАЖНО: Boost (приоритет полей) должен применяться только в поисковых запросах,
            // а не в маппингах индекса. В Elasticsearch 8.x boost в маппингах устарел и удален.
            // Для применения boost используйте параметры в поисковых запросах:
            // - 'boost' => 2.0 в multi_match, match, term и других запросах
            // - 'boost_mode' => 'multiply' для режима применения boost
            // - 'score_mode' => 'sum' для режима подсчета скора
            
            // Размер чанка для bulk индексации
            // Влияет на производительность и использование памяти
            'chunk_size' => 1000,
            
            // Условия запросов для фильтрации записей перед индексацией
            // Позволяют индексировать только нужные записи
            /*
            'query_conditions' => [
                // Базовые условия WHERE для всех запросов
                // Поддерживаются: обычные значения, 'null', 'not_null'
                'where' => [
                    'is_active' => true,                       // Только активные записи
                    'deleted_at' => null,                      // Исключить мягко удаленные
                    'email_verified_at' => 'not_null',         // Только с подтвержденным email
                ],
                
                // Условия для определенных статусов (WHERE IN)
                'where_in' => [
                    'status' => ['published', 'approved'],     // Только опубликованные и одобренные
                ],
                
                // Условия для диапазонов (WHERE BETWEEN)
                'where_between' => [
                    'price' => [100, 10000],                   // Товары в определенном ценовом диапазоне
                ],
                
                // Условия для отношений (WHERE HAS)
                // Можно передать замыкание или массив условий
                'where_has' => [
                    'category' => function($query) {
                        $query->where('is_active', true);      // Только с активными категориями
                    },
                    // Или простое условие:
                    // 'category' => ['is_active' => true],
                ],
                
                // Условия для отсутствия отношений (WHERE DOESN'T HAVE)
                'where_doesnt_have' => [
                    'blocked_reviews',                         // Исключить товары с заблокированными отзывами
                ],
                
                // Дополнительные условия через замыкание
                // Позволяет использовать любые методы Query Builder
                'where_callback' => function($query) {
                    $query->where('stock_quantity', '>', 0)    // Только товары в наличии
                          ->where('expires_at', '>', now());   // Не истекшие
                },
            ],
            */
            
            // Кастомные настройки индекса для этой конкретной модели (опционально)
            // Переопределяют глобальные настройки индекса
            'index_settings' => [
                // Можно добавить кастомные настройки для индекса
                // Например, специальные анализаторы или фильтры
            ],
        ],
    ],
]; 