<?php

namespace Maratmiftahov\LaravelElastic;

use Elastic\Elasticsearch\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * Основной класс для работы с поиском в Elasticsearch
 * 
 * Предоставляет удобный API для поиска в индексах Elasticsearch
 * с поддержкой различных типов поиска: обычный поиск, поиск по всем моделям,
 * автодополнение с подсветкой результатов.
 * 
 * Особенности:
 * - Автоматическое использование настроек из конфигурации
 * - Поддержка multi-field mapping
 * - Встроенная подсветка результатов
 * - Обработка ошибок с логированием
 * - Метаданные результатов поиска
 * - Поддержка boost в поисковых запросах (не в маппингах)
 * 
 * ⚠️ ВАЖНО: В Elasticsearch 8.x boost в маппингах индекса устарел и удален.
 * Приоритет полей должен применяться только в поисковых запросах через параметр 'boost'.
 */
class ElasticSearch
{
    /**
     * Экземпляр клиента Elasticsearch
     * 
     * Используется для всех операций с Elasticsearch API
     */
    protected Client $elasticsearch;

    /**
     * Создает новый экземпляр ElasticSearch
     * 
     * @param Client $elasticsearch Экземпляр клиента Elasticsearch
     */
    public function __construct(Client $elasticsearch)
    {
        $this->elasticsearch = $elasticsearch;
    }

