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
    
    // Поля из relations (формат: "relation.field")
    'category.title', 'category.slug', 'category.is_active',
    'brand.name', 'brand.slug', 'brand.logo',
    
    // Вложенные relations (формат: "relation.nested.field")
    'category.manufacturer.name', 'category.manufacturer.code',
    
    // Поля из коллекций
    'images.url', 'images.alt',
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

### `processField($record, $field, $document, $translatableConfig)`
Основной метод для обработки отдельного поля.

### `processSimpleField($record, $field, $document, $translatableConfig)`
Обрабатывает простые поля (без relations).

### `processTranslatableSimpleField($record, $field, $document, $translatableConfig)`
Обрабатывает translatable простые поля.

### `processRelationField($record, $field, $document, $translatableConfig)`
Обрабатывает поля с relations.

### `processSimpleRelationField($record, $relationName, $relationField, $fullField, $document, $translatableConfig)`
Обрабатывает простые relation поля (один уровень).

### `processNestedRelationField($record, $parts, $fullField, $document, $translatableConfig)`
Обрабатывает вложенные relation поля (несколько уровней).

### `processSingleRelation($relation, $relationField, $fullField, $document, $translatableConfig)`
Обрабатывает одиночное relation.

### `processTranslatableRelationField($relation, $relationField, $fullField, $document, $translatableConfig)`
Обрабатывает translatable поле в relation.

### `processMultipleRelations($relations, $relationField, $fullField, $document, $translatableConfig)`
Обрабатывает множественные relations (коллекции).

### `isFieldTranslatable($field, $translatableConfig)`
Определяет, является ли поле translatable.

### `isFieldInTranslatableList($field, $translatableFields)`
Проверяет, есть ли поле в списке translatable полей.

### `getAnalyzerForLocale($locale)`
Получает анализатор для конкретного языка.

### `loadRelationsForSearchableFields($query, $config)`
Загружает relations, указанные в searchable_fields.

## Пример результата индексации

Для конфигурации:
```php
'searchable_fields' => [
    'title', 'category.title', 'brand.name', 'category.manufacturer.name'
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
1. Обновить конфигурацию translatable_fields
2. Упростить searchable_fields до массива строк
3. Удалить явные языковые поля (title_en, title_lv и т.д.)
4. Обновить поисковые запросы для работы с новой структурой полей 