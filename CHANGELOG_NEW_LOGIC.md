# Новая логика индексации - Изменения

## Обзор изменений

Обновлена логика индексации для поддержки relations в translatable полях и упрощения конфигурации searchable_fields.

## Основные изменения

### 1. Обновлена конфигурация translatable полей

**Было:**
```php
'translatable_fields' => [
    'title', 'slug', 'short_description', 'specification', 'description'
],
```

**Стало:**
```php
'translatable_fields' => [
    'title', 'slug', 'short_description', 'specification', 'description',
    // Поддержка relations
    'category' => ['title', 'description'],
    'brand' => ['name', 'description'],
    // Вложенные relations
    'category' => ['manufacturer' => ['name', 'code']]
],
```

### 2. Упрощена конфигурация searchable_fields

**Было:**
```php
'searchable_fields' => [
    'title' => [
        'type' => 'text',
        'analyzer' => 'english',
        'fields' => [
            'exact' => ['type' => 'text', 'analyzer' => 'exact_match'],
            'autocomplete' => ['type' => 'text', 'analyzer' => 'autocomplete'],
        ],
    ],
    'title_en' => ['type' => 'text', 'analyzer' => 'english'],
    'title_lv' => ['type' => 'text', 'analyzer' => 'latvian'],
    // ... много других полей
],
```

**Стало:**
```php
'searchable_fields' => [
    // Поля текущей модели
    'title', 'slug', 'short_description', 'specification', 'description',
    'is_active', 'created_at', 'updated_at',
    
    // Поля из relations (формат: вложенные массивы)
    'category' => ['title', 'slug', 'is_active'],
    'brand' => ['name', 'slug', 'logo'],
    
    // Вложенные relations
    'category' => ['manufacturer' => ['name', 'code']],
    
    // Поля из коллекций
    'images' => ['url', 'alt'],
],
```

### 3. Новая логика обработки полей

#### Поддержка relations в translatable полях
- Поля могут быть указаны как простые строки: `'title'`
- Поля могут быть указаны как relations: `'category' => ['title', 'description']`
- Поддержка вложенных relations: `'category' => ['manufacturer' => ['name', 'code']]`

#### Автоматическое определение translatable полей
- Система автоматически определяет, какие поля из searchable_fields являются translatable
- Для translatable полей создаются отдельные поля для каждого языка: `title_en`, `title_lv`
- Для relations: `category.title_en`, `category.title_lv`

#### Поддержка вложенных relations
- Поддержка полей вида: `category.manufacturer.name`
- Автоматическая загрузка всех необходимых relations
- Обработка как одиночных relations, так и коллекций

## Новые методы в IndexCommand

### `processSearchableFields($record, $searchableFields, $document, $translatableConfig)`
Основной метод для обработки всех полей из searchable_fields.

### `processRelationFields($record, $relationName, $relationFields, $document, $translatableConfig)`
Обрабатывает поля relations.

### `processSingleRelationFields($relation, $relationName, $relationFields, $document, $translatableConfig)`
Обрабатывает поля одиночного relation.

### `processMultipleRelationFields($relations, $relationName, $relationFields, $document, $translatableConfig)`
Обрабатывает поля множественного relation.

### `processNestedRelationFields($relation, $relationPath, $relationFields, $document, $translatableConfig)`
Обрабатывает вложенные relation поля.

### `processSimpleField($record, $field, $document, $translatableConfig)`
Обрабатывает простые поля (без relations).

### `processTranslatableSimpleField($record, $field, $document, $translatableConfig)`
Обрабатывает translatable простые поля.

### `processSingleRelation($relation, $relationField, $fullField, $document, $translatableConfig)`
Обрабатывает одиночное relation.

### `processTranslatableRelationField($relation, $relationField, $fullField, $document, $translatableConfig)`
Обрабатывает translatable поле в relation.

