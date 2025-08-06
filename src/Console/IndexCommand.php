<?php

namespace Maratmiftahov\LaravelElastic\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Elastic\Elasticsearch\Client;

/**
 * Команда для индексации моделей в Elasticsearch
 * 
 * Эта команда позволяет индексировать модели Laravel в Elasticsearch
 * с поддержкой различных опций: создание индексов, удаление, переиндексация.
 * 
 * Поддерживаемые операции:
 * - Индексация всех моделей или конкретной модели
 * - Создание индексов без данных
 * - Удаление существующих индексов
 * - Принудительная переиндексация (удаление + создание + индексация)
 * - Настройка размера чанков для bulk операций
 */
class IndexCommand extends Command
{
    /**
     * Сигнатура команды с доступными опциями
     * 
     * Опции:
     * --model=MODEL    - Индексировать только конкретную модель
     * --chunk=SIZE     - Размер чанка для bulk индексации
     * --create-only    - Только создать индекс без индексации данных
     * --delete-only    - Только удалить существующие индексы
     * --reindex        - Переиндексация (удалить + создать + индексировать)
     */
    protected $signature = 'elastic:index {--model=} {--chunk=} {--create-only} {--delete-only} {--reindex}';

    /**
     * Описание команды для справки
     */
    protected $description = 'Index models into Elasticsearch

Options:
  --model=MODEL    Index only a specific model (e.g., "App\\Models\\Product")
  --chunk=SIZE     Set chunk size for bulk indexing (default: from config)
  --create-only    Only create index without indexing data
  --delete-only    Only delete existing index
  --reindex        Reindex data (delete and recreate index)';

    /**
     * Экземпляр клиента Elasticsearch
     * 
     * Используется для всех операций с Elasticsearch API
     */
    protected Client $elasticsearch;

    /**
     * Основной метод выполнения команды
     * 
     * @param Client $elasticsearch Экземпляр клиента Elasticsearch (внедряется через DI)
     * @return int Код возврата (0 - успех, 1 - ошибка)
     */
    public function handle(Client $elasticsearch): int
    {
        $this->elasticsearch = $elasticsearch;

        $this->info('Starting Elasticsearch indexing...');

        // Получаем модели для индексации из конфигурации
        $models = $this->getModelsToIndex();
        
        if (empty($models)) {
            $this->error('No models configured for indexing.');
            return 1;
        }

        // Обрабатываем специальные операции
        if ($this->option('delete-only')) {
            return $this->deleteIndexes($models);
        }

        if ($this->option('create-only')) {
            return $this->createIndexes($models);
        }

        if ($this->option('reindex')) {
            return $this->reindexModels($models);
        }

        // Создаем прогресс-бар для отображения процесса
        $bar = $this->output->createProgressBar(count($models));
        $bar->start();

        // Индексируем каждую модель
        foreach ($models as $modelClass => $config) {
            try {
                $this->indexModel($modelClass, $config);
                $bar->advance();
            } catch (\Exception $e) {
                $this->error("\nFailed to index {$modelClass}: " . $e->getMessage());
                return 1;
            }
        }

        $bar->finish();
        $this->newLine();
        $this->info('Indexing completed successfully!');

        return 0;
    }

    /**
     * Получает модели для индексации на основе конфигурации и опций
     * 
     * @return array Массив моделей с их конфигурацией
     * @throws \InvalidArgumentException Если указанная модель не найдена в конфигурации
     */
    protected function getModelsToIndex(): array
    {
        $models = Config::get('elastic.models', []);
        
        // Если указана конкретная модель, возвращаем только её
        if ($specificModel = $this->option('model')) {
            if (!isset($models[$specificModel])) {
                throw new \InvalidArgumentException("Model {$specificModel} is not configured for indexing.");
            }
            return [$specificModel => $models[$specificModel]];
        }

        return $models;
    }

