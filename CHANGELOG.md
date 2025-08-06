# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.4.2] - 2024-12-19

### Added
- **Performance Monitoring**: Added comprehensive logging for search performance metrics
- **Caching System**: Implemented intelligent caching for frequently accessed data
- **New Command**: Added `elastic:clear-cache` command for cache management
- **HTTP Client Optimization**: Enhanced Elasticsearch client configuration for better performance

### Performance Improvements
- **Search Field Caching**: Cache search field generation to avoid repeated processing
- **Autocomplete Field Caching**: Cache autocomplete field generation
- **Highlight Field Caching**: Cache highlight field configuration
- **Translatable Config Caching**: Cache translatable field configuration
- **Foreign Key Caching**: Cache foreign key resolution for relations
- **Field Detection Caching**: Cache translatable field detection results

### Monitoring & Logging
- **Search Performance Logging**: Log execution time, result count, and total hits for each search
- **Database Loading Logging**: Log performance metrics for database data loading
- **Error Logging**: Enhanced error logging with performance metrics
- **Cache Hit/Miss Tracking**: Track cache usage for optimization

### Technical Enhancements
- **HTTP Client Configuration**: Optimized connection settings with keep-alive support
- **Timeout Configuration**: Configurable timeouts for Elasticsearch requests
- **Connection Pooling**: Improved connection management for better performance
- **Cache Key Management**: Structured cache keys for better organization

### New Commands
- `php artisan elastic:clear-cache` - Clear all Elasticsearch-related cache
- `php artisan elastic:clear-cache --all` - Clear entire application cache

### Cache Management
The package now caches the following data for 1 hour:
- Search field configurations with boost values
- Autocomplete field lists
- Highlight field configurations
- Translatable field configurations
- Foreign key mappings for relations
- Translatable field detection results

### Performance Impact
- **Reduced CPU Usage**: Caching eliminates repeated field processing
- **Faster Search**: Pre-computed field lists reduce search preparation time
- **Better Monitoring**: Performance metrics help identify bottlenecks
- **Optimized Connections**: HTTP client optimizations reduce connection overhead

### Migration Guide
No breaking changes. The caching system is transparent and automatically improves performance.

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
- Improved translatable field detection and processing in search queries

### Features
- **Boost Configuration**: New `searchable_fields_boost` setting allows precise control over search relevance:
  ```php
  'searchable_fields_boost' => [
      'title' => 3.0,                    // High priority for title
      'category' => [
          'title' => 2.0,               // Medium priority for category title
          'manufacturer' => [
              'name' => 1.5,            // Medium priority for manufacturer name
          ]
      ],
  ],
  ```
- **Multilingual Search**: Automatic handling of translatable fields in search:
  - Regular fields: `title` → `title^3.0`
  - Translatable fields: `title` → `title_en^3.0`, `title_lv^3.0`
  - Relation fields: `category.title` → `category.title^2.0`
  - Translatable relation fields: `category.title` → `category.title_en^2.0`, `category.title_lv^2.0`

### Technical Details
- New method `getSearchFieldsWithBoost()` replaces old search field extraction logic
- Added `extractSearchFieldsWithBoostFromConfig()` for processing boost configuration
- Added `addFieldWithBoost()` and related methods for handling multilingual fields
- Updated `getAutocompleteFields()` and `getHighlightFields()` for multilingual support
- Enhanced `getTranslatableConfig()` and `isFieldTranslatable()` methods

### Migration Guide
⚠️ **ВНИМАНИЕ**: This version introduces new search logic. To use the new boost functionality:

1. Add `searchable_fields_boost` configuration to your models:
   ```php
   'searchable_fields_boost' => [
       'title' => 3.0,
       'description' => 2.0,
       'category' => ['title' => 2.0],
   ],
   ```

2. The search will automatically use boost values from this configuration
3. If no boost is specified for a field, it defaults to 1.0
4. Translatable fields are automatically expanded with language suffixes

### Fixed
- Improved search relevance through proper boost application
- Better handling of multilingual fields in search queries
- Enhanced support for nested relations in search configuration

