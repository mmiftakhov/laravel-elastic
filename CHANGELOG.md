# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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