    /**
     * Индексирует конкретную модель
     * 
     * @param string $modelClass Полное имя класса модели
     * @param array $config Конфигурация модели
     * @param bool $forceReindex Принудительная переиндексация (удалить существующий индекс)
     */
    protected function indexModel(string $modelClass, array $config, bool $forceReindex = false): void
    {
        $this->line("\nIndexing {$modelClass}...");

        // Получаем имя индекса
        $indexName = $this->getIndexName($config);
        
        // Проверяем существование индекса
        if ($this->indexExists($indexName) && !$forceReindex) {
            $this->warn("Index {$indexName} already exists. Use --reindex to force reindexing.");
            return;
        }

        // Создаем или пересоздаем индекс
        $this->createIndex($indexName, $config, $forceReindex);

        // Создаем экземпляр модели
        $model = new $modelClass();
        
        // Применяем условия запроса из конфигурации
        $query = $this->applyQueryConditions($model, $config);
        
        // Получаем общее количество записей
        $total = $query->count();
        $this->line("Found {$total} records to index.");

        if ($total === 0) {
            $this->warn("No records found for {$modelClass}.");
            return;
        }

        // Определяем размер чанка для индексации
        $configChunkSize = $config['chunk_size'] ?? 1000;
        $optionChunkSize = $this->option('chunk');
        
        if ($optionChunkSize) {
            $chunkSize = (int) $optionChunkSize;
        } else {
            $chunkSize = $configChunkSize;
        }
        
        $this->line("Using chunk size: {$chunkSize}");

        // Создаем прогресс-бар для отображения процесса индексации
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        // Индексируем данные чанками для оптимизации памяти
        $query->chunk($chunkSize, function ($records) use ($indexName, $config, $bar) {
            $this->indexChunk($records, $indexName, $config);
            $bar->advance($records->count());
        });

        $bar->finish();
        $this->newLine();
        
        $this->info("Successfully indexed {$total} records for {$modelClass}.");
    }

    /**
     * Получает имя индекса для модели
     * 
     * @param array $config Конфигурация модели
     * @return string Имя индекса с префиксом (если настроен)
     */
    protected function getIndexName(array $config): string
    {
        $indexName = $config['index'];
        $prefix = Config::get('elastic.index.prefix', '');
        
        if ($prefix) {
            $indexName = $prefix . '_' . $indexName;
        }

        return $indexName;
    }

