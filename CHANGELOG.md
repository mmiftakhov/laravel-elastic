# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.4.3] - 2024-12-19

### Added
- **NEW FEATURE**: Added `load_from_db` option to control database data loading
- **Performance Optimization**: Enhanced data extraction from Elasticsearch results
- **Advanced Caching**: Added caching for model class resolution and index existence checks
- **Flexible Data Loading**: Option to return only Elasticsearch data without database queries

### Changed
- **Performance Improvement**: Optimized ID extraction from Elasticsearch hits using direct iteration
- **Enhanced Logging**: Added `load_from_db` parameter to search performance logs
- **Caching Strategy**: Extended caching to model class resolution and index existence checks
- **Data Processing**: Improved handling of search results with optional database loading

### Performance Improvements
- **Faster ID Extraction**: Replaced collection operations with direct array iteration for better performance
- **Reduced Database Queries**: New `load_from_db` option allows skipping database queries when not needed
- **Cached Model Resolution**: Cache model class resolution to avoid repeated configuration lookups
- **Cached Index Checks**: Cache index existence checks to reduce Elasticsearch API calls

### Usage Examples
```php
// Search with database loading (default behavior)
$results = $elasticSearch->search('App\Models\Product', 'query');

// Search without database loading (faster, returns only Elasticsearch data)
$results = $elasticSearch->search('App\Models\Product', 'query', [
    'load_from_db' => false
]);
```

## [0.4.2] - 2024-12-19

### Added
- **Performance Monitoring**: Added comprehensive logging for search performance metrics
- **Caching System**: Implemented intelligent caching for frequently accessed data
- **New Command**: Added `elastic:clear-cache` command for cache management
- **HTTP Client Optimization**: Enhanced Elasticsearch client configuration for better performance

### Performance Improvements
- **Search Field Caching**: Cache search field generation to avoid repeated processing
- **Autocomplete Field Caching**: Cache autocomplete field generation
- **Highlight Field Caching**: Cache highlight field generation
- **Database Query Caching**: Cache database query results for better performance
- **Relation Query Caching**: Cache relation query building for complex relationships

### New Commands
- `php artisan elastic:clear-cache` - Clear Elasticsearch cache
- `php artisan elastic:clear-cache --all` - Clear all application cache

### Configuration Enhancements
- **HTTP Client Settings**: Added keep-alive, timeout, and connection pool settings
- **Cache TTL**: Configurable cache time-to-live for different data types
- **Performance Logging**: Detailed logging of search execution times and result counts

## [0.4.1] - 2024-12-19

### Added
- **NEW FEATURE**: Added `searchable_fields_boost` configuration for fine-tuning search relevance
- Support for boost values in searchable fields with relations structure
- Automatic handling of multilingual fields in search queries
- Enhanced search logic with proper boost application for translatable fields

### Changed
- **BREAKING CHANGE**: Updated search logic to use new `searchable_fields_boost` configuration
- Replaced old `getSearchFields()` method with new `getSearchFieldsWithBoost()` method
- Updated autocomplete and highlight functions to support multilingual fields
- Enhanced translatable field detection and processing

### Configuration Example
```php
'searchable_fields_boost' => [
    'title' => 3.0,                    // High priority for title
    'slug' => 2.5,                     // High priority for slug
    'short_description' => 2.0,        // Medium priority for short description
    'specification' => 1.5,            // Lower priority for specification
    'category' => [
        'title' => 2.0,                // Boost for category title
        'description' => 1.5,          // Boost for category description
        'manufacturer' => [
            'name' => 1.8,             // Boost for nested manufacturer name
            'code' => 1.2              // Boost for nested manufacturer code
        ]
    ],
    'brand' => [
        'name' => 2.2,                 // Boost for brand name
        'slug' => 1.8                  // Boost for brand slug
    ]
]
```

## [0.4.0] - 2024-12-19

### Added
- **NEW FEATURE**: Added `searchable_fields_boost` configuration for fine-tuning search relevance
- Support for boost values in searchable fields with relations structure
- Automatic handling of multilingual fields in search queries
- Enhanced search logic with proper boost application for translatable fields

### Changed
- **BREAKING CHANGE**: Updated search logic to use new `searchable_fields_boost` configuration
- Replaced old `getSearchFields()` method with new `getSearchFieldsWithBoost()` method
- Updated autocomplete and highlight functions to support multilingual fields
- Enhanced translatable field detection and processing

