# Документация изменений

Эта директория содержит документацию всех изменений в пакете `laravel-elastic`.

## Структура

```
docs/changes/
├── diary/          # Дневник изменений (хронологически)
│   └── YYYY/
│       └── MM/
│           └── YYYY-MM-DD-<branch>.md
└── tags/           # Изменения по скоупам (тематически)
    └── <scope>.md
```

## Дневник (Diary)

Файлы в `diary/` организованы по датам и содержат полную хронологию изменений:

- **Формат имени:** `YYYY-MM-DD-<branch>.md`
- **Пример:** `2025-10-03-main.md`
- **Содержание:** Все изменения за день в конкретной ветке

### Структура записи

```markdown
## HH:MM type[scope] — краткое описание
**Entry ID:** <ULID>
**Дата:** YYYY-MM-DD
**Ветка:** branch-name

### Файлы
- `path/to/file.php` (+10 −3)

### Что сделано
Описание изменений (3-4 предложения)

### Почему
Причина изменений (1-3 предложения)

### Влияние
- **API пакета:** описание
- **Обратная совместимость:** breaking changes или совместимо
- **Производительность:** описание
- **Конфигурация:** описание

### Проверено
- Тесты: новые|обновлены|N/A
- Линтер: ok|todo
- Совместимость: Laravel 10+|Laravel 11+

### Follow-up
- [ ] задача 1
- [ ] задача 2
```

## Теги (Tags)

Файлы в `tags/` организованы по компонентам (скоупам) и содержат ссылки на все изменения конкретного компонента:

### Доступные скоупы

- `core.search.md` — Основной класс поиска
- `core.indexing.md` — Логика индексации
- `core.mapping.md` — Работа с mapping полей
- `core.translatable.md` — Мультиязычность
- `provider.md` — Service Provider
- `command.index.md` — Команда индексации
- `command.search.md` — Команда поиска
- `command.cache.md` — Команда очистки кэша
- `config.elastic.md` — Конфигурация пакета
- `config.models.md` — Настройки моделей
- `config.search.md` — Настройки поиска
- `package.meta.md` — Метаданные пакета (composer.json)
- `docs.readme.md` — Документация (README)
- `docs.changelog.md` — История изменений (CHANGELOG)
- `release.md` — Релизный процесс

### Структура тега

```markdown
# Изменения: <scope>

- YYYY-MM-DD — краткое описание → [../../diary/YYYY/MM/YYYY-MM-DD-<branch>.md#entry_id]
- YYYY-MM-DD — краткое описание → [../../diary/YYYY/MM/YYYY-MM-DD-<branch>.md#entry_id]
```

## Типы изменений

- `feat` — новая функциональность
- `fix` — исправление бага
- `refactor` — рефакторинг
- `perf` — производительность
- `docs` — документация
- `config` — изменения конфигурации
- `chore` — рутинные задачи
- `release` — релиз новой версии
- `merge` — слияние веток

## Пример использования

### 1. Внесены изменения в поиск

**Измененные файлы:**
- `src/ElasticSearch.php`
- `config/elastic.php`

**Скоупы:**
- `core.search`
- `config.search`

**Создаются записи:**
1. `docs/changes/diary/2025/10/2025-10-03-main.md` (новая запись В КОНЕЦ)
2. `docs/changes/tags/core.search.md` (ссылка В КОНЕЦ)
3. `docs/changes/tags/config.search.md` (ссылка В КОНЕЦ)

### 2. Добавлена новая команда

**Новые файлы:**
- `src/Console/ExportCommand.php`

**Измененные файлы:**
- `src/ElasticServiceProvider.php`

**Скоупы:**
- `command.export`
- `provider`

**Создаются записи:**
1. `docs/changes/diary/2025/10/2025-10-03-feature-export.md` (новая запись В КОНЕЦ)
2. `docs/changes/tags/command.export.md` (новый файл)
3. `docs/changes/tags/provider.md` (ссылка В КОНЕЦ)

## Правила

1. **Всегда** документируй изменения сразу после их внесения
2. **Никогда** не редактируй старые записи (только добавляй новые В КОНЕЦ)
3. **Всегда** обновляй ВСЕ релевантные теги
4. **Никогда** не дублируй информацию (дневник — детали, теги — ссылки)
5. **Всегда** используй Entry ID для связи записей

## Entry ID

Для связи записей используется уникальный идентификатор (Entry ID):
- Формат: ULID (Universally Unique Lexicographically Sortable Identifier)
- Пример: `01J8VXYZ123456ABCDEF`
- Генерация: можно использовать timestamp в формате `<YYYY><MM><DD><HH><MM><SS>`