    /**
     * Поиск в конкретной модели
     * 
     * Выполняет поиск в индексе указанной модели с поддержкой
     * всех настроек из конфигурации: multi-field mapping,
     * подсветка результатов, пагинация, boost в запросах.
     * 
     * @param string $modelClass Полное имя класса модели
     * @param string $query Поисковый запрос
     * @param array $options Дополнительные опции поиска:
     *                       - limit: количество результатов
     *                       - offset: смещение для пагинации
     *                       - fields: поля для поиска
     *                       - boost: приоритет полей (массив [поле => значение])
     *                       - boost_mode: режим применения boost (multiply, replace, sum, avg, max, min)
     *                       - score_mode: режим подсчета скора (sum, avg, max, min, first, multiply)
     *                       - sort: сортировка результатов
     *                       - analyzer: анализатор для поиска
     *                       - highlight: включить подсветку
     * @return Collection Коллекция результатов с метаданными
     * @throws \InvalidArgumentException Если модель не настроена
     * @throws \RuntimeException При ошибке поиска
     */
    public function search(string $modelClass, string $query, array $options = []): Collection
    {
        $startTime = microtime(true);
        
        $models = Config::get('elastic.models', []);
        
        if (!isset($models[$modelClass])) {
            throw new \InvalidArgumentException("Model {$modelClass} is not configured for indexing.");
        }

        $config = $models[$modelClass];
        $indexName = $this->getIndexName($config);

        $searchParams = $this->buildSearchParams($query, $config, $options);
        $searchParams['index'] = $indexName;

        try {
            // Выполняем поиск через API Elasticsearch 8.x
            $response = $this->elasticsearch->search($searchParams);
            $results = $this->formatResults($response->asArray(), $config);
            
            $endTime = microtime(true);
            $executionTime = ($endTime - $startTime) * 1000;
            
            Log::info("ElasticSearch search completed", [
                'model' => $modelClass,
                'query' => $query,
                'execution_time_ms' => round($executionTime, 2),
                'results_count' => $results->count(),
                'total_hits' => $results->get('_meta.total', 0)
            ]);
            
            return $results;
        } catch (\Exception $e) {
            $endTime = microtime(true);
            $executionTime = ($endTime - $startTime) * 1000;
            
            Log::error("ElasticSearch search failed", [
                'model' => $modelClass,
                'query' => $query,
                'execution_time_ms' => round($executionTime, 2),
                'error' => $e->getMessage()
            ]);
            
            throw new \RuntimeException("Search failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Поиск во всех настроенных моделях
     * 
     * Выполняет поиск во всех индексах одновременно и возвращает
     * результаты, сгруппированные по моделям. Продолжает поиск
     * даже если в некоторых моделях произошли ошибки.
     * 
     * @param string $query Поисковый запрос
     * @param array $options Дополнительные опции поиска
     * @return array Массив результатов, сгруппированных по моделям
     */
    public function searchAll(string $query, array $options = []): array
    {
        $models = Config::get('elastic.models', []);
        $results = [];

        foreach ($models as $modelClass => $config) {
            $indexName = $this->getIndexName($config);
            
            if (!$this->indexExists($indexName)) {
                continue;
            }

            $searchParams = $this->buildSearchParams($query, $config, $options);
            $searchParams['index'] = $indexName;

            try {
                $response = $this->elasticsearch->search($searchParams);
                $results[$modelClass] = $this->formatResults($response->asArray(), $config);
            } catch (\Exception $e) {
                // Логируем ошибку, но продолжаем поиск в других моделях
                Log::warning("Search failed for {$modelClass}: " . $e->getMessage());
            }
        }

        return $results;
    }

    /**
     * Получение предложений автодополнения
     * 
     * Выполняет поиск с использованием phrase_prefix для автодополнения.
     * Использует специальные поля с edge_ngram анализатором для
     * эффективного поиска по префиксам.
     * 
     * @param string $modelClass Полное имя класса модели
     * @param string $query Поисковый запрос
     * @param array $options Дополнительные опции
     * @return Collection Коллекция предложений с подсветкой
     * @throws \InvalidArgumentException Если модель не настроена
     * @throws \RuntimeException При ошибке поиска
     */
    public function autocomplete(string $modelClass, string $query, array $options = []): Collection
    {
        $models = Config::get('elastic.models', []);
        
        if (!isset($models[$modelClass])) {
            throw new \InvalidArgumentException("Model {$modelClass} is not configured for indexing.");
        }

        $config = $models[$modelClass];
        $indexName = $this->getIndexName($config);
        $autocompleteSettings = Config::get('elastic.search.autocomplete', []);

        $searchParams = [
            'index' => $indexName,
            'body' => [
                'query' => [
                    'multi_match' => [
                        'query' => $query,
                        'fields' => $this->getAutocompleteFields($config),
                        'type' => 'phrase_prefix',
                        'analyzer' => 'autocomplete',
                    ],
                ],
                'size' => $options['limit'] ?? $autocompleteSettings['max_suggestions'] ?? 10,
                'highlight' => [
                    'fields' => $this->getHighlightFields($config),
                ],
            ],
        ];

        try {
            $response = $this->elasticsearch->search($searchParams);
            return $this->formatResults($response->asArray(), $config);
        } catch (\Exception $e) {
            throw new \RuntimeException("Autocomplete failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Строит параметры поиска для Elasticsearch
     * 
     * Создает полную структуру запроса с учетом всех настроек:
     * - multi_match запрос для поиска по нескольким полям
     * - настройки релевантности из конфигурации searchable_fields_boost
     * - обработка мультиязычных полей (title_en, title_lv, etc.)
     * - сортировка, агрегации, подсветка
     * - пагинация
     * 
     * @param string $query Поисковый запрос
     * @param array $config Конфигурация модели
     * @param array $options Дополнительные опции
     * @return array Параметры для API Elasticsearch
     */
    protected function buildSearchParams(string $query, array $config, array $options = []): array
    {
        // Получаем поля для поиска с учетом boost из конфигурации
        $searchFields = $this->getSearchFieldsWithBoost($config, $options['fields'] ?? null, $options['boost'] ?? null);
        $searchSettings = Config::get('elastic.search.default', []);

        $searchParams = [
            'body' => [
                'query' => [
                    'multi_match' => [
                        'query' => $query,
                        'fields' => $searchFields,
                        'type' => $options['type'] ?? 'best_fields',
                        'operator' => $options['operator'] ?? $searchSettings['operator'] ?? 'OR',
                        'fuzziness' => $options['fuzziness'] ?? $searchSettings['fuzziness'] ?? 'AUTO',
                        'minimum_should_match' => $options['minimum_should_match'] ?? $searchSettings['minimum_should_match'] ?? '75%',
                    ],
                ],
                'size' => $options['limit'] ?? 10,
                'from' => $options['offset'] ?? 0,
            ],
        ];

        // Добавляем boost_mode и score_mode если указаны
        if (isset($options['boost_mode'])) {
            $searchParams['body']['query']['multi_match']['boost_mode'] = $options['boost_mode'];
        }
        
        if (isset($options['score_mode'])) {
            $searchParams['body']['query']['multi_match']['score_mode'] = $options['score_mode'];
        }

        // Добавляем сортировку
        if (isset($options['sort'])) {
            $searchParams['body']['sort'] = $options['sort'];
        }

        // Добавляем агрегации
        if (isset($options['aggs'])) {
            $searchParams['body']['aggs'] = $options['aggs'];
        }

        // Добавляем подсветку
        if ($options['highlight'] ?? true) {
            $searchParams['body']['highlight'] = [
                'fields' => $this->getHighlightFields($config),
            ];
        }

        // Добавляем анализатор
        if (isset($options['analyzer'])) {
            $searchParams['body']['query']['multi_match']['analyzer'] = $options['analyzer'];
        }

        return $searchParams;
    }

    /**
     * Получает поля для поиска с учетом boost из конфигурации
     * 
     * Поддерживает новую структуру searchable_fields_boost с relations
     * и автоматическую обработку мультиязычных полей.
     * 
     * @param array $config Конфигурация модели
     * @param array|null $fields Поля для поиска (если указаны)
     * @param array|null $boost Boost значения для полей
     * @return array Массив полей с boost значениями
     */
    protected function getSearchFieldsWithBoost(array $config, ?array $fields, ?array $boost): array
    {
        // Кэшируем результат построения поисковых полей
        $cacheKey = 'search_fields_' . md5(serialize($config) . serialize($fields) . serialize($boost));
        
        return Cache::remember($cacheKey, 3600, function() use ($config, $fields, $boost) {
            if ($fields) {
                // Применяем boost к указанным полям
                if ($boost) {
                    return array_map(function($field) use ($boost) {
                        $fieldName = is_array($field) ? $field[0] : $field;
                        $fieldBoost = $boost[$fieldName] ?? 1.0;
                        return $fieldName . '^' . $fieldBoost;
                    }, $fields);
                }
                return $fields;
            }

            $searchFields = [];
            $boostConfig = $config['searchable_fields_boost'] ?? [];
            $translatableConfig = $this->getTranslatableConfig($config);
            
            $this->extractSearchFieldsWithBoostFromConfig(
                $config['searchable_fields'] ?? [], 
                $searchFields, 
                $boostConfig,
                $translatableConfig
            );

            return $searchFields;
        });
    }

    /**
     * Извлекает поля для поиска из конфигурации с учетом boost и мультиязычности
     * 
     * @param array $searchableFields Поля для поиска
     * @param array $searchFields Массив для заполнения полей
     * @param array $boostConfig Конфигурация boost значений
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function extractSearchFieldsWithBoostFromConfig(
        array $searchableFields, 
        array &$searchFields, 
        array $boostConfig,
        array $translatableConfig
    ): void {
        foreach ($searchableFields as $field => $fieldConfig) {
            if (is_numeric($field) && is_string($fieldConfig)) {
                // Простое поле (числовой ключ)
                $this->addFieldWithBoost($fieldConfig, $searchFields, $boostConfig, $translatableConfig);
            } elseif (is_string($field) && is_array($fieldConfig)) {
                // Relation поля
                $this->extractRelationSearchFieldsWithBoost($field, $fieldConfig, $searchFields, $boostConfig, $translatableConfig);
            }
        }
    }

    /**
     * Добавляет поле с boost значением, учитывая мультиязычность
     * 
     * @param string $field Имя поля
     * @param array $searchFields Массив полей для поиска
     * @param array $boostConfig Конфигурация boost
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function addFieldWithBoost(string $field, array &$searchFields, array $boostConfig, array $translatableConfig): void
    {
        $fieldBoost = $boostConfig[$field] ?? 1.0;
        
        // Проверяем, является ли поле translatable
        if ($this->isFieldTranslatable($field, $translatableConfig)) {
            // Для translatable полей добавляем поля для каждого языка
            foreach ($translatableConfig['locales'] as $locale) {
                $localizedField = $field . '_' . $locale;
                $searchFields[] = $localizedField . '^' . $fieldBoost;
            }
        } else {
            // Для обычных полей добавляем одно поле
            $searchFields[] = $field . '^' . $fieldBoost;
        }
    }

    /**
     * Извлекает поля relations для поиска с учетом boost и мультиязычности
     * 
     * @param string $relationName Имя relation
     * @param array $relationFields Поля relation
     * @param array $searchFields Массив для заполнения полей
     * @param array $boostConfig Конфигурация boost значений
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function extractRelationSearchFieldsWithBoost(
        string $relationName, 
        array $relationFields, 
        array &$searchFields, 
        array $boostConfig,
        array $translatableConfig
    ): void {
        foreach ($relationFields as $field => $fieldConfig) {
            if (is_numeric($field) && is_string($fieldConfig)) {
                // Простое поле в relation
                $fullField = $relationName . '.' . $fieldConfig;
                $this->addRelationFieldWithBoost($relationName, $fieldConfig, $fullField, $searchFields, $boostConfig, $translatableConfig);
            } elseif (is_string($field) && is_array($fieldConfig)) {
                // Вложенное relation
                $this->extractNestedRelationSearchFieldsWithBoost($relationName . '.' . $field, $fieldConfig, $searchFields, $boostConfig, $translatableConfig);
            }
        }
    }

    /**
     * Добавляет поле relation с boost значением, учитывая мультиязычность
     * 
     * @param string $relationName Имя relation
     * @param string $relationField Имя поля в relation
     * @param string $fullField Полное имя поля
     * @param array $searchFields Массив полей для поиска
     * @param array $boostConfig Конфигурация boost
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function addRelationFieldWithBoost(
        string $relationName, 
        string $relationField, 
        string $fullField, 
        array &$searchFields, 
        array $boostConfig, 
        array $translatableConfig
    ): void {
        // Получаем boost для relation поля
        $relationBoostConfig = $boostConfig[$relationName] ?? [];
        $fieldBoost = $relationBoostConfig[$relationField] ?? 1.0;
        
        // Проверяем, является ли поле translatable
        if ($this->isFieldTranslatable($fullField, $translatableConfig)) {
            // Для translatable полей добавляем поля для каждого языка
            foreach ($translatableConfig['locales'] as $locale) {
                $localizedField = $fullField . '_' . $locale;
                $searchFields[] = $localizedField . '^' . $fieldBoost;
            }
        } else {
            // Для обычных полей добавляем одно поле
            $searchFields[] = $fullField . '^' . $fieldBoost;
        }
    }

    /**
     * Извлекает поля вложенных relations для поиска с учетом boost и мультиязычности
     * 
     * @param string $relationPath Путь к relation
     * @param array $relationFields Поля relation
     * @param array $searchFields Массив для заполнения полей
     * @param array $boostConfig Конфигурация boost значений
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function extractNestedRelationSearchFieldsWithBoost(
        string $relationPath, 
        array $relationFields, 
        array &$searchFields, 
        array $boostConfig,
        array $translatableConfig
    ): void {
        foreach ($relationFields as $field => $fieldConfig) {
            if (is_numeric($field) && is_string($fieldConfig)) {
                // Простое поле во вложенном relation
                $fullField = $relationPath . '.' . $fieldConfig;
                $this->addNestedRelationFieldWithBoost($relationPath, $fieldConfig, $fullField, $searchFields, $boostConfig, $translatableConfig);
            } elseif (is_string($field) && is_array($fieldConfig)) {
                // Еще более вложенное relation
                $this->extractNestedRelationSearchFieldsWithBoost($relationPath . '.' . $field, $fieldConfig, $searchFields, $boostConfig, $translatableConfig);
            }
        }
    }

    /**
     * Добавляет поле вложенного relation с boost значением, учитывая мультиязычность
     * 
     * @param string $relationPath Путь к relation
     * @param string $relationField Имя поля в relation
     * @param string $fullField Полное имя поля
     * @param array $searchFields Массив полей для поиска
     * @param array $boostConfig Конфигурация boost
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function addNestedRelationFieldWithBoost(
        string $relationPath, 
        string $relationField, 
        string $fullField, 
        array &$searchFields, 
        array $boostConfig, 
        array $translatableConfig
    ): void {
        // Получаем boost для вложенного relation поля
        $fieldBoost = $this->getNestedRelationBoost($relationPath, $relationField, $boostConfig);
        
        // Проверяем, является ли поле translatable
        if ($this->isFieldTranslatable($fullField, $translatableConfig)) {
            // Для translatable полей добавляем поля для каждого языка
            foreach ($translatableConfig['locales'] as $locale) {
                $localizedField = $fullField . '_' . $locale;
                $searchFields[] = $localizedField . '^' . $fieldBoost;
            }
        } else {
            // Для обычных полей добавляем одно поле
            $searchFields[] = $fullField . '^' . $fieldBoost;
        }
    }

    /**
     * Получает boost значение для вложенного relation поля
     * 
     * @param string $relationPath Путь к relation
     * @param string $relationField Имя поля в relation
     * @param array $boostConfig Конфигурация boost
     * @return float Boost значение
     */
    protected function getNestedRelationBoost(string $relationPath, string $relationField, array $boostConfig): float
    {
        $pathParts = explode('.', $relationPath);
        $currentConfig = $boostConfig;
        
        // Проходим по пути к вложенному relation
        foreach ($pathParts as $part) {
            if (isset($currentConfig[$part]) && is_array($currentConfig[$part])) {
                $currentConfig = $currentConfig[$part];
            } else {
                return 1.0; // Если путь не найден, возвращаем значение по умолчанию
            }
        }
        
        // Возвращаем boost для конкретного поля
        return $currentConfig[$relationField] ?? 1.0;
    }

    /**
     * Получает поля для автодополнения
     * 
     * Извлекает все поля из новой структуры searchable_fields
     * для использования в автодополнении с поддержкой мультиязычных полей.
     * 
     * @param array $config Конфигурация модели
     * @return array Массив полей для автодополнения
     */
    protected function getAutocompleteFields(array $config): array
    {
        // Кэшируем поля для автодополнения
        $cacheKey = 'autocomplete_fields_' . md5(serialize($config));
        
        return Cache::remember($cacheKey, 3600, function() use ($config) {
            $autocompleteFields = [];
            $translatableConfig = $this->getTranslatableConfig($config);
            
            $this->extractAutocompleteFieldsFromConfig(
                $config['searchable_fields'] ?? [], 
                $autocompleteFields,
                $translatableConfig
            );

            return $autocompleteFields;
        });
    }

    /**
     * Извлекает поля для автодополнения из конфигурации
     * 
     * @param array $searchableFields Поля для поиска
     * @param array $autocompleteFields Массив для заполнения полей
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function extractAutocompleteFieldsFromConfig(array $searchableFields, array &$autocompleteFields, array $translatableConfig): void
    {
        foreach ($searchableFields as $field => $fieldConfig) {
            if (is_numeric($field) && is_string($fieldConfig)) {
                // Простое поле (числовой ключ)
                $this->addAutocompleteField($fieldConfig, $autocompleteFields, $translatableConfig);
            } elseif (is_string($field) && is_array($fieldConfig)) {
                // Relation поля
                $this->extractRelationAutocompleteFields($field, $fieldConfig, $autocompleteFields, $translatableConfig);
            }
        }
    }

    /**
     * Добавляет поле для автодополнения, учитывая мультиязычность
     * 
     * @param string $field Имя поля
     * @param array $autocompleteFields Массив полей для автодополнения
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function addAutocompleteField(string $field, array &$autocompleteFields, array $translatableConfig): void
    {
        // Проверяем, является ли поле translatable
        if ($this->isFieldTranslatable($field, $translatableConfig)) {
            // Для translatable полей добавляем поля для каждого языка
            foreach ($translatableConfig['locales'] as $locale) {
                $autocompleteFields[] = $field . '_' . $locale;
            }
        } else {
            // Для обычных полей добавляем одно поле
            $autocompleteFields[] = $field;
        }
    }

    /**
     * Извлекает поля relations для автодополнения
     * 
     * @param string $relationName Имя relation
     * @param array $relationFields Поля relation
     * @param array $autocompleteFields Массив для заполнения полей
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function extractRelationAutocompleteFields(string $relationName, array $relationFields, array &$autocompleteFields, array $translatableConfig): void
    {
        foreach ($relationFields as $field => $fieldConfig) {
            if (is_numeric($field) && is_string($fieldConfig)) {
                // Простое поле в relation
                $fullField = $relationName . '.' . $fieldConfig;
                $this->addAutocompleteField($fullField, $autocompleteFields, $translatableConfig);
            } elseif (is_string($field) && is_array($fieldConfig)) {
                // Вложенное relation
                $this->extractNestedRelationAutocompleteFields($relationName . '.' . $field, $fieldConfig, $autocompleteFields, $translatableConfig);
            }
        }
    }

    /**
     * Извлекает поля вложенных relations для автодополнения
     * 
     * @param string $relationPath Путь к relation
     * @param array $relationFields Поля relation
     * @param array $autocompleteFields Массив для заполнения полей
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function extractNestedRelationAutocompleteFields(string $relationPath, array $relationFields, array &$autocompleteFields, array $translatableConfig): void
    {
        foreach ($relationFields as $field => $fieldConfig) {
            if (is_numeric($field) && is_string($fieldConfig)) {
                // Простое поле во вложенном relation
                $fullField = $relationPath . '.' . $fieldConfig;
                $this->addAutocompleteField($fullField, $autocompleteFields, $translatableConfig);
            } elseif (is_string($field) && is_array($fieldConfig)) {
                // Еще более вложенное relation
                $this->extractNestedRelationAutocompleteFields($relationPath . '.' . $field, $fieldConfig, $autocompleteFields, $translatableConfig);
            }
        }
    }

    /**
     * Получает поля для подсветки результатов
     * 
     * Настраивает подсветку для всех текстовых полей из новой структуры
     * searchable_fields с оптимальными параметрами и поддержкой мультиязычных полей.
     * 
     * @param array $config Конфигурация модели
     * @return array Конфигурация подсветки для Elasticsearch
     */
    protected function getHighlightFields(array $config): array
    {
        // Кэшируем поля для подсветки
        $cacheKey = 'highlight_fields_' . md5(serialize($config));
        
        return Cache::remember($cacheKey, 3600, function() use ($config) {
            $highlightFields = [];
            $translatableConfig = $this->getTranslatableConfig($config);
            
            $this->extractHighlightFieldsFromConfig(
                $config['searchable_fields'] ?? [], 
                $highlightFields,
                $translatableConfig
            );

            return $highlightFields;
        });
    }

    /**
     * Извлекает поля для подсветки из конфигурации
     * 
     * @param array $searchableFields Поля для поиска
     * @param array $highlightFields Массив для заполнения полей подсветки
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function extractHighlightFieldsFromConfig(array $searchableFields, array &$highlightFields, array $translatableConfig): void
    {
        foreach ($searchableFields as $field => $fieldConfig) {
            if (is_numeric($field) && is_string($fieldConfig)) {
                // Простое поле (числовой ключ)
                $this->addHighlightField($fieldConfig, $highlightFields, $translatableConfig);
            } elseif (is_string($field) && is_array($fieldConfig)) {
                // Relation поля
                $this->extractRelationHighlightFields($field, $fieldConfig, $highlightFields, $translatableConfig);
            }
        }
    }

    /**
     * Добавляет поле для подсветки, учитывая мультиязычность
     * 
     * @param string $field Имя поля
     * @param array $highlightFields Массив полей для подсветки
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function addHighlightField(string $field, array &$highlightFields, array $translatableConfig): void
    {
        $highlightConfig = [
            'type' => 'unified',
            'fragment_size' => 150,
            'number_of_fragments' => 3,
        ];

        // Проверяем, является ли поле translatable
        if ($this->isFieldTranslatable($field, $translatableConfig)) {
            // Для translatable полей добавляем поля для каждого языка
            foreach ($translatableConfig['locales'] as $locale) {
                $highlightFields[$field . '_' . $locale] = $highlightConfig;
            }
        } else {
            // Для обычных полей добавляем одно поле
            $highlightFields[$field] = $highlightConfig;
        }
    }

    /**
     * Извлекает поля relations для подсветки
     * 
     * @param string $relationName Имя relation
     * @param array $relationFields Поля relation
     * @param array $highlightFields Массив для заполнения полей подсветки
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function extractRelationHighlightFields(string $relationName, array $relationFields, array &$highlightFields, array $translatableConfig): void
    {
        foreach ($relationFields as $field => $fieldConfig) {
            if (is_numeric($field) && is_string($fieldConfig)) {
                // Простое поле в relation
                $fullField = $relationName . '.' . $fieldConfig;
                $this->addHighlightField($fullField, $highlightFields, $translatableConfig);
            } elseif (is_string($field) && is_array($fieldConfig)) {
                // Вложенное relation
                $this->extractNestedRelationHighlightFields($relationName . '.' . $field, $fieldConfig, $highlightFields, $translatableConfig);
            }
        }
    }

    /**
     * Извлекает поля вложенных relations для подсветки
     * 
     * @param string $relationPath Путь к relation
     * @param array $relationFields Поля relation
     * @param array $highlightFields Массив для заполнения полей подсветки
     * @param array $translatableConfig Конфигурация translatable полей
     */
    protected function extractNestedRelationHighlightFields(string $relationPath, array $relationFields, array &$highlightFields, array $translatableConfig): void
    {
        foreach ($relationFields as $field => $fieldConfig) {
            if (is_numeric($field) && is_string($fieldConfig)) {
                // Простое поле во вложенном relation
                $fullField = $relationPath . '.' . $fieldConfig;
                $this->addHighlightField($fullField, $highlightFields, $translatableConfig);
            } elseif (is_string($field) && is_array($fieldConfig)) {
                // Еще более вложенное relation
                $this->extractNestedRelationHighlightFields($relationPath . '.' . $field, $fieldConfig, $highlightFields, $translatableConfig);
            }
        }
    }

    /**
     * Форматирует результаты поиска
     * 
     * Преобразует ответ Elasticsearch в удобную коллекцию Laravel
     * с добавлением подсветки, метаданных поиска и данных из БД.
     * 
     * @param array $response Ответ от Elasticsearch
     * @param array $config Конфигурация модели
     * @return Collection Коллекция результатов с метаданными
     */
    protected function formatResults(array $response, array $config): Collection
    {
        $hits = $response['hits']['hits'] ?? [];
        $total = $response['hits']['total']['value'] ?? 0;
        $maxScore = $response['hits']['max_score'] ?? 0;

        // Извлекаем ID из результатов Elasticsearch
        $ids = collect($hits)->pluck('_id')->toArray();
        $scores = collect($hits)->pluck('_score', '_id')->toArray();
        $highlights = collect($hits)->pluck('highlight', '_id')->toArray();

        // Если есть ID и настроены return_fields, загружаем данные из БД
        if (!empty($ids) && isset($config['return_fields'])) {
            $dbResults = $this->loadDataFromDatabase($ids, $config);
            
            // Объединяем данные из БД с результатами Elasticsearch
            $results = collect($dbResults)->map(function ($item) use ($scores, $highlights) {
                $id = $item['id'];
                
                // Добавляем скор из Elasticsearch
                $item['_score'] = $scores[$id] ?? 0;
                $item['_id'] = $id;
                
                // Добавляем подсветку
                if (isset($highlights[$id])) {
                    foreach ($highlights[$id] as $field => $fragments) {
                        $item['highlight_' . $field] = implode(' ... ', $fragments);
                    }
                }
                
                return $item;
            });
        } else {
            // Fallback: возвращаем только данные из Elasticsearch
            $results = collect($hits)->map(function ($hit) use ($config) {
                $source = $hit['_source'] ?? [];
                $highlight = $hit['highlight'] ?? [];

                // Добавляем подсветку к исходным данным
                foreach ($highlight as $field => $fragments) {
                    $source['highlight_' . $field] = implode(' ... ', $fragments);
                }

                // Добавляем скор и ID
                $source['_score'] = $hit['_score'];
                $source['_id'] = $hit['_id'];

                return $source;
            });
        }

        // Добавляем метаданные как свойство коллекции
        $results->put('_meta', [
            'total' => $total,
            'max_score' => $maxScore,
            'took' => $response['took'] ?? 0,
        ]);

        return $results;
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
     * Загружает данные из базы данных по ID с сохранением порядка из Elasticsearch
     * 
     * @param array $ids Массив ID в порядке результатов Elasticsearch
     * @param array $config Конфигурация модели
     * @return array Массив данных из БД в том же порядке
     */
    protected function loadDataFromDatabase(array $ids, array $config): array
    {
        $startTime = microtime(true);
        
        if (empty($ids)) {
            return [];
        }

        // Получаем имя модели из конфигурации
        $modelClass = $this->getModelClassFromConfig($config);
        if (!$modelClass) {
            return [];
        }

        $returnFields = $config['return_fields'] ?? [];
        
        // Строим запрос с учетом return_fields
        $query = $modelClass::query();
        
        // Разделяем поля на основные и отношения
        $mainFields = [];
        $relations = [];
        
        foreach ($returnFields as $key => $value) {
            if (is_string($value)) {
                // Обычное поле основной модели
                $mainFields[] = $value;
            } elseif (is_array($value)) {
                // Отношение
                $relations[$key] = $this->buildRelationQuery($value);
            }
        }
        
        // Всегда делаем select для основных полей, но если есть relations,
        // добавляем в select все foreign keys, необходимые для загрузки relations
        if (!empty($mainFields)) {
            $selectFields = $mainFields;
            
            // Если есть relations, добавляем foreign keys
            if (!empty($relations)) {
                $foreignKeys = $this->getForeignKeysForRelations($modelClass, array_keys($relations));
                $selectFields = array_merge($selectFields, $foreignKeys);
            }
            
            $query->select($selectFields);
        }
        
        // Добавляем отношения с их собственными select
        foreach ($relations as $relation => $relationConfig) {
            $query->with([$relation => function ($query) use ($relationConfig) {
                if (isset($relationConfig['select']) && !empty($relationConfig['select'])) {
                    $query->select($relationConfig['select']);
                }
                if (isset($relationConfig['with'])) {
                    foreach ($relationConfig['with'] as $nestedRelation => $nestedConfig) {
                        $query->with([$nestedRelation => function ($nestedQuery) use ($nestedConfig) {
                            if (isset($nestedConfig['select']) && !empty($nestedConfig['select'])) {
                                $nestedQuery->select($nestedConfig['select']);
                            }
                        }]);
                    }
                }
            }]);
        }
        
        // Фильтруем по ID и сохраняем порядок из Elasticsearch
        $query->whereIn('id', $ids);
        
        // Получаем результаты
        $results = $query->get()->keyBy('id')->toArray();
        
        // Сортируем в том же порядке, что и в Elasticsearch
        $orderedResults = [];
        foreach ($ids as $id) {
            if (isset($results[$id])) {
                $orderedResults[] = $results[$id];
            }
        }
        
        $endTime = microtime(true);
        $executionTime = ($endTime - $startTime) * 1000;
        
        Log::info("Database data loading completed", [
            'model' => $modelClass,
            'ids_count' => count($ids),
            'results_count' => count($orderedResults),
            'execution_time_ms' => round($executionTime, 2)
        ]);
        
        return $orderedResults;
    }

    /**
     * Строит конфигурацию запроса для отношения
     * 
     * @param array $relationFields Поля отношения
     * @return array Конфигурация запроса
     */
    protected function buildRelationQuery(array $relationFields): array
    {
        $config = ['select' => [], 'with' => []];
        
        foreach ($relationFields as $key => $value) {
            if (is_string($value)) {
                // Обычное поле отношения
                $config['select'][] = $value;
            } elseif (is_array($value)) {
                // Вложенное отношение
                $nestedConfig = $this->buildRelationQuery($value);
                $config['with'][$key] = $nestedConfig;
            }
        }
        
        return $config;
    }

    /**
     * Получает имя класса модели из конфигурации
     * 
     * @param array $config Конфигурация модели
     * @return string|null Имя класса модели
     */
    protected function getModelClassFromConfig(array $config): ?string
    {
        $models = Config::get('elastic.models', []);
        
        foreach ($models as $modelClass => $modelConfig) {
            if ($modelConfig === $config) {
                return $modelClass;
            }
        }
        
        return null;
    }

    /**
     * Получает foreign keys для указанных relations
     * 
     * @param string $modelClass Имя класса модели
     * @param array $relations Массив имен relations
     * @return array Массив foreign keys
     */
    protected function getForeignKeysForRelations(string $modelClass, array $relations): array
    {
        // Кэшируем foreign keys для relations
        $cacheKey = 'foreign_keys_' . $modelClass . '_' . md5(serialize($relations));
        
        return Cache::remember($cacheKey, 3600, function() use ($modelClass, $relations) {
            $foreignKeys = [];
            
            // Создаем экземпляр модели для получения информации о relations
            $model = new $modelClass();
            
            foreach ($relations as $relation) {
                // Получаем foreign key для relation
                $foreignKey = $this->getForeignKeyForRelation($model, $relation);
                if ($foreignKey) {
                    $foreignKeys[] = $foreignKey;
                }
            }
            
            return array_unique($foreignKeys);
        });
    }

    /**
     * Получает foreign key для конкретного relation
     * 
     * @param mixed $model Экземпляр модели
     * @param string $relation Имя relation
     * @return string|null Foreign key или null
     */
    protected function getForeignKeyForRelation($model, string $relation): ?string
    {
        // Кэшируем foreign key для каждого relation
        $cacheKey = 'foreign_key_' . get_class($model) . '_' . $relation;
        
        return Cache::remember($cacheKey, 3600, function() use ($model, $relation) {
            // Проверяем, существует ли relation
            if (!method_exists($model, $relation)) {
                return null;
            }
            
            // Получаем relation метод без использования Reflection
            $relationInstance = $model->$relation();
            
            // Получаем foreign key из relation
            if ($relationInstance instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
                return $relationInstance->getForeignKeyName();
            } elseif ($relationInstance instanceof \Illuminate\Database\Eloquent\Relations\HasOne) {
                return $relationInstance->getForeignKeyName();
            } elseif ($relationInstance instanceof \Illuminate\Database\Eloquent\Relations\HasMany) {
                return $relationInstance->getForeignKeyName();
            } elseif ($relationInstance instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
                // Для many-to-many возвращаем pivot table foreign key
                return $relationInstance->getForeignPivotKeyName();
            }
            
            return null;
        });
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
     * Получает конфигурацию translatable полей
     * 
     * Объединяет глобальные настройки с настройками конкретной модели
     * 
     * @param array $config Конфигурация модели
     * @return array Конфигурация translatable полей
     */
    protected function getTranslatableConfig(array $config): array
    {
        // Кэшируем конфигурацию translatable полей
        $cacheKey = 'translatable_config_' . md5(serialize($config));
        
        return Cache::remember($cacheKey, 3600, function() use ($config) {
            $globalConfig = Config::get('elastic.translatable', []);
            $modelConfig = $config['translatable'] ?? [];
            
            $mergedConfig = array_merge($globalConfig, $modelConfig);
            
            // Устанавливаем значения по умолчанию, если они отсутствуют
            $mergedConfig['locales'] = $mergedConfig['locales'] ?? ['en'];
            $mergedConfig['fallback_locale'] = $mergedConfig['fallback_locale'] ?? 'en';
            $mergedConfig['index_localized_fields'] = $mergedConfig['index_localized_fields'] ?? true;
            $mergedConfig['auto_detect_translatable'] = $mergedConfig['auto_detect_translatable'] ?? true;
            $mergedConfig['translatable_fields'] = $mergedConfig['translatable_fields'] ?? [];
            
            return $mergedConfig;
        });
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
        // Кэшируем результат для каждого поля
        $cacheKey = 'translatable_field_' . md5($field . serialize($translatableConfig));
        
        return Cache::remember($cacheKey, 3600, function() use ($field, $translatableConfig) {
            if (!$translatableConfig['auto_detect_translatable']) {
                return $this->isFieldInTranslatableList($field, $translatableConfig['translatable_fields'] ?? []);
            }
            
            // Для auto_detect проверяем по списку translatable_fields
            return $this->isFieldInTranslatableList($field, $translatableConfig['translatable_fields'] ?? []);
        });
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
        // Создаем хеш-таблицу для быстрого поиска
        static $fieldCache = [];
        
        $cacheKey = md5(serialize($translatableFields));
        if (!isset($fieldCache[$cacheKey])) {
            $fieldCache[$cacheKey] = $this->buildTranslatableFieldHash($translatableFields);
        }
        
        return isset($fieldCache[$cacheKey][$field]);
    }
    
    /**
     * Строит хеш-таблицу для быстрого поиска translatable полей
     * 
     * @param array $translatableFields Список translatable полей
     * @return array Хеш-таблица полей
     */
    protected function buildTranslatableFieldHash(array $translatableFields): array
    {
        $hash = [];
        
        foreach ($translatableFields as $key => $translatableField) {
            if (is_string($translatableField)) {
                // Простое поле
                $hash[$translatableField] = true;
            } elseif (is_array($translatableField)) {
                // Relation поля
                foreach ($translatableField as $relationField => $relationFields) {
                    if (is_string($relationFields)) {
                        // Простое поле в relation
                        $hash[$relationField . '.' . $relationFields] = true;
                    } elseif (is_array($relationFields)) {
                        // Массив полей в relation
                        foreach ($relationFields as $subFieldKey => $subFieldValue) {
                            if (is_numeric($subFieldKey) && is_string($subFieldValue)) {
                                // Простое поле в relation (числовой ключ)
                                $hash[$relationField . '.' . $subFieldValue] = true;
                            } elseif (is_string($subFieldKey) && is_array($subFieldValue)) {
                                // Вложенные relations
                                foreach ($subFieldValue as $nestedField) {
                                    if (is_string($nestedField)) {
                                        $hash[$relationField . '.' . $subFieldKey . '.' . $nestedField] = true;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        
        return $hash;
    }
} 