    /**
     * Проверяет существование индекса в Elasticsearch
     * 
     * @param string $indexName Имя индекса
     * @return bool True если индекс существует, false в противном случае
     */
    protected function indexExists(string $indexName): bool
    {
        try {
            // Используем API Elasticsearch 8.x для проверки существования индекса
            $response = $this->elasticsearch->indices()->exists(['index' => $indexName]);
            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Создает индекс с правильным маппингом
     * 
     * @param string $indexName Имя индекса
     * @param array $config Конфигурация модели
     * @param bool $forceReindex Принудительная переиндексация (удалить существующий индекс)
     */
    protected function createIndex(string $indexName, array $config, bool $forceReindex = false): void
    {
        $this->line("Creating index: {$indexName}");

        // Удаляем существующий индекс если требуется принудительная переиндексация
        if ($forceReindex && $this->indexExists($indexName)) {
            $this->elasticsearch->indices()->delete(['index' => $indexName]);
            $this->line("Deleted existing index: {$indexName}");
        }

        // Подготавливаем настройки индекса
        $settings = $this->getIndexSettings($config);
        
        // Подготавливаем маппинг
        $mapping = $this->getIndexMapping($config);

        // Создаем индекс с настройками и маппингом
        $params = [
            'index' => $indexName,
            'body' => [
                'settings' => $settings,
                'mappings' => $mapping,
            ],
        ];

        $this->elasticsearch->indices()->create($params);
        $this->line("Created index: {$indexName}");
    }

    /**
     * Получает настройки индекса из конфигурации
     * 
     * Объединяет настройки по умолчанию, настройки модели и настройки анализа
     * для создания полной конфигурации индекса Elasticsearch
     * 
     * @param array $config Конфигурация модели
     * @return array Настройки индекса для Elasticsearch
     */
    protected function getIndexSettings(array $config): array
    {
        $defaultSettings    = Config::get('elastic.index', []);
        $modelSettings      = $config['index_settings'] ?? [];
        $analysisSettings   = Config::get('elastic.analysis', []);
        
        return array_merge([
            'number_of_shards'   => $defaultSettings['number_of_shards'] ?? 1,
            'number_of_replicas' => $defaultSettings['number_of_replicas'] ?? 0,
            'analysis'           => $analysisSettings,
        ], $modelSettings);
    }

    /**
     * Создает маппинг индекса из конфигурации
     * 
     * Преобразует конфигурацию полей модели в маппинг Elasticsearch
     * с поддержкой relations и translatable полей
     * 
     * @param array $config Конфигурация модели
     * @return array Маппинг для Elasticsearch
     */
    protected function getIndexMapping(array $config): array
    {
        $properties = [];
        $translatableConfig = $this->getTranslatableConfig($config);

        // Добавляем поля для поиска
        $this->buildMappingFromSearchableFields($config['searchable_fields'] ?? [], $properties, $translatableConfig);

        // Добавляем вычисляемые поля
        foreach ($config['computed_fields'] ?? [] as $field => $fieldConfig) {
            $properties[$field] = [
                'type' => $fieldConfig['type'] ?? 'text',
            ];

            if (isset($fieldConfig['analyzer'])) {
                $properties[$field]['analyzer'] = $fieldConfig['analyzer'];
            }
        }

        return [
            'properties' => $properties,
        ];
    }

    /**
     * Строит маппинг из searchable_fields
     * 
     * @param array $searchableFields Поля для поиска
     * @param array $properties Свойства маппинга
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function buildMappingFromSearchableFields(array $searchableFields, array &$properties, array $translatableConfig): void
    {
        foreach ($searchableFields as $field => $fieldConfig) {
            if (is_string($field)) {
                // Простое поле
                $this->addFieldToMapping($field, $properties, $translatableConfig);
            } elseif (is_array($fieldConfig)) {
                // Relation поля
                $this->addRelationFieldsToMapping($field, $fieldConfig, $properties, $translatableConfig);
            }
        }
    }

    /**
     * Добавляет простое поле в маппинг
     * 
     * @param string $field Имя поля
     * @param array $properties Свойства маппинга
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function addFieldToMapping(string $field, array &$properties, array $translatableConfig): void
    {
        $isTranslatable = $this->isFieldTranslatable($field, $translatableConfig);
        
        if ($isTranslatable) {
            // Для translatable полей создаем поля для каждого языка
            foreach ($translatableConfig['locales'] as $locale) {
                $localizedField = $field . '_' . $locale;
                $properties[$localizedField] = [
                    'type' => 'text',
                    'analyzer' => $this->getAnalyzerForLocale($locale),
                ];
            }
        } else {
            // Для обычных полей создаем одно поле
            $properties[$field] = [
                'type' => 'text',
                'analyzer' => 'standard',
            ];
        }
    }

    /**
     * Добавляет поля relations в маппинг
     * 
     * @param string $relationName Имя relation
     * @param array $relationFields Поля relation
     * @param array $properties Свойства маппинга
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function addRelationFieldsToMapping(string $relationName, array $relationFields, array &$properties, array $translatableConfig): void
    {
        foreach ($relationFields as $field => $fieldConfig) {
            if (is_string($field)) {
                // Простое поле в relation
                $fullField = $relationName . '.' . $field;
                $this->addFieldToMapping($fullField, $properties, $translatableConfig);
            } elseif (is_array($fieldConfig)) {
                // Вложенное relation
                $this->addNestedRelationFieldsToMapping($relationName . '.' . $field, $fieldConfig, $properties, $translatableConfig);
            }
        }
    }

    /**
     * Добавляет поля вложенных relations в маппинг
     * 
     * @param string $relationPath Путь к relation
     * @param array $relationFields Поля relation
     * @param array $properties Свойства маппинга
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function addNestedRelationFieldsToMapping(string $relationPath, array $relationFields, array &$properties, array $translatableConfig): void
    {
        foreach ($relationFields as $field => $fieldConfig) {
            if (is_string($field)) {
                // Простое поле во вложенном relation
                $fullField = $relationPath . '.' . $field;
                $this->addFieldToMapping($fullField, $properties, $translatableConfig);
            } elseif (is_array($fieldConfig)) {
                // Еще более вложенное relation
                $this->addNestedRelationFieldsToMapping($relationPath . '.' . $field, $fieldConfig, $properties, $translatableConfig);
            }
        }
    }

    /**
     * Определяет, является ли поле translatable
     * 
     * @param string $field Имя поля (может содержать relations)
     * @param array $translatableConfig Конфигурация translatable полей
     * @return bool True, если поле translatable
     */
    protected function isFieldTranslatable(string $field, array $translatableConfig): bool
    {
        if (!$translatableConfig['auto_detect_translatable']) {
            return $this->isFieldInTranslatableList($field, $translatableConfig['translatable_fields'] ?? []);
        }
        
        // Для auto_detect проверяем по списку translatable_fields
        return $this->isFieldInTranslatableList($field, $translatableConfig['translatable_fields'] ?? []);
    }

    /**
     * Проверяет, есть ли поле в списке translatable полей
     * 
     * @param string $field Имя поля
     * @param array $translatableFields Список translatable полей
     * @return bool True, если поле найдено в списке
     */
    protected function isFieldInTranslatableList(string $field, array $translatableFields): bool
    {
        foreach ($translatableFields as $translatableField) {
            if (is_string($translatableField)) {
                // Простое поле
                if ($field === $translatableField) {
                    return true;
                }
            } elseif (is_array($translatableField)) {
                // Relation поле - проверяем все поля в relation
                foreach ($translatableField as $relationField => $relationFields) {
                    if (is_string($relationFields)) {
                        // Простое поле в relation
                        if ($field === $relationField . '.' . $relationFields) {
                            return true;
                        }
                    } elseif (is_array($relationFields)) {
                        // Массив полей в relation
                        foreach ($relationFields as $subField) {
                            if (is_string($subField)) {
                                if ($field === $relationField . '.' . $subField) {
                                    return true;
                                }
                            } elseif (is_array($subField)) {
                                // Вложенные relations
                                foreach ($subField as $nestedRelation => $nestedFields) {
                                    if (is_array($nestedFields)) {
                                        foreach ($nestedFields as $nestedField) {
                                            if ($field === $relationField . '.' . $nestedRelation . '.' . $nestedField) {
                                                return true;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        
        return false;
    }

    /**
     * Получает анализатор для конкретного языка
     * 
     * @param string $locale Код языка
     * @return string Название анализатора
     */
    protected function getAnalyzerForLocale(string $locale): string
    {
        $analyzers = [
            'en' => 'english',
            'lv' => 'latvian',
            'ru' => 'russian',
        ];
        
        return $analyzers[$locale] ?? 'standard';
    }

    /**
     * Индексирует чанк записей в Elasticsearch
     * 
     * Использует bulk API Elasticsearch для эффективной индексации
     * множества документов за одну операцию
     * 
     * @param \Illuminate\Database\Eloquent\Collection $records Коллекция записей для индексации
     * @param string $indexName Имя индекса
     * @param array $config Конфигурация модели
     */
    protected function indexChunk($records, string $indexName, array $config): void
    {
        $body = [];

        foreach ($records as $record) {
            // Подготавливаем данные документа
            $document = $this->prepareDocument($record, $config);
            
            // Добавляем метаданные для bulk операции
            $body[] = [
                'index' => [
                    '_index' => $indexName,
                    '_id' => $record->getKey(),
                ],
            ];
            
            // Добавляем сам документ
            $body[] = $document;
        }

        if (!empty($body)) {
            // Выполняем bulk операцию для индексации всех документов
            $this->elasticsearch->bulk(['body' => $body]);
        }
    }

    /**
     * Подготавливает данные документа для индексации
     * 
     * Собирает все необходимые поля из записи модели
     * включая поля для поиска, translatable поля и вычисляемые поля
     * Поддерживает relations и translatable поля
     * 
     * @param \Illuminate\Database\Eloquent\Model $record Запись модели
     * @param array $config Конфигурация модели
     * @return array Данные документа для Elasticsearch
     */
    protected function prepareDocument($record, array $config): array
    {
        $document = [];
        $translatableConfig = $this->getTranslatableConfig($config);

        // Обрабатываем все поля из searchable_fields
        $this->processSearchableFields($record, $config['searchable_fields'] ?? [], $document, $translatableConfig);

        // Добавляем вычисляемые поля
        foreach ($config['computed_fields'] ?? [] as $field => $fieldConfig) {
            $document[$field] = $this->computeField($record, $fieldConfig);
        }

        return $document;
    }

    /**
     * Обрабатывает все поля из searchable_fields
     * 
     * @param \Illuminate\Database\Eloquent\Model $record Запись модели
     * @param array $searchableFields Массив полей для поиска
     * @param array $document Текущий документ
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function processSearchableFields($record, array $searchableFields, array &$document, array $translatableConfig): void
    {
        foreach ($searchableFields as $field => $fieldConfig) {
            if (is_string($field)) {
                // Простое поле
                $this->processSimpleField($record, $field, $document, $translatableConfig);
            } elseif (is_array($fieldConfig)) {
                // Relation поле
                $this->processRelationFields($record, $field, $fieldConfig, $document, $translatableConfig);
            }
        }
    }

    /**
     * Обрабатывает поля relations
     * 
     * @param \Illuminate\Database\Eloquent\Model $record Запись модели
     * @param string $relationName Имя relation
     * @param array $relationFields Поля relation
     * @param array $document Текущий документ
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function processRelationFields($record, string $relationName, array $relationFields, array &$document, array $translatableConfig): void
    {
        // Проверяем, загружено ли relation
        if (!$record->relationLoaded($relationName)) {
            return;
        }

        $relation = $record->getRelation($relationName);

        if ($relation instanceof \Illuminate\Database\Eloquent\Model) {
            // Один к одному или многие к одному
            $this->processSingleRelationFields($relation, $relationName, $relationFields, $document, $translatableConfig);
        } elseif ($relation instanceof \Illuminate\Database\Eloquent\Collection) {
            // Один ко многим или многие ко многим
            $this->processMultipleRelationFields($relation, $relationName, $relationFields, $document, $translatableConfig);
        }
    }

    /**
     * Обрабатывает поля одиночного relation
     * 
     * @param \Illuminate\Database\Eloquent\Model $relation Модель relation
     * @param string $relationName Имя relation
     * @param array $relationFields Поля relation
     * @param array $document Текущий документ
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function processSingleRelationFields($relation, string $relationName, array $relationFields, array &$document, array $translatableConfig): void
    {
        foreach ($relationFields as $field => $fieldConfig) {
            if (is_string($field)) {
                // Простое поле в relation
                $fullField = $relationName . '.' . $field;
                $this->processRelationField($relation, $field, $fullField, $document, $translatableConfig);
            } elseif (is_array($fieldConfig)) {
                // Вложенное relation
                $this->processNestedRelationFields($relation, $relationName . '.' . $field, $fieldConfig, $document, $translatableConfig);
            }
        }
    }

    /**
     * Обрабатывает поля множественного relation
     * 
     * @param \Illuminate\Database\Eloquent\Collection $relations Коллекция relations
     * @param string $relationName Имя relation
     * @param array $relationFields Поля relation
     * @param array $document Текущий документ
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function processMultipleRelationFields($relations, string $relationName, array $relationFields, array &$document, array $translatableConfig): void
    {
        foreach ($relationFields as $field => $fieldConfig) {
            if (is_string($field)) {
                // Простое поле в коллекции
                $fullField = $relationName . '.' . $field;
                $this->processMultipleRelations($relations, $field, $fullField, $document, $translatableConfig);
            } elseif (is_array($fieldConfig)) {
                // Вложенное relation в коллекции (берем первый элемент)
                if ($relations->count() > 0) {
                    $firstRelation = $relations->first();
                    $this->processNestedRelationFields($firstRelation, $relationName . '.' . $field, $fieldConfig, $document, $translatableConfig);
                }
            }
        }
    }

    /**
     * Обрабатывает вложенные relation поля
     * 
     * @param \Illuminate\Database\Eloquent\Model $relation Модель relation
     * @param string $relationPath Путь к relation
     * @param array $relationFields Поля relation
     * @param array $document Текущий документ
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function processNestedRelationFields($relation, string $relationPath, array $relationFields, array &$document, array $translatableConfig): void
    {
        foreach ($relationFields as $field => $fieldConfig) {
            if (is_string($field)) {
                // Простое поле во вложенном relation
                $fullField = $relationPath . '.' . $field;
                $this->processRelationField($relation, $field, $fullField, $document, $translatableConfig);
            } elseif (is_array($fieldConfig)) {
                // Еще более вложенное relation
                $this->processNestedRelationFields($relation, $relationPath . '.' . $field, $fieldConfig, $document, $translatableConfig);
            }
        }
    }



    /**
     * Обрабатывает простое поле (без relations)
     * 
     * @param \Illuminate\Database\Eloquent\Model $record Запись модели
     * @param string $field Имя поля
     * @param array $document Текущий документ
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function processSimpleField($record, string $field, array &$document, array $translatableConfig): void
    {
        // Проверяем, является ли поле translatable
        if ($this->isFieldTranslatable($field, $translatableConfig)) {
            $this->processTranslatableSimpleField($record, $field, $document, $translatableConfig);
        } else {
            // Обычное поле
            if (array_key_exists($field, $record->getAttributes())) {
                $document[$field] = $record->getAttribute($field);
            }
        }
    }

    /**
     * Обрабатывает translatable простое поле
     * 
     * @param \Illuminate\Database\Eloquent\Model $record Запись модели
     * @param string $field Имя поля
     * @param array $document Текущий документ
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function processTranslatableSimpleField($record, string $field, array &$document, array $translatableConfig): void
    {
        // Получаем оригинальное значение и декодируем JSON
        $originalValue = $record->getRawOriginal($field);
        $translatableArray = json_decode($originalValue, true);
        
        // Проверяем, что декодирование прошло успешно и это массив
        if (is_array($translatableArray)) {
            // Добавляем поля для каждого языка
            foreach ($translatableConfig['locales'] as $locale) {
                if (isset($translatableArray[$locale])) {
                    $document[$field . '_' . $locale] = $translatableArray[$locale];
                }
            }
        } else {
            // Если декодирование не удалось, добавляем как обычное поле
            if (array_key_exists($field, $record->getAttributes())) {
                $document[$field] = $record->getAttribute($field);
            }
        }
    }



    /**
     * Обрабатывает одиночное relation
     * 
     * @param \Illuminate\Database\Eloquent\Model $relation Модель relation
     * @param string $relationField Имя поля в relation
     * @param string $fullField Полное имя поля (например, "category.title")
     * @param array $document Текущий документ
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function processSingleRelation($relation, string $relationField, string $fullField, array &$document, array $translatableConfig): void
    {
        // Проверяем, является ли поле translatable
        if ($this->isFieldTranslatable($fullField, $translatableConfig)) {
            $this->processTranslatableRelationField($relation, $relationField, $fullField, $document, $translatableConfig);
        } else {
            // Обычное поле в relation
            if (array_key_exists($relationField, $relation->getAttributes())) {
                $document[$fullField] = $relation->getAttribute($relationField);
            }
        }
    }

    /**
     * Обрабатывает translatable поле в relation
     * 
     * @param \Illuminate\Database\Eloquent\Model $relation Модель relation
     * @param string $relationField Имя поля в relation
     * @param string $fullField Полное имя поля
     * @param array $document Текущий документ
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function processTranslatableRelationField($relation, string $relationField, string $fullField, array &$document, array $translatableConfig): void
    {
        // Получаем оригинальное значение и декодируем JSON
        $originalValue = $relation->getRawOriginal($relationField);
        $translatableArray = json_decode($originalValue, true);
        
        // Проверяем, что декодирование прошло успешно и это массив
        if (is_array($translatableArray)) {
            // Добавляем поля для каждого языка
            foreach ($translatableConfig['locales'] as $locale) {
                if (isset($translatableArray[$locale])) {
                    $document[$fullField . '_' . $locale] = $translatableArray[$locale];
                }
            }
        } else {
            // Если декодирование не удалось, добавляем как обычное поле
            if (array_key_exists($relationField, $relation->getAttributes())) {
                $document[$fullField] = $relation->getAttribute($relationField);
            }
        }
    }

    /**
     * Обрабатывает множественные relations (коллекции)
     * 
     * @param \Illuminate\Database\Eloquent\Collection $relations Коллекция relations
     * @param string $relationField Имя поля в relation
     * @param string $fullField Полное имя поля
     * @param array $document Текущий документ
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function processMultipleRelations($relations, string $relationField, string $fullField, array &$document, array $translatableConfig): void
    {
        $values = [];
        
        foreach ($relations as $relation) {
            if (array_key_exists($relationField, $relation->getAttributes())) {
                $values[] = $relation->getAttribute($relationField);
            }
        }
        
        if (!empty($values)) {
            $document[$fullField] = implode(' ', $values);
        }
    }



    /**
     * Получает конфигурацию translatable полей
     * 
     * Объединяет глобальные настройки с настройками конкретной модели
     * 
     * @param array $config Конфигурация модели
     * @return array Конфигурация translatable полей
     */
    protected function getTranslatableConfig(array $config): array
    {
        $globalConfig = config('elastic.translatable', []);
        $modelConfig = $config['translatable'] ?? [];
        
        $mergedConfig = array_merge($globalConfig, $modelConfig);
        
        // Устанавливаем значения по умолчанию, если они отсутствуют
        $mergedConfig['locales'] = $mergedConfig['locales'] ?? ['en'];
        $mergedConfig['fallback_locale'] = $mergedConfig['fallback_locale'] ?? 'en';
        $mergedConfig['index_localized_fields'] = $mergedConfig['index_localized_fields'] ?? true;
        $mergedConfig['auto_detect_translatable'] = $mergedConfig['auto_detect_translatable'] ?? true;
        $mergedConfig['translatable_fields'] = $mergedConfig['translatable_fields'] ?? [];
        
        return $mergedConfig;
    }



    /**
     * Вычисляет значение поля на основе конфигурации
     * 
     * Поддерживает два типа вычислений:
     * - source: объединение нескольких полей в одно
     * - transform: применение трансформации к значению
     * 
     * @param \Illuminate\Database\Eloquent\Model $record Запись модели
     * @param array $fieldConfig Конфигурация поля
     * @return mixed Вычисленное значение поля
     */
    protected function computeField($record, array $fieldConfig): mixed
    {
        // Если указан source, объединяем несколько полей
        if (isset($fieldConfig['source'])) {
            $sources = (array) $fieldConfig['source'];
            $values = [];
            
            foreach ($sources as $source) {
                if (array_key_exists($source, $record->getAttributes())) {
                    $values[] = $record->getAttribute($source);
                }
            }
            
            return implode(' ', array_filter($values));
        }

        // Если указана трансформация, применяем её
        if (isset($fieldConfig['transform'])) {
            return $this->transformField($record, $fieldConfig['transform']);
        }

        return null;
    }

    /**
     * Применяет трансформацию к значению поля
     * 
     * Поддерживаемые трансформации:
     * - price_range: группировка цен по диапазонам
     * - popularity_score: расчет популярности на основе нескольких полей
     * - availability_status: определение статуса доступности
     * 
     * @param \Illuminate\Database\Eloquent\Model $record Запись модели
     * @param string $transform Тип трансформации
     * @return mixed Трансформированное значение
     */
    protected function transformField($record, string $transform): mixed
    {
        switch ($transform) {
            case 'price_range':
                $price = $record->getAttribute('price');
                if ($price <= 1000) return 'low';
                if ($price <= 10000) return 'medium';
                return 'high';
            
            case 'popularity_score':
                $viewsCount = $record->getAttribute('views_count') ?? 0;
                $salesCount = $record->getAttribute('sales_count') ?? 0;
                $rating = $record->getAttribute('rating') ?? 0;
                
                // Простая формула для расчета популярности
                $popularity = ($viewsCount * 0.1) + ($salesCount * 0.5) + ($rating * 10);
                return round($popularity, 2);
            
            case 'availability_status':
                $stockQuantity = $record->getAttribute('stock_quantity') ?? 0;
                $isActive = $record->getAttribute('is_active') ?? false;
                
                if (!$isActive) return 'inactive';
                if ($stockQuantity <= 0) return 'out_of_stock';
                if ($stockQuantity <= 10) return 'low_stock';
                return 'in_stock';
            
            default:
                return null;
        }
    }

    /**
     * Применяет условия запроса из конфигурации
     * 
     * Позволяет фильтровать записи перед индексацией
     * Поддерживает различные типы условий: where, where_in, where_between, where_has и др.
     * Автоматически загружает relations, указанные в searchable_fields
     * 
     * @param \Illuminate\Database\Eloquent\Model $model Экземпляр модели
     * @param array $config Конфигурация модели
     * @return \Illuminate\Database\Eloquent\Builder Query Builder с примененными условиями
     */
    protected function applyQueryConditions($model, array $config)
    {
        $query = $model->newQuery();

        $conditions = $config['query_conditions'] ?? [];

        // Применяем базовые условия WHERE
        foreach ($conditions['where'] ?? [] as $field => $value) {
            if ($value === 'not_null') {
                $query->whereNotNull($field);
            } elseif ($value === 'null') {
                $query->whereNull($field);
            } else {
                $query->where($field, $value);
            }
        }

        // Применяем условия WHERE IN
        foreach ($conditions['where_in'] ?? [] as $field => $values) {
            $query->whereIn($field, $values);
        }

        // Применяем условия WHERE BETWEEN
        foreach ($conditions['where_between'] ?? [] as $field => $values) {
            if (is_array($values) && count($values) === 2) {
                $query->whereBetween($field, $values);
            }
        }

        // Применяем условия WHERE HAS (для отношений)
        foreach ($conditions['where_has'] ?? [] as $relation => $callback) {
            if (is_callable($callback)) {
                $query->whereHas($relation, $callback);
            } else {
                $query->whereHas($relation, function($q) use ($callback) {
                    foreach ($callback as $field => $value) {
                        $q->where($field, $value);
                    }
                });
            }
        }

        // Применяем условия WHERE DOESN'T HAVE
        foreach ($conditions['where_doesnt_have'] ?? [] as $relation) {
            $query->whereDoesntHave($relation);
        }

        // Применяем кастомные условия через замыкание
        if (isset($conditions['where_callback']) && is_callable($conditions['where_callback'])) {
            $conditions['where_callback']($query);
        }

        // Загружаем relations, указанные в searchable_fields
        $this->loadRelationsForSearchableFields($query, $config);

        return $query;
    }

    /**
     * Загружает relations, указанные в searchable_fields
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query Query Builder
     * @param array $config Конфигурация модели
     */
    protected function loadRelationsForSearchableFields($query, array $config): void
    {
        $relationsToLoad = [];
        
        $this->extractRelationsFromSearchableFields($config['searchable_fields'] ?? [], $relationsToLoad);
        
        // Загружаем все необходимые relations
        if (!empty($relationsToLoad)) {
            $query->with($relationsToLoad);
        }
    }

    /**
     * Извлекает relations из searchable_fields
     * 
     * @param array $searchableFields Поля для поиска
     * @param array $relationsToLoad Массив для заполнения relations
     */
    protected function extractRelationsFromSearchableFields(array $searchableFields, array &$relationsToLoad): void
    {
        foreach ($searchableFields as $field => $fieldConfig) {
            if (is_array($fieldConfig)) {
                // Это relation
                if (!in_array($field, $relationsToLoad)) {
                    $relationsToLoad[] = $field;
                }
                
                // Рекурсивно обрабатываем вложенные relations
                $this->extractNestedRelations($field, $fieldConfig, $relationsToLoad);
            }
        }
    }

    /**
     * Извлекает вложенные relations
     * 
     * @param string $parentPath Путь к родительскому relation
     * @param array $fields Поля relation
     * @param array $relationsToLoad Массив для заполнения relations
     */
    protected function extractNestedRelations(string $parentPath, array $fields, array &$relationsToLoad): void
    {
        foreach ($fields as $field => $fieldConfig) {
            if (is_array($fieldConfig)) {
                // Это вложенное relation
                $fullPath = $parentPath . '.' . $field;
                if (!in_array($fullPath, $relationsToLoad)) {
                    $relationsToLoad[] = $fullPath;
                }
                
                // Рекурсивно обрабатываем еще более вложенные relations
                $this->extractNestedRelations($fullPath, $fieldConfig, $relationsToLoad);
            }
        }
    }

    /**
     * Удаляет индексы для всех моделей
     * 
     * Используется с опцией --delete-only
     * Удаляет все индексы, настроенные в конфигурации
     * 
     * @param array $models Массив моделей с их конфигурацией
     * @return int Код возврата (0 - успех, 1 - ошибка)
     */
    protected function deleteIndexes(array $models): int
    {
        $this->info('Deleting Elasticsearch indexes...');

        foreach ($models as $modelClass => $config) {
            $indexName = $this->getIndexName($config);
            
            if ($this->indexExists($indexName)) {
                try {
                    // Используем API Elasticsearch 8.x для удаления индекса
                    $this->elasticsearch->indices()->delete(['index' => $indexName]);
                    $this->info("Deleted index: {$indexName}");
                } catch (\Exception $e) {
                    $this->error("Failed to delete index {$indexName}: " . $e->getMessage());
                    return 1;
                }
            } else {
                $this->warn("Index {$indexName} does not exist.");
            }
        }

        $this->info('Index deletion completed!');
        return 0;
    }

    /**
     * Создает индексы для всех моделей без индексации данных
     * 
     * Используется с опцией --create-only
     * Создает только структуру индексов с маппингом, но не индексирует данные
     * 
     * @param array $models Массив моделей с их конфигурацией
     * @return int Код возврата (0 - успех, 1 - ошибка)
     */
    protected function createIndexes(array $models): int
    {
        $this->info('Creating Elasticsearch indexes...');

        foreach ($models as $modelClass => $config) {
            $indexName = $this->getIndexName($config);
            
            if ($this->indexExists($indexName)) {
                $this->warn("Index {$indexName} already exists.");
                continue;
            }

            try {
                $this->createIndex($indexName, $config, false);
                $this->info("Created index: {$indexName}");
            } catch (\Exception $e) {
                $this->error("Failed to create index {$indexName}: " . $e->getMessage());
                return 1;
            }
        }

        $this->info('Index creation completed!');
        return 0;
    }

    /**
     * Переиндексирует все модели (удаляет и пересоздает индексы с данными)
     * 
     * Используется с опцией --reindex
     * Полный цикл: удаление индекса -> создание индекса -> индексация данных
     * 
     * @param array $models Массив моделей с их конфигурацией
     * @return int Код возврата (0 - успех, 1 - ошибка)
     */
    protected function reindexModels(array $models): int
    {
        $this->info('Reindexing Elasticsearch data...');

        foreach ($models as $modelClass => $config) {
            try {
                // Принудительная переиндексация (удаление + создание + индексация)
                $this->indexModel($modelClass, $config, true);
                $this->info("Reindexed: {$modelClass}");
            } catch (\Exception $e) {
                $this->error("Failed to reindex {$modelClass}: " . $e->getMessage());
                return 1;
            }
        }

        $this->info('Reindexing completed successfully!');
        return 0;
    }
} 