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
        // Таймаут для выполнения запросов (в секундах)
        'timeout' => env('ELASTICSEARCH_TIMEOUT', 30),
        
        // Таймаут для установки соединения (в секундах)
        'connect_timeout' => env('ELASTICSEARCH_CONNECT_TIMEOUT', 10),
        
        // Количество попыток повторного подключения при ошибках
        'retries' => env('ELASTICSEARCH_RETRIES', 3),
        
        // Настройки SSL/TLS (для Elasticsearch 8.x с включенной безопасностью)
        'ssl' => [
            'verify' => env('ELASTICSEARCH_SSL_VERIFY', true),
            'cert' => env('ELASTICSEARCH_SSL_CERT', null),
            'key' => env('ELASTICSEARCH_SSL_KEY', null),
            'ca' => env('ELASTICSEARCH_SSL_CA', null),
        ],
        
        // Настройки аутентификации
        'headers' => [
            'Authorization' => env('ELASTICSEARCH_AUTH_HEADER', null),
        ],
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
        
        // Настройки для zero-downtime reindexing (переиндексация без простоя)
        // Позволяет создавать новые версии индексов с алиасами
        'versioning' => [
            'enabled' => env('ELASTICSEARCH_VERSIONING_ENABLED', true),
            'alias_suffix' => '_current',           // Суффикс для алиаса текущей версии
            'version_format' => 'v{number}',        // Формат версии: v1, v2, v3...
            'keep_old_versions' => 2,               // Количество старых версий для хранения
        ],
        
        // Настройки для автоматического обновления маппинга
        'mapping' => [
            'dynamic' => 'strict',                  // Строгий режим: новые поля отклоняются
            'date_detection' => false,              // Отключить автоопределение дат
            'numeric_detection' => false,           // Отключить автоопределение чисел
        ],
        
        // Настройки для оптимизации производительности
        // ⚠️ ВНИМАНИЕ: Эти настройки влияют на производительность и использование ресурсов
        'performance' => [
            // Интервал обновления индекса (refresh_interval)
            // - '1s' = обновление каждую секунду (быстрый поиск, но больше нагрузки)
            // - '30s' = обновление каждые 30 секунд (медленнее, но меньше нагрузки)
            // - '-1' = отключить автоматическое обновление (только ручное)
            'refresh_interval' => env('ELASTICSEARCH_REFRESH_INTERVAL', '1s'),
            
            // Максимальное количество результатов для поиска
            // Влияет на память и производительность при глубокой пагинации
            // Рекомендуется: 1000-10000 для типовых проектов
            'max_result_window' => env('ELASTICSEARCH_MAX_RESULT_WINDOW', 10000),
            
            // Максимальная разница для n-грамм (влияет на размер индекса)
            // Используется только для автодополнения и частичного поиска
            // Рекомендуется: 20-50 для типовых проектов
            'max_ngram_diff' => env('ELASTICSEARCH_MAX_NGRAM_DIFF', 20),
        ],
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
        // Для типовых проектов достаточно 3-5 основных анализаторов
        'analyzer' => [
            // Стандартный анализатор для английского языка
            // Используется по умолчанию, если не указан другой
            'standard' => [
                'type' => 'standard',
                'stopwords' => '_english_',         // Встроенный список английских стоп-слов
            ],
            
            // Кастомный анализатор для русского языка
            // ОБЯЗАТЕЛЬНО для проектов с русским контентом
            'russian' => [
                'type' => 'custom',
                'tokenizer' => 'standard',          // Разбивает по пробелам и знакам препинания
                'filter' => [
                    'lowercase',                    // Приводит к нижнему регистру
                    'russian_stop',                 // Удаляет русские стоп-слова
                    'russian_stemmer',              // Приводит к основе слова (стемминг)
                ],
            ],
            
            // Анализатор для автодополнения
            // ОБЯЗАТЕЛЬНО для проектов с автодополнением
            'autocomplete' => [
                'type' => 'custom',
                'tokenizer' => 'autocomplete_tokenizer',  // Специальный токенизатор
                'filter' => [
                    'lowercase',                    // Приводит к нижнему регистру
                    'autocomplete_filter',          // Фильтр для автодополнения
                ],
            ],
            
            // Анализатор для поиска по точному совпадению
            // РЕКОМЕНДУЕТСЯ для поиска по SKU, кодам, точным названиям
            'exact_match' => [
                'type' => 'custom',
                'tokenizer' => 'keyword',           // Обрабатывает весь текст как один токен
                'filter' => ['lowercase'],          // Только приведение к нижнему регистру
            ],
            
            // Анализатор для поиска по всему тексту (multi-field)
            // ОПЦИОНАЛЬНО: объединяет возможности всех языков
            // Увеличивает размер индекса, но улучшает качество поиска
            'full_text' => [
                'type' => 'custom',
                'tokenizer' => 'standard',
                'filter' => [
                    'lowercase',                    // Приводит к нижнему регистру
                    'russian_stop',                 // Удаляет русские стоп-слова
                    'english_stop',                 // Удаляет английские стоп-слова
                    'russian_stemmer',              // Стемминг для русского
                    'english_stemmer',              // Стемминг для английского
                    'unique',                       // Удаляет дубликаты токенов
                ],
            ],
            
            // ДОПОЛНИТЕЛЬНЫЕ АНАЛИЗАТОРЫ (раскомментировать при необходимости)
            
            // Анализатор для английского языка (если нужен отдельно от русского)
            // 'english' => [
            //     'type' => 'custom',
            //     'tokenizer' => 'standard',
            //     'filter' => [
            //         'lowercase',
            //         'english_stop',
            //         'english_stemmer',
            //         'english_possessive_stemmer',
            //     ],
            // ],
            
            // Анализатор для латышского языка (если нужен)
            // 'latvian' => [
            //     'type' => 'custom',
            //     'tokenizer' => 'standard',
            //     'filter' => [
            //         'lowercase',
            //         'latvian_stop',
            //         'latvian_stemmer',
            //     ],
            // ],
            
            // Анализатор для частичного совпадения (если нужен)
            // 'partial_match' => [
            //     'type' => 'custom',
            //     'tokenizer' => 'standard',
            //     'filter' => [
            //         'lowercase',
            //         'edge_ngram',
            //     ],
            // ],
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
        // ⚠️ ВНИМАНИЕ: Каждый фильтр увеличивает время обработки
        'filter' => [
            // ОБЯЗАТЕЛЬНЫЕ ФИЛЬТРЫ для русского языка
            'russian_stop' => [
                'type' => 'stop',
                'stopwords' => '_russian_',         // Встроенный список русских стоп-слов
            ],
            
            'russian_stemmer' => [
                'type' => 'stemmer',
                'language' => 'russian',
            ],
            
            // ОБЯЗАТЕЛЬНЫЕ ФИЛЬТРЫ для английского языка
            'english_stop' => [
                'type' => 'stop',
                'stopwords' => '_english_',         // Встроенный список английских стоп-слов
            ],
            
            'english_stemmer' => [
                'type' => 'stemmer',
                'language' => 'english',
            ],
            
            // ОБЯЗАТЕЛЬНЫЕ ФИЛЬТРЫ для автодополнения
            'autocomplete_filter' => [
                'type' => 'edge_ngram',
                'min_gram' => 1,                    // Минимальная длина
                'max_gram' => 20,                   // Максимальная длина
            ],
            
            // ОБЯЗАТЕЛЬНЫЙ ФИЛЬТР для удаления дубликатов
            'unique' => [
                'type' => 'unique',
                'only_on_same_position' => true,    // Только в одной позиции
            ],
            
            // ДОПОЛНИТЕЛЬНЫЕ ФИЛЬТРЫ (раскомментировать при необходимости)
            
            // Стеммер для притяжательных форм в английском
            // 'english_possessive_stemmer' => [
            //     'type' => 'stemmer',
            //     'language' => 'possessive_english',
            // ],
            
            // Фильтр для создания n-грамм (для автодополнения)
            // 'edge_ngram' => [
            //     'type' => 'edge_ngram',
            //     'min_gram' => 2,                    // Минимальная длина
            //     'max_gram' => 20,                   // Максимальная длина
            // ],
            
            // Фильтр для нормализации текста
            // 'word_delimiter' => [
            //     'type' => 'word_delimiter',
            //     'generate_word_parts' => true,      // Генерировать части слов
            //     'generate_number_parts' => true,    // Генерировать части чисел
            //     'catenate_words' => true,           // Объединять слова
            //     'catenate_numbers' => true,         // Объединять числа
            //     'catenate_all' => false,            // Не объединять все
            //     'split_on_case_change' => true,     // Разбивать при смене регистра
            //     'preserve_original' => false,       // Не сохранять оригинал
            //     'split_on_numerics' => true,        // Разбивать при цифрах
            //     'stem_english_possessive' => true,  // Обрабатывать притяжательные
            // ],
            
            // Фильтр для синонимов (можно настроить кастомные синонимы)
            // 'synonym_filter' => [
            //     'type' => 'synonym',
            //     'synonyms' => [
            //         'iphone, apple phone',
            //         'android, google phone',
            //         'laptop, notebook, portable computer',
            //     ],
            //     'ignore_case' => true,              // Игнорировать регистр
            // ],
            
            // Фильтр для удаления акцентов
            // 'asciifolding' => [
            //     'type' => 'asciifolding',
            //     'preserve_original' => false,       // Не сохранять оригинал
            // ],
        ],
        
        // Кастомные стоп-слова (можно переопределить встроенные)
        // Дополнительные слова для удаления при анализе
        'stopwords' => [
            // Русские стоп-слова (дополнительные к встроенным)
            'russian_custom' => [
                'этот', 'эта', 'это', 'эти',
                'тот', 'та', 'то', 'те',
                'какой', 'какая', 'какое', 'какие',
                'который', 'которая', 'которое', 'которые',
                'наш', 'наша', 'наше', 'наши',
                'ваш', 'ваша', 'ваше', 'ваши',
                'их', 'его', 'ее', 'их',
            ],
            
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
            'analyzer' => 'partial_match',         // Анализатор для частичного совпадения
            'type' => 'best_fields',               // Тип запроса - лучшие поля
        ],
        
        // Настройки для поиска по всему тексту
        // Универсальный поиск по всем текстовым полям
        'full_text' => [
            'analyzer' => 'full_text',             // Анализатор для всего текста
            'fields' => ['name', 'description'],   // Поля для поиска по всему тексту
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
        'App\\Models\\Product' => [
            // Имя индекса (будет добавлен префикс из конфигурации)
            'index' => 'products',
            
            // Поля для поиска - определяют как индексировать данные для поиска
            // Каждое поле может иметь свой тип, анализатор и настройки
            'searchable_fields' => [
                // Основное поле названия с multi-field mapping
                'name' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'russian',                   // Анализатор для русского языка
                    'fields' => [
                        // Подполе для точного совпадения
                        'exact' => [
                            'type' => 'text',
                            'analyzer' => 'exact_match',       // Анализатор точного совпадения
                        ],
                        // Подполе для частичного совпадения
                        'partial' => [
                            'type' => 'text',
                            'analyzer' => 'partial_match',     // Анализатор частичного совпадения
                        ],
                        // Подполе для автодополнения
                        'autocomplete' => [
                            'type' => 'text',
                            'analyzer' => 'autocomplete',      // Анализатор автодополнения
                        ],
                    ],
                ],
                
                // Поле описания
                'description' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'russian',                   // Анализатор для русского языка
                ],
                
                // Поле категории (ключевое слово)
                'category' => [
                    'type' => 'keyword',                       // Тип поля - ключевое слово
                ],
                
                // Поле бренда (ключевое слово)
                'brand' => [
                    'type' => 'keyword',                       // Тип поля - ключевое слово
                ],
                
                // Поле тегов (массив ключевых слов)
                'tags' => [
                    'type' => 'keyword',                       // Тип поля - ключевое слово
                ],
                
                // Поле SKU (артикул)
                'sku' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'exact_match',               // Анализатор точного совпадения
                ],
                
                // Поле для поисковых предложений
                'search_suggestions' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'autocomplete',              // Анализатор автодополнения
                ],
                
                // Многоязычные поля - для поддержки нескольких языков
                'name_en' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'english',                   // Анализатор для английского языка
                ],
                
                'description_en' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'english',                   // Анализатор для английского языка
                ],
                
                'name_lv' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'latvian',                   // Анализатор для латышского языка
                ],
                
                'description_lv' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'latvian',                   // Анализатор для латышского языка
                ],
            ],
            
            // Поля для хранения - возвращаются напрямую из Elasticsearch
            // Эти поля не участвуют в поиске, но доступны в результатах
            'stored_fields' => [
                'id',                           // ID записи
                'name',                         // Название
                'name_en',                      // Название на английском
                'name_lv',                      // Название на латышском
                'price',                        // Цена
                'category',                     // Категория
                'brand',                        // Бренд
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
                // Поле для поиска по всему тексту
                'search_text' => [
                    'type' => 'text',                          // Тип поля - текстовый
                    'analyzer' => 'full_text',                 // Анализатор для всего текста
                    'source' => ['name', 'description', 'category', 'brand', 'tags'], // Источники данных
                ],
                
                // Поле для группировки по ценовым диапазонам
                'price_range' => [
                    'type' => 'keyword',                       // Тип поля - ключевое слово
                    'source' => 'price',                       // Источник данных
                    'transform' => 'price_range',              // Трансформация
                ],
                
                // Поле для расчета популярности
                'popularity_score' => [
                    'type' => 'float',                         // Тип поля - число с плавающей точкой
                    'source' => ['views_count', 'sales_count', 'rating'], // Источники данных
                    'transform' => 'popularity_score',         // Трансформация
                ],
                
                // Поле для статуса доступности
                'availability_status' => [
                    'type' => 'keyword',                       // Тип поля - ключевое слово
                    'source' => ['stock_quantity', 'is_active'], // Источники данных
                    'transform' => 'availability_status',      // Трансформация
                ],
            ],
            
            // Настройки запросов для этой модели
            // Переопределяют глобальные настройки поиска
            'query' => [
                'default_operator' => 'OR',                    // Оператор по умолчанию
                'fuzziness' => 'AUTO',                         // Уровень нечеткости
                'minimum_should_match' => '75%',               // Минимальное совпадение
                'boost_mode' => 'multiply',                    // Режим буста
                'score_mode' => 'sum',                         // Режим подсчета скора
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
            
            // Использовать мягкое удаление для этой модели
            // Влияет на обработку удаленных записей
            'soft_deletes' => true,
            
            // Условия запросов для фильтрации записей перед индексацией
            // Позволяют индексировать только нужные записи
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
            
            // Кастомные настройки индекса для этой конкретной модели (опционально)
            // Переопределяют глобальные настройки индекса
            'index_settings' => [
                // Можно добавить кастомные настройки для индекса
                // Например, специальные анализаторы или фильтры
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Настройки логирования для операций Elasticsearch 8.18.
    | Позволяет отслеживать запросы, ответы и ошибки для отладки
    | и мониторинга производительности.
    |
    | Документация:
    | - https://www.elastic.co/guide/en/elasticsearch/client/php-api/8.18/logging.html
    | - https://www.elastic.co/guide/en/elasticsearch/reference/8.18/logging.html
    |
    */
    'logging' => [
        // Включить логирование операций Elasticsearch
        'enabled' => env('ELASTICSEARCH_LOGGING', false),
        
        // Уровень логирования (debug, info, warning, error)
        'level' => env('ELASTICSEARCH_LOG_LEVEL', 'info'),
        
        // Настройки для детального логирования
        'detailed' => [
            'enabled' => env('ELASTICSEARCH_DETAILED_LOGGING', false), // Детальное логирование
            'log_requests' => true,                // Логировать запросы
            'log_responses' => true,               // Логировать ответы
            'log_errors' => true,                  // Логировать ошибки
            'log_performance' => true,             // Логировать производительность
        ],
        
        // Настройки для мониторинга производительности
        'performance' => [
            'enabled' => env('ELASTICSEARCH_PERFORMANCE_LOGGING', false), // Логирование производительности
            'slow_query_threshold' => 1000,        // Порог медленных запросов (мс)
            'log_slow_queries' => true,            // Логировать медленные запросы
            'log_bulk_operations' => true,         // Логировать bulk операции
        ],
        
        // Настройки для отладки
        'debug' => [
            'enabled' => env('ELASTICSEARCH_DEBUG_LOGGING', false), // Отладочное логирование
            'log_curl_commands' => false,          // Логировать cURL команды
            'log_request_body' => false,           // Логировать тело запроса
            'log_response_body' => false,          // Логировать тело ответа
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring Configuration
    |--------------------------------------------------------------------------
    |
    | Настройки мониторинга для Elasticsearch 8.18.
    | Позволяет отслеживать состояние кластера и производительность.
    |
    */
    'monitoring' => [
        // Включить мониторинг кластера
        'enabled' => env('ELASTICSEARCH_MONITORING', false),
        
        // Настройки для проверки здоровья кластера
        'health_check' => [
            'enabled' => true,                     // Включить проверку здоровья
            'interval' => 300,                     // Интервал проверки (секунды)
            'timeout' => 10,                       // Таймаут проверки (секунды)
        ],
        
        // Настройки для мониторинга индексов
        'indices' => [
            'enabled' => true,                     // Включить мониторинг индексов
            'check_size' => true,                  // Проверять размер индексов
            'check_health' => true,                // Проверять здоровье индексов
            'alert_threshold' => 0.9,              // Порог для алертов (90% использования)
        ],
        
        // Настройки для алертов
        'alerts' => [
            'enabled' => env('ELASTICSEARCH_ALERTS', false), // Включить алерты
            'email' => env('ELASTICSEARCH_ALERT_EMAIL', null), // Email для алертов
            'webhook' => env('ELASTICSEARCH_ALERT_WEBHOOK', null), // Webhook для алертов
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security Configuration
    |--------------------------------------------------------------------------
    |
    | Настройки безопасности для Elasticsearch 8.18.
    | Включает аутентификацию, авторизацию и шифрование.
    |
    | Документация:
    | - https://www.elastic.co/guide/en/elasticsearch/reference/8.18/security-settings.html
    | - https://www.elastic.co/guide/en/elasticsearch/reference/8.18/security-api.html
    |
    */
    'security' => [
        // Настройки аутентификации
        'authentication' => [
            'enabled' => env('ELASTICSEARCH_AUTH_ENABLED', false), // Включить аутентификацию
            'type' => env('ELASTICSEARCH_AUTH_TYPE', 'basic'),     // Тип аутентификации
            'username' => env('ELASTICSEARCH_USERNAME', null),      // Имя пользователя
            'password' => env('ELASTICSEARCH_PASSWORD', null),      // Пароль
            'api_key' => env('ELASTICSEARCH_API_KEY', null),       // API ключ
        ],
        
        // Настройки SSL/TLS
        'ssl' => [
            'enabled' => env('ELASTICSEARCH_SSL_ENABLED', false),  // Включить SSL/TLS
            'verify' => env('ELASTICSEARCH_SSL_VERIFY', true),     // Проверять сертификат
            'cert' => env('ELASTICSEARCH_SSL_CERT', null),         // Путь к сертификату
            'key' => env('ELASTICSEARCH_SSL_KEY', null),           // Путь к приватному ключу
            'ca' => env('ELASTICSEARCH_SSL_CA', null),             // Путь к CA сертификату
        ],
        
        // Настройки авторизации
        'authorization' => [
            'enabled' => env('ELASTICSEARCH_AUTHZ_ENABLED', false), // Включить авторизацию
            'roles' => env('ELASTICSEARCH_ROLES', []),              // Роли пользователя
            'permissions' => env('ELASTICSEARCH_PERMISSIONS', []),  // Разрешения
        ],
    ],
]; 