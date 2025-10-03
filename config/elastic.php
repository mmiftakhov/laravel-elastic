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
        ],
        'tokenizer' => [
            'edge_ngram_tokenizer' => [
                'type' => 'edge_ngram',
                'min_gram' => 2,
                'max_gram' => 20,
                'token_chars' => ['letter', 'digit'],
            ],
        ],
    ],

    'search' => [
        'default' => [
            'limit' => 20,
            'offset' => 0,
            'type' => 'multi_match',
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
    ],
];