## [0.4.0] - 2024-12-19

### Fixed
- **CRITICAL FIX**: Fixed translatable field detection logic to properly handle mixed structure in translatable_fields configuration
- Resolved issue where relation fields like `category.title` were not being recognized as translatable
- Added comprehensive logic to handle numeric keys in relation field arrays
- Removed debug logging from production code

### Breaking Changes
- This version fixes a critical bug in translatable field detection. Update from any previous version to ensure proper handling of translatable relation fields.

### Features
- Full support for translatable relation fields (e.g., `category.title_en`, `category.title_lv`)
- Proper JSON decoding and processing of translatable fields from database
- Support for nested relations with translatable fields

## [0.3.5] - 2024-12-19

### Fixed
- Added final check in translatable field detection to search for relations directly by translatableField key
- Enhanced logic to handle cases where relation fields are stored with numeric keys in nested arrays
- Improved debug output to show all checking steps for translatable field detection

## [0.3.4] - 2024-12-19

### Fixed
- Fixed translatable field detection logic to properly handle numeric keys in relation field arrays
- Added additional check for array values in relationFields when searching for translatable fields
- Resolved issue where relation fields like `category.title` were not being recognized due to PHP array key conversion in nested arrays

## [0.3.3] - 2024-12-19

### Added
- Added comprehensive debug dumps in `isFieldInTranslatableList` to show detailed structure of translatable_fields
- Added relation data dumps in `processRelationField` to show relation attributes and raw data
- Enhanced debug output to show step-by-step process of finding relations in translatable_fields

### Changed
- Improved debug information to help diagnose why relation fields are not being recognized as translatable

## [0.3.2] - 2024-12-19

### Fixed
- Fixed translatable field detection logic to properly handle mixed structure in translatable_fields configuration
- Added additional check for relation fields when translatable_fields contains both simple fields and relation arrays
- Resolved issue where relation fields like `category.title` were not being recognized due to PHP array key conversion

## [0.3.1] - 2024-12-19

### Added
- Added comprehensive debug logging to `processRelationField` and `processTranslatableRelationField` methods
- Enhanced debug output to show detailed field processing steps for relation fields
- Added logging for JSON decoding process in translatable relation fields

### Changed
- Improved debug information to help diagnose translatable field processing issues

## [0.3.0] - 2024-12-19

### Fixed
- Fixed translatable field detection logic in `isFieldInTranslatableList` method to properly handle numeric keys in translatable_fields configuration
- Resolved issue where relation fields like `category.title` were not being recognized as translatable due to incorrect array key handling
- Removed debug logging from production code

### Breaking Changes
- This version fixes a critical bug in translatable field detection. Update from any previous version to ensure proper handling of translatable relation fields.

## [0.2.9] - 2024-12-19

### Fixed
- Fixed translatable field detection logic in `isFieldInTranslatableList` method to properly handle numeric keys in translatable_fields configuration
- Resolved issue where relation fields like `category.title` were not being recognized as translatable due to incorrect array key handling

## [0.2.8] - 2024-12-19

### Added
- Added debug logging to `isFieldTranslatable` and `isFieldInTranslatableList` methods to help diagnose translatable field detection issues

### Changed
- Enhanced debug output to show detailed field matching process for translatable fields

## [0.2.7] - 2024-12-19

### Fixed
- **КРИТИЧЕСКОЕ ИСПРАВЛЕНИЕ**: Исправлена логика определения translatable полей в relations
- Исправлена проверка типов ключей в translatable_fields (is_numeric вместо is_string для числовых ключей)
- Восстановлена корректная обработка translatable relation полей (category.title_en, category.title_lv)
- Исправлен метод isFieldInTranslatableList для правильного определения translatable полей

## [0.2.6] - 2024-12-19

### Fixed
- **КРИТИЧЕСКОЕ ИСПРАВЛЕНИЕ**: Исправлена логика обработки полей в relations
- Исправлена проверка типов ключей в relation полях (is_numeric вместо is_string для числовых ключей)
- Восстановлена корректная индексация relation полей (category.title, category.title_en, category.title_lv)
- Исправлены методы: processSingleRelationFields, processMultipleRelationFields, processNestedRelationFields
- Исправлены методы маппинга: addRelationFieldsToMapping, addNestedRelationFieldsToMapping

