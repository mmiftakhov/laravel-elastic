<?php

return [
    'hosts' => [
        env('ELASTICSEARCH_HOST_FULL', 'http://localhost:9200'),
    ],

    'index_settings' => [
        'prefix' => env('ELASTICSEARCH_INDEX_PREFIX', ''),
        'number_of_shards' => 1,
        'number_of_replicas' => 0,
    ],

    'analysis' => [
        'analyzer' => [
            'full_text_en' => [
                'type' => 'custom',
                'tokenizer' => 'standard',
                'filter' => ['lowercase', 'asciifolding', 'porter_stem'],
            ],
            'full_text_lv' => [
                'type' => 'custom',
                'tokenizer' => 'standard',
                'filter' => ['lowercase', 'asciifolding'],
            ],
            'autocomplete' => [
                'type' => 'custom',
                'tokenizer' => 'edge_ngram_tokenizer',
                'filter' => ['lowercase', 'asciifolding'],
            ],
            'exact_match' => [
                'type' => 'custom',
                'tokenizer' => 'keyword',
                'filter' => ['lowercase', 'asciifolding'],
            ],
            'code_analyzer' => [
                'type' => 'custom',
                'tokenizer' => 'standard',
                'filter' => ['lowercase', 'asciifolding'],
            ],
            // Analyzer for size-like inputs such as "20x47x14", "20-47-14", "20 47 14"
            'size_analyzer' => [
                'type' => 'custom',
                'char_filter' => ['numbers_only'],
                'tokenizer' => 'whitespace',
                'filter' => ['lowercase'],
            ],
        ],
        'tokenizer' => [
            'edge_ngram_tokenizer' => [
                'type' => 'edge_ngram',
                'min_gram' => 2,
                'max_gram' => 20,
                'token_chars' => ['letter', 'digit'],
            ],
        ],
        'char_filter' => [
            // Keep only digits and decimal separators, replace everything else with a space
            'numbers_only' => [
                'type' => 'pattern_replace',
                'pattern' => '[^0-9.,]+',
                'replacement' => ' ',
            ],
        ],
    ],

    'search' => [
        'default' => [
            'limit' => 20,
            'offset' => 0,
            // Valid multi_match types: best_fields, most_fields, cross_fields, phrase, phrase_prefix, bool_prefix
            'type' => 'best_fields',
            'operator' => 'and',
        ],
        'fuzzy' => [
            'enabled' => true,
            'fuzziness' => 'AUTO',
            'prefix_length' => 2,
        ],
        'autocomplete' => [
            'limit' => 10,
            'min_chars' => 2,
        ],
        'highlight' => [
            'enabled' => true,
            'fields' => ['*'],
            'fragment_size' => 150,
            'number_of_fragments' => 3,
        ],
        'exact_match' => [
            'boost' => 10.0,
        ],
        'keyword_match' => [
            'boost' => 15.0,
        ],
    ],

    'translatable' => [
        'locales' => ['en', 'lv'],
        'fallback_locale' => 'en',
        'index_localized_fields' => true,
        'auto_detect_translatable' => true,
    ],

    'cache' => [
        'enabled' => true,
        'ttl' => env('ELASTICSEARCH_CACHE_TTL', 60),
    ],

    'models' => [
        // Example:
        // 'App\\Models\\Product' => [
        //     'index' => 'products',
        //     'translatable' => [
        //         'enabled' => true,
        //         'fields' => ['title', 'description'],
        //         'locales' => ['en', 'lv'],
        //     ],
        //     'searchable_fields' => [
        //         'title', 'description', 'sku',
        //         'category' => ['title', 'slug'],
        //         'brand' => ['name'],
        //     ],
        //     'searchable_fields_boost' => [
        //         'title' => 3.0,
        //         'sku' => 2.5,
        //         'category' => [
        //             'title' => 2.0,
        //         ],
        //     ],
        //     'return_fields' => [
        //         'id', 'title', 'slug', 'price',
        //         'category' => ['id', 'title'],
        //     ],
        //     'computed_fields' => [
        //         'full_search' => [
        //             'source_fields' => ['title_en', 'title_lv', 'description_en'],
        //             'type' => 'text',
        //             'analyzer' => 'full_text_en',
        //         ],
        //     ],
        //     'chunk_size' => 1000,
        // ],

        // Product model configuration
        'App\\Models\\Product\\Product' => [
            'index' => 'products',
            
            'translatable' => [
                'enabled' => true,
                'fields' => ['title', 'slug'],
                'locales' => ['en', 'lv'],
            ],
            
            'searchable_fields' => [
                'title', 'slug', 'search_data', 'size_terms', 'code', 'isbn_code', 
                'internal_dia', 'outer_dia', 'width', 'quantity', 'created_at', 'price_with_tax', 'price',
                'is_top', 'is_popular', 'is_new', 'is_limited_quantity',
                'category' => ['id', 'title'],
                'manufacture' => ['id', 'title'],
            ],

            'searchable_fields_boost' => [
                'title' => 5.0,
                'code' => 4.0,
                'isbn_code' => 4.0,
                'slug' => 3.0,
                'search_data' => 3.0,
                'size_terms' => 3.5,
                'internal_dia' => 2.5,
                'outer_dia' => 2.5,
                'width' => 2.5,
                'is_top' => 0,
                'is_popular' => 0,
                'is_new' => 0,
                'is_limited_quantity' => 0,
                'category' => [
                    'id' => 0,
                    'title' => 1.5,
                ],
                'manufacture' => [
                    'id' => 0,
                    'title' => 1.5,
                ],
            ],
            
            'return_fields' => [
                'id', 'title', 'internal_dia', 'outer_dia', 'width', 'weight',
                'slug', 'isbn_code', 'code', 'is_top', 'is_new', 'is_limited_quantity',
                'price', 'price_with_tax', 'parent_id', 'manufacture_id', 'quantity', 'no_discount',
                'authenticatedUserPrice' => [
                    'product_id',
                    'special_price',
                    'special_price_with_tax',
                ],
                'attachments' => [
                    'id',
                    'model_id',
                    'filepath',
                ],
                'manufacture' => [
                    'id',
                    'title',
                ],
                'parent' => [
                    'id',
                    'slug',
                ],
            ],
            
            'computed_fields' => [
                'size_terms' => [
                    'source_fields' => ['internal_dia', 'outer_dia', 'width'],
                    'type' => 'text',
                    'analyzer' => 'size_analyzer',
                ],
            ],
            
            'chunk_size' => 1000,
        ],

        // Manufacture model configuration
        'App\\Models\\Manufacture\\Manufacture' => [
            'index' => 'manufactures',
            
            'translatable' => [
                'enabled' => true,
                'fields' => ['title', 'slug'],
                'locales' => ['en', 'lv'],
            ],
            
            'searchable_fields' => [
                'title', 'slug', 'code',
            ],
            
            'searchable_fields_boost' => [
                'title' => 5.0,
                'code' => 4.0,
                'slug' => 2.0,
            ],
            
            'return_fields' => [
                'id', 'title', 'slug', 'code',
            ],
            
            'chunk_size' => 1000,
        ],

        // Category model configuration
        'App\\Models\\Category\\Category' => [
            'index' => 'categories',
            
            'translatable' => [
                'enabled' => true,
                'fields' => ['title', 'slug'],
                'locales' => ['en', 'lv'],
            ],
            
            'searchable_fields' => [
                'title', 'slug', 'code',
            ],
            
            'searchable_fields_boost' => [
                'title' => 5.0,
                'code' => 4.0,
                'slug' => 2.0,
            ],
            
            'return_fields' => [
                'id', 'title', 'slug', 'code',
            ],
            
            'chunk_size' => 1000,
        ],
    ],
];