### Configuration Example
```php
'searchable_fields_boost' => [
    'title' => 3.0,                    // High priority for title
    'slug' => 2.5,                     // High priority for slug
    'short_description' => 2.0,        // Medium priority for short description
    'specification' => 1.5,            // Lower priority for specification
    'category' => [
        'title' => 2.0,                // Boost for category title
        'description' => 1.5,          // Boost for category description
        'manufacturer' => [
            'name' => 1.8,             // Boost for nested manufacturer name
            'code' => 1.2              // Boost for nested manufacturer code
        ]
    ],
    'brand' => [
        'name' => 2.2,                 // Boost for brand name
        'slug' => 1.8                  // Boost for brand slug
    ]
]
```

## [0.3.0] - 2024-12-19

### Added
- **NEW FEATURE**: Added `searchable_fields_boost` configuration for fine-tuning search relevance
- Support for boost values in searchable fields with relations structure
- Automatic handling of multilingual fields in search queries
- Enhanced search logic with proper boost application for translatable fields

### Changed
- **BREAKING CHANGE**: Updated search logic to use new `searchable_fields_boost` configuration
- Replaced old `getSearchFields()` method with new `getSearchFieldsWithBoost()` method
- Updated autocomplete and highlight functions to support multilingual fields
- Enhanced translatable field detection and processing

### Configuration Example
```php
'searchable_fields_boost' => [
    'title' => 3.0,                    // High priority for title
    'slug' => 2.5,                     // High priority for slug
    'short_description' => 2.0,        // Medium priority for short description
    'specification' => 1.5,            // Lower priority for specification
    'category' => [
        'title' => 2.0,                // Boost for category title
        'description' => 1.5,          // Boost for category description
        'manufacturer' => [
            'name' => 1.8,             // Boost for nested manufacturer name
            'code' => 1.2              // Boost for nested manufacturer code
        ]
    ],
    'brand' => [
        'name' => 2.2,                 // Boost for brand name
        'slug' => 1.8                  // Boost for brand slug
    ]
]
```

## [0.2.0] - 2024-12-19

### Added
- **NEW FEATURE**: Added `searchable_fields_boost` configuration for fine-tuning search relevance
- Support for boost values in searchable fields with relations structure
- Automatic handling of multilingual fields in search queries
- Enhanced search logic with proper boost application for translatable fields

### Changed
- **BREAKING CHANGE**: Updated search logic to use new `searchable_fields_boost` configuration
- Replaced old `getSearchFields()` method with new `getSearchFieldsWithBoost()` method
- Updated autocomplete and highlight functions to support multilingual fields
- Enhanced translatable field detection and processing

### Configuration Example
```php
'searchable_fields_boost' => [
    'title' => 3.0,                    // High priority for title
    'slug' => 2.5,                     // High priority for slug
    'short_description' => 2.0,        // Medium priority for short description
    'specification' => 1.5,            // Lower priority for specification
    'category' => [
        'title' => 2.0,                // Boost for category title
        'description' => 1.5,          // Boost for category description
        'manufacturer' => [
            'name' => 1.8,             // Boost for nested manufacturer name
            'code' => 1.2              // Boost for nested manufacturer code
        ]
    ],
    'brand' => [
        'name' => 2.2,                 // Boost for brand name
        'slug' => 1.8                  // Boost for brand slug
    ]
]
```

## [0.1.0] - 2024-12-19

### Added
- **NEW FEATURE**: Added `searchable_fields_boost` configuration for fine-tuning search relevance
- Support for boost values in searchable fields with relations structure
- Automatic handling of multilingual fields in search queries
- Enhanced search logic with proper boost application for translatable fields

### Changed
- **BREAKING CHANGE**: Updated search logic to use new `searchable_fields_boost` configuration
- Replaced old `getSearchFields()` method with new `getSearchFieldsWithBoost()` method
- Updated autocomplete and highlight functions to support multilingual fields
- Enhanced translatable field detection and processing

### Configuration Example
```php
'searchable_fields_boost' => [
    'title' => 3.0,                    // High priority for title
    'slug' => 2.5,                     // High priority for slug
    'short_description' => 2.0,        // Medium priority for short description
    'specification' => 1.5,            // Lower priority for specification
    'category' => [
        'title' => 2.0,                // Boost for category title
        'description' => 1.5,          // Boost for category description
        'manufacturer' => [
            'name' => 1.8,             // Boost for nested manufacturer name
            'code' => 1.2              // Boost for nested manufacturer code
        ]
    ],
    'brand' => [
        'name' => 2.2,                 // Boost for brand name
        'slug' => 1.8                  // Boost for brand slug
    ]
]
```

## [0.0.1] - 2024-12-19

### Added
- Initial release of Laravel Elastic package
- Basic Elasticsearch integration for Laravel applications
- Indexing and search functionality
- Support for model relations and multilingual fields
- Configuration-based setup for easy integration 