### `processMultipleRelations($relations, $relationField, $fullField, $document, $translatableConfig)`
Обрабатывает множественные relations (коллекции).

### `buildMappingFromSearchableFields($searchableFields, $properties, $translatableConfig)`
Строит маппинг из searchable_fields.

### `addFieldToMapping($field, $properties, $translatableConfig)`
Добавляет простое поле в маппинг.

### `addRelationFieldsToMapping($relationName, $relationFields, $properties, $translatableConfig)`
Добавляет поля relations в маппинг.

### `addNestedRelationFieldsToMapping($relationPath, $relationFields, $properties, $translatableConfig)`
Добавляет поля вложенных relations в маппинг.

### `isFieldTranslatable($field, $translatableConfig)`
Определяет, является ли поле translatable.

### `isFieldInTranslatableList($field, $translatableFields)`
Проверяет, есть ли поле в списке translatable полей.

### `getAnalyzerForLocale($locale)`
Получает анализатор для конкретного языка.

### `loadRelationsForSearchableFields($query, $config)`
Загружает relations, указанные в searchable_fields.

### `extractRelationsFromSearchableFields($searchableFields, $relationsToLoad)`
Извлекает relations из searchable_fields.

### `extractNestedRelations($parentPath, $fields, $relationsToLoad)`
Извлекает вложенные relations.

## Пример результата индексации

Для конфигурации:
```php
'searchable_fields' => [
    'title', 'category' => ['title'], 'brand' => ['name'], 'category' => ['manufacturer' => ['name']]
],
'translatable_fields' => [
    'title', 'category' => ['title'], 'brand' => ['name'], 'category' => ['manufacturer' => ['name']]
],
```

Результат будет:
```php
[
    'title_en' => 'iPhone 15 Pro',
    'title_lv' => 'iPhone 15 Pro',
    'category.title_en' => 'Smartphones',
    'category.title_lv' => 'Viedtālruņi',
    'brand.name_en' => 'Apple',
    'brand.name_lv' => 'Apple',
    'category.manufacturer.name_en' => 'Foxconn',
    'category.manufacturer.name_lv' => 'Foxconn',
]
```

## Преимущества новой логики

1. **Упрощенная конфигурация** - searchable_fields теперь простой массив строк
2. **Поддержка relations** - можно указывать поля из связанных моделей
3. **Вложенные relations** - поддержка многоуровневых связей
4. **Автоматическая загрузка** - relations загружаются автоматически
5. **Единообразное именование** - все поля именуются одинаково
6. **Поддержка коллекций** - обработка один-ко-многим отношений
7. **Гибкость translatable полей** - поддержка relations в translatable конфигурации

## Обратная совместимость

⚠️ **ВНИМАНИЕ**: Эти изменения не обратно совместимы с предыдущей версией.

Для миграции на новую логику необходимо:
1. Обновить конфигурацию translatable_fields для поддержки relations
2. Изменить searchable_fields на новую структуру с вложенными массивами
3. Удалить явные языковые поля (title_en, title_lv и т.д.)
4. Обновить поисковые запросы для работы с новой структурой полей

## Финальные изменения в версии 0.2.3

### Удалены неиспользуемые методы из IndexCommand:
- `processTranslatableFields()` - заменен новой логикой
- `getTranslatableFields()` - больше не используется
- `isTranslatableField()` - заменен на `isFieldTranslatable()`
- `getFirstAvailableValue()` - больше не используется

### Обновлен класс ElasticSearch:
- `getSearchFields()` - теперь поддерживает новую структуру searchable_fields
- `getAutocompleteFields()` - обновлен для работы с relations
- `getHighlightFields()` - обновлен для работы с relations
- Добавлены новые вспомогательные методы для извлечения полей

### Новая структура полностью поддерживает:
- Relations через вложенные массивы
- Вложенные relations (например, category.manufacturer.name)
- Автоматическое определение translatable полей
- Автоматическую загрузку relations
- Единообразное именование полей в индексе 