## [0.2.5] - 2024-12-19

### Fixed
- **КРИТИЧЕСКОЕ ИСПРАВЛЕНИЕ**: Исправлена логика обработки простых полей в searchable_fields
- Исправлена проверка типов ключей в массивах (is_numeric вместо is_string для числовых ключей)
- Восстановлена корректная индексация всех полей

## [0.2.4] - 2024-12-19

### Fixed
- Исправлена логика индексирования полей в Elasticsearch
- Исправлена обработка структуры `searchable_fields` (числовые и строковые ключи)
- Исправлена поддержка translatable полей в relations
- Исправлена обработка вложенных relations
- Исправлена обработка множественных relations (коллекции)
- Убрано дублирование в конфигурации `searchable_fields`

### Added
- Полная поддержка translatable полей в relations
- Поддержка вложенных relations с translatable полями
- Поддержка множественных relations с translatable полями
- Автоматическое определение translatable полей в relations
- Улучшенная документация с примерами конфигурации

### Changed
- Обновлена структура конфигурации для лучшей читаемости
- Улучшена логика определения translatable полей
- Обновлены методы обработки полей для поддержки всех типов relations

### Technical Details
- Исправлен метод `buildMappingFromSearchableFields()` для правильной обработки структуры
- Исправлен метод `processSearchableFields()` для корректной обработки простых и relation полей
- Добавлен метод `processTranslatableMultipleRelations()` для обработки translatable коллекций
- Обновлен метод `isFieldInTranslatableList()` для поддержки вложенных relations

### Migration Guide
⚠️ **ВНИМАНИЕ**: Этот релиз содержит изменения в логике индексирования. Для корректной работы рекомендуется:

1. Обновить конфигурацию `searchable_fields`:
   ```php
   // Было (неправильно):
   'searchable_fields' => [
       'category' => ['title', 'slug'],
       'category' => ['manufacturer' => ['name']], // Дублирование!
   ],
   
   // Стало (правильно):
   'searchable_fields' => [
       'category' => [
           'title', 'slug',
           'manufacturer' => ['name']
       ],
   ],
   ```

2. Обновить конфигурацию `translatable_fields`:
   ```php
   // Было:
   'translatable_fields' => ['title', 'description'],
   
   // Стало (с поддержкой relations):
   'translatable_fields' => [
       'title', 'description',
       'category' => ['title', 'description'],
       'category' => ['manufacturer' => ['name']],
   ],
   ```

3. Удалить явные языковые поля из `searchable_fields`:
   ```php
   // Было:
   'searchable_fields' => ['title', 'title_en', 'title_lv'],
   
   // Стало:
   'searchable_fields' => ['title'], // Языковые поля создаются автоматически
   ```

## [0.2.3] - 2024-12-18

### Added
- Поддержка relations в translatable полях
- Упрощенная конфигурация searchable_fields
- Автоматическое определение translatable полей
- Поддержка вложенных relations

### Changed
- Обновлена логика обработки полей
- Улучшена поддержка многоязычных проектов

## [0.2.2] - 2024-12-17

### Fixed
- Исправлена обработка translatable полей
- Исправлена загрузка relations

### Added
- Поддержка computed_fields
- Улучшенная документация

## [0.2.1] - 2024-12-16

### Fixed
- Исправлены ошибки в командах индексации
- Исправлена обработка конфигурации

### Added
- Поддержка query_conditions
- Улучшенная обработка ошибок

## [0.2.0] - 2024-12-15

### Added
- Поддержка translatable полей
- Команды для индексации и поиска
- Конфигурация через config файл

### Changed
- Полная переработка архитектуры
- Улучшенная поддержка Elasticsearch 8.x

## [0.1.0] - 2024-12-14

### Added
- Базовая интеграция с Elasticsearch
- Простые команды для индексации
- Основная функциональность поиска 