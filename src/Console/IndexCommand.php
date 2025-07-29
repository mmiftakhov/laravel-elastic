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
     * с поддержкой multi-field mapping для различных анализаторов
     * 
     * @param array $config Конфигурация модели
     * @return array Маппинг для Elasticsearch
     */
    protected function getIndexMapping(array $config): array
    {
        $properties = [];

        // Добавляем поля для поиска
        foreach ($config['searchable_fields'] ?? [] as $field => $fieldConfig) {
            $properties[$field] = [
                'type' => $fieldConfig['type'] ?? 'text',
            ];

            if (isset($fieldConfig['analyzer'])) {
                $properties[$field]['analyzer'] = $fieldConfig['analyzer'];
            }

            if (isset($fieldConfig['boost'])) {
                $properties[$field]['boost'] = $fieldConfig['boost'];
            }

            // Добавляем multi-field mappings для разных анализаторов
            if (isset($fieldConfig['fields'])) {
                $properties[$field]['fields'] = [];
                foreach ($fieldConfig['fields'] as $subField => $subFieldConfig) {
                    $properties[$field]['fields'][$subField] = [
                        'type' => $subFieldConfig['type'] ?? 'text',
                    ];

                    if (isset($subFieldConfig['analyzer'])) {
                        $properties[$field]['fields'][$subField]['analyzer'] = $subFieldConfig['analyzer'];
                    }

                    if (isset($subFieldConfig['boost'])) {
                        $properties[$field]['fields'][$subField]['boost'] = $subFieldConfig['boost'];
                    }
                }
            }
        }

        // Добавляем вычисляемые поля
        foreach ($config['computed_fields'] ?? [] as $field => $fieldConfig) {
            $properties[$field] = [
                'type' => $fieldConfig['type'] ?? 'text',
            ];

            if (isset($fieldConfig['analyzer'])) {
                $properties[$field]['analyzer'] = $fieldConfig['analyzer'];
            }

            if (isset($fieldConfig['boost'])) {
                $properties[$field]['boost'] = $fieldConfig['boost'];
            }
        }

        return [
            'properties' => $properties,
        ];
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
     * включая хранимые поля, поля для поиска и вычисляемые поля
     * Поддерживает translatable поля (JSON структуры)
     * 
     * @param \Illuminate\Database\Eloquent\Model $record Запись модели
     * @param array $config Конфигурация модели
     * @return array Данные документа для Elasticsearch
     */
    protected function prepareDocument($record, array $config): array
    {
        $document = [];

        // Добавляем хранимые поля (возвращаются напрямую из Elasticsearch)
        foreach ($config['stored_fields'] ?? [] as $field) {
            if (array_key_exists($field, $record->getAttributes())) {
                $document[$field] = $record->getAttribute($field);
            }
        }

        // Добавляем поля для поиска
        foreach ($config['searchable_fields'] ?? [] as $field => $fieldConfig) {
            if (array_key_exists($field, $record->getAttributes())) {
                $document[$field] = $record->getAttribute($field);
            }
        }

        // Обрабатываем translatable поля
        $document = $this->processTranslatableFields($record, $document, $config);

        // Добавляем вычисляемые поля
        foreach ($config['computed_fields'] ?? [] as $field => $fieldConfig) {
            $document[$field] = $this->computeField($record, $fieldConfig);
        }

        return $document;
    }

    /**
     * Обрабатывает translatable поля из JSON структуры
     * 
     * Автоматически определяет translatable поля по типу данных и конфигурации,
     * извлекает значения для каждого языка и добавляет их как отдельные поля в документ.
     * 
     * @param \Illuminate\Database\Eloquent\Model $record Запись модели
     * @param array $document Текущий документ
     * @param array $config Конфигурация модели
     * @return array Обновленный документ с языковыми полями
     */
    protected function processTranslatableFields($record, array $document, array $config): array
    {
        // Получаем настройки translatable полей (глобальные + переопределения модели)
        $translatableConfig = $this->getTranslatableConfig($config);
        
        // Определяем translatable поля
        $translatableFields = $this->getTranslatableFields($record, $translatableConfig);
        
        foreach ($translatableFields as $field) {
            if (array_key_exists($field, $record->getAttributes())) {
                $translatableValue = $record->getAttribute($field);
                
                // Проверяем, является ли поле translatable, используя getRawOriginal()
                if ($this->isTranslatableField($record, $field, $translatableConfig)) {
                    // Получаем оригинальное значение и декодируем JSON
                    $originalValue = $record->getRawOriginal($field);
                    $translatableArray = json_decode($originalValue, true);
                    
                    // Проверяем, что декодирование прошло успешно и это массив
                    if (is_array($translatableArray)) {
                        // Добавляем основное поле (используем fallback язык)
                        $fallbackLocale = $translatableConfig['fallback_locale'];
                        $document[$field] = $translatableArray[$fallbackLocale] ?? $this->getFirstAvailableValue($translatableArray, $translatableConfig['locales']);
                        
                        // Добавляем языковые версии, если включено в конфигурации
                        if ($translatableConfig['index_localized_fields']) {
                            foreach ($translatableConfig['locales'] as $locale) {
                                if (isset($translatableArray[$locale])) {
                                    $document[$field . '_' . $locale] = $translatableArray[$locale];
                                }
                            }
                        }
                    } else {
                        // Если декодирование не удалось, используем значение как есть
                        $document[$field] = $translatableValue;
                    }
                } else {
                    // Если поле не translatable, оставляем как есть
                    $document[$field] = $translatableValue;
                }
            }
        }
        
        return $document;
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
     * Определяет translatable поля для записи
     * 
     * @param \Illuminate\Database\Eloquent\Model $record Запись модели
     * @param array $translatableConfig Конфигурация translatable полей
     * @return array Массив имен translatable полей
     */
    protected function getTranslatableFields($record, array $translatableConfig): array
    {
        if (!$translatableConfig['auto_detect_translatable']) {
            return $translatableConfig['translatable_fields'] ?? [];
        }
        
        // Автоматически определяем translatable поля
        $translatableFields = [];
        $attributes = $record->getAttributes();
        
        foreach ($attributes as $field => $value) {
            if ($this->isTranslatableField($record, $field, $translatableConfig)) {
                $translatableFields[] = $field;
            }
        }
        
        return $translatableFields;
    }

    /**
     * Проверяет, является ли поле translatable
     * 
     * Использует getRawOriginal() для определения типа данных поля
     * 
     * @param \Illuminate\Database\Eloquent\Model $record Запись модели
     * @param string $field Имя поля
     * @param array $translatableConfig Конфигурация translatable полей
     * @return bool True, если поле translatable
     */
    protected function isTranslatableField($record, string $field, array $translatableConfig): bool
    {
        // Если auto_detect отключен, проверяем только по списку
        if (!$translatableConfig['auto_detect_translatable']) {
            return in_array($field, $translatableConfig['translatable_fields'] ?? []);
        }
        
        // Получаем оригинальное значение поля (до аксессоров)
        $originalValue = $record->getRawOriginal($field);
        
        // Если значение null или не строка, не может быть translatable
        if ($originalValue === null || !is_string($originalValue)) {
            return false;
        }
        
        // Пытаемся декодировать JSON
        $decoded = json_decode($originalValue, true);
        
        // Если это валидный JSON и содержит языковые ключи
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $locales = $translatableConfig['locales'];
            
            // Проверяем, содержит ли JSON хотя бы один из поддерживаемых языков
            foreach ($locales as $locale) {
                if (isset($decoded[$locale]) && is_string($decoded[$locale])) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Получает первое доступное значение из translatable массива
     * 
     * @param array $translatableArray Массив с переводами
     * @param array $locales Поддерживаемые языки
     * @return string Первое доступное значение или пустая строка
     */
    protected function getFirstAvailableValue(array $translatableArray, array $locales): string
    {
        // Проверяем, что передан массив
        if (!is_array($translatableArray)) {
            return '';
        }
        
        foreach ($locales as $locale) {
            if (isset($translatableArray[$locale]) && is_string($translatableArray[$locale])) {
                return $translatableArray[$locale];
            }
        }
        
        return '';
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

        return $query;
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