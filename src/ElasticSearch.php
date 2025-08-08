<?php

namespace Maratmiftahov\LaravelElastic;

use Elastic\Elasticsearch\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * ElasticSearch - основной класс для работы с Elasticsearch
 * 
 * Предоставляет удобный API для поиска в Elasticsearch с поддержкой:
 * - Multi-field mapping с boost значениями
 * - Подсветки результатов
 * - Мультиязычности (translatable поля)
 * - Relations (связи между моделями)
 * - Кэширования результатов
 * - Асинхронного логирования
 * 
 * @package Maratmiftahov\LaravelElastic
 */
class ElasticSearch
{
    /**
     * Клиент Elasticsearch
     */
    protected $elasticsearch;

    /**
     * Кэш префикса индекса
     */
    private static $indexPrefixCache = null;
    private static $indexPrefixCacheTime = 0;
    private const INDEX_PREFIX_CACHE_TTL = 300; // 5 минут

    /**
     * Кэш проверки translatable полей
     */
    private static $translatableFieldCache = [];
    private static $translatableFieldCacheTime = 0;
    private const TRANSLATABLE_FIELD_CACHE_TTL = 300; // 5 минут

    /**
     * Конструктор
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
     *                       - fields: поля для поиска (если не указаны, используются из конфигурации)
     *                       - boost: boost значения для полей
     *                       - sort: сортировка результатов
     *                       - aggs: агрегации
     *                       - highlight: настройки подсветки
     *                       - analyzer: анализатор для поиска
     * @return Collection Коллекция результатов с метаданными
     * @throws \InvalidArgumentException Если модель не настроена
     * @throws \RuntimeException При ошибке поиска
     */
    public function search(string $modelClass, ?string $query = '', array $options = []): Collection
    {
        $startTime = microtime(true);
        
        // Кэшируем результаты для одинаковых запросов
        $cacheKey = $this->generateSearchCacheKey($modelClass, $query, $options);
        $cachedResult = Cache::get($cacheKey);
        if ($cachedResult !== null) {
            return $cachedResult;
        }

        try {
            // Получаем конфигурацию модели
            $modelsConfig = $this->getCachedModelsConfig();
            $config = $modelsConfig[$modelClass] ?? null;

            if (!$config) {
                throw new \InvalidArgumentException("Configuration not found for model: {$modelClass}");
            }

            // Получаем имя индекса
            $indexName = $this->getIndexName($config);

            // Проверяем существование индекса
            if (!$this->indexExists($indexName)) {
                return collect([])->put('_meta', [
                    'total' => 0,
                    'max_score' => 0,
                    'took' => 0,
                ]);
            }

            // Строим параметры поиска
            $searchParams = $this->buildSearchParams($query, $config, $options);

            // Выполняем поиск
            $response = $this->elasticsearch->search([
                'index' => $indexName,
                ...$searchParams
            ]);

            // Форматируем результаты
            $results = $this->formatResults($response->asArray(), $config, $options);

            // Кэшируем результат на 5 минут
            Cache::put($cacheKey, $results, 300);

            $endTime = microtime(true);
            $executionTime = ($endTime - $startTime) * 1000;

            // Логирование результатов
            $this->logSearchResult($modelClass, $query, $executionTime, $results, $options);

            return $results;

        } catch (\Exception $e) {
            $endTime = microtime(true);
            $executionTime = ($endTime - $startTime) * 1000;

            // Логирование ошибок
            $this->logSearchError($modelClass, $query, $executionTime, $e);

            throw new \RuntimeException("Search failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Получает конфигурацию моделей
     * 
     * @return array Конфигурация моделей
     */
    private function getCachedModelsConfig(): array
    {
        return Config::get('elastic.models', []);
    }

    /**
     * Генерирует ключ кэша для поиска
     * 
     * @param string $modelClass Имя класса модели
     * @param string $query Поисковый запрос
     * @param array $options Опции поиска
     * @return string Ключ кэша
     */
    private function generateSearchCacheKey(string $modelClass, ?string $query, array $options): string
    {
        return 'elastic_search_' . md5($modelClass . (string) $query . serialize($options));
    }

    /**
     * Логирование результатов поиска
     * 
     * @param string $modelClass Имя класса модели
     * @param string $query Поисковый запрос
     * @param float $executionTime Время выполнения в миллисекундах
     * @param Collection $results Результаты поиска
     * @param array $options Опции поиска
     */
    private function logSearchResult(string $modelClass, string $query, float $executionTime, Collection $results, array $options): void
    {
        Log::info("ElasticSearch search completed", [
            'model' => $modelClass,
            'query' => $query,
            'execution_time_ms' => round($executionTime, 2),
            'results_count' => $results->count(),
            'total' => $results->get('_meta.total', 0),
            'options' => $options
        ]);
    }

    /**
     * Логирование ошибок поиска
     * 
     * @param string $modelClass Имя класса модели
     * @param string $query Поисковый запрос
     * @param float $executionTime Время выполнения в миллисекундах
     * @param \Exception $e Исключение
     */
    private function logSearchError(string $modelClass, string $query, float $executionTime, \Exception $e): void
    {
        Log::error("ElasticSearch search failed", [
            'model' => $modelClass,
            'query' => $query,
            'execution_time_ms' => round($executionTime, 2),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
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
        // Кэшируем параметры для одинаковых запросов
        $cacheKey = $this->generateBuildParamsCacheKey($query, $config, $options);
        $cachedParams = Cache::get($cacheKey);
        if ($cachedParams !== null) {
            return $cachedParams;
        }

        // Получаем поля для поиска с учетом boost из конфигурации
        $searchFields = $this->getSearchFieldsWithBoost($config, $options['fields'] ?? null, $options['boost'] ?? null);
        $searchSettings = $this->getCachedSearchSettings();

        // Проверяем, что у нас есть поля для поиска
        if (empty($searchFields)) {
            throw new \RuntimeException("No search fields found. Check configuration.");
        }

        // Проверяем, пустой ли запрос
        $isEmptyQuery = empty(trim($query));
        
        $searchParams = [
            'body' => [
                'size' => $options['limit'] ?? 10,
                'from' => $options['offset'] ?? 0,
                // Гарантируем корректный подсчет общего количества результатов
                'track_total_hits' => $options['track_total_hits'] ?? true,
            ],
        ];
        
        if ($isEmptyQuery) {
            // Если запрос пустой, используем match_all для получения всех записей
            $searchParams['body']['query'] = [
                'match_all' => new \stdClass()
            ];
        } else {
            // Обычный поиск
            $searchParams['body']['query'] = [
                'multi_match' => [
                    'query' => $query,
                    'fields' => $searchFields,
                    'type' => $options['type'] ?? 'best_fields',
                    'operator' => $options['operator'] ?? $searchSettings['operator'] ?? 'OR',
                    'fuzziness' => $options['fuzziness'] ?? $searchSettings['fuzziness'] ?? 'AUTO',
                    'minimum_should_match' => $options['minimum_should_match'] ?? $searchSettings['minimum_should_match'] ?? '75%',
                ],
            ];
        }

        // Оптимизированные условные проверки
        $this->addOptionalSearchParams($searchParams, $options, $config);

        // Кэшируем результат на 1 час
        Cache::put($cacheKey, $searchParams, 3600);

        return $searchParams;
    }

    /**
     * Получает настройки поиска
     */
    private function getCachedSearchSettings(): array
    {
        return Config::get('elastic.search.default', []);
    }

    /**
     * Генерирует ключ кэша для параметров поиска
     */
    private function generateBuildParamsCacheKey(string $query, array $config, array $options): string
    {
        return 'build_params_' . md5($query . serialize($config) . serialize($options));
    }

    /**
     * Добавляет опциональные параметры поиска
     */
    private function addOptionalSearchParams(array &$searchParams, array $options, array $config): void
    {
        // Корректная поддержка function_score: если указаны boost_mode/score_mode/functions —
        // оборачиваем текущий query в function_score, вместо того чтобы пытаться задавать эти
        // параметры внутри multi_match (что не поддерживается Elasticsearch)
        $shouldWrapWithFunctionScore = isset($options['boost_mode']) || isset($options['score_mode']) || isset($options['functions']);
        if ($shouldWrapWithFunctionScore) {
            $currentQuery = $searchParams['body']['query'] ?? ['match_all' => new \stdClass()];
            $functionScore = [
                'function_score' => [
                    'query' => $currentQuery,
                ],
            ];

            if (isset($options['functions']) && is_array($options['functions'])) {
                $functionScore['function_score']['functions'] = $options['functions'];
            }
            if (isset($options['boost_mode'])) {
                $functionScore['function_score']['boost_mode'] = $options['boost_mode'];
            }
            if (isset($options['score_mode'])) {
                $functionScore['function_score']['score_mode'] = $options['score_mode'];
            }

            $searchParams['body']['query'] = $functionScore;
        }

        // Добавляем фильтры
        if (isset($options['filter']) && !empty($options['filter'])) {
            $currentQuery = $searchParams['body']['query'];
            
            // Если у нас уже bool query (с фильтрами), добавляем к существующим фильтрам
            if (isset($currentQuery['bool'])) {
                $searchParams['body']['query']['bool']['filter'] = array_merge(
                    $currentQuery['bool']['filter'] ?? [],
                    $this->buildFilterQuery($options['filter'])
                );
            } else {
                // Преобразуем текущий query в bool query с фильтрами
                $searchParams['body']['query'] = [
                    'bool' => [
                        'must' => [
                            $currentQuery
                        ],
                        'filter' => $this->buildFilterQuery($options['filter'])
                    ]
                ];
            }
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
        //if ($options['highlight'] ?? true) {
        //    $searchParams['body']['highlight'] = [
        //        'fields' => $this->getHighlightFields($config),
        //    ];
        //}

        // Добавляем анализатор
        if (isset($options['analyzer'])) {
            $searchParams['body']['query']['multi_match']['analyzer'] = $options['analyzer'];
        }
    }

    /**
     * Строит фильтр для Elasticsearch query
     * 
     * @param array $filters Массив фильтров
     * @return array Массив фильтров для Elasticsearch
     */
    private function buildFilterQuery(array $filters): array
    {
        $filterQueries = [];

        foreach ($this->normalizeFilters($filters) as [$field, $value]) {
            if (is_array($value)) {
                // Range: ['gte' => 10, 'lte' => 100]
                if ($this->isRangeArray($value)) {
                    $filterQueries[] = [
                        'range' => [
                            $field => $value,
                        ],
                    ];
                    continue;
                }

                // Terms: numeric array of values
                if (!$this->isAssoc($value)) {
                    $filterQueries[] = [
                        'terms' => [
                            $field => array_values($value),
                        ],
                    ];
                    continue;
                }

                // If associative array but not range, try to cast to terms if values keyed arbitrarily
                $filterQueries[] = [
                    'terms' => [
                        $field => array_values($value),
                    ],
                ];
            } else {
                // Term: scalar value
                $filterQueries[] = [
                    'term' => [
                        $field => $value,
                    ],
                ];
            }
        }

        return $filterQueries;
    }

    /**
     * Нормализует входные фильтры в список пар [field, value]
     */
    private function normalizeFilters(array $filters): array
    {
        $normalized = [];

        // Вариант 1: список элементов, каждый из которых — ассоциативная пара field => value
        $isList = array_keys($filters) === range(0, count($filters) - 1);
        if ($isList) {
            foreach ($filters as $item) {
                if (is_array($item)) {
                    foreach ($item as $field => $value) {
                        $normalized[] = [$field, $value];
                    }
                }
            }
            return $normalized;
        }

        // Вариант 2: ассоциативный массив field => value
        foreach ($filters as $field => $value) {
            $normalized[] = [$field, $value];
        }

        return $normalized;
    }

    /**
     * Проверяет, является ли массив ассоциативным
     */
    private function isAssoc(array $array): bool
    {
        if ($array === []) {
            return false;
        }
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Проверяет, похож ли массив на range-условие
     */
    private function isRangeArray(array $array): bool
    {
        if ($array === []) {
            return false;
        }
        $allowed = ['gte', 'lte', 'gt', 'lt'];
        foreach (array_keys($array) as $key) {
            if (!in_array($key, $allowed, true)) {
                return false;
            }
        }
        return true;
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
                    $result = [];
                    foreach ($fields as $field) {
                        $fieldName = is_array($field) ? $field[0] : $field;
                        $fieldBoost = $boost[$fieldName] ?? 1.0;
                        $result[] = $fieldName . '^' . $fieldBoost;
                    }
                    return $result;
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
        if (empty($searchableFields)) {
            return;
        }

        foreach ($searchableFields as $field => $fieldConfig) {
            // Оптимизированные проверки типов
            if (is_string($fieldConfig)) {
                // Простое поле (числовой ключ)
                $this->addFieldWithBoost($fieldConfig, $searchFields, $boostConfig, $translatableConfig);
            } elseif (is_array($fieldConfig)) {
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
        
        // Кэшированная проверка translatable поля
        if ($this->isFieldTranslatableCached($field, $translatableConfig)) {
            // Для translatable полей добавляем поля для каждого языка
            $locales = $translatableConfig['locales'];
            $boostSuffix = '^' . $fieldBoost;
            
            foreach ($locales as $locale) {
                $searchFields[] = $field . '_' . $locale . $boostSuffix;
            }
        } else {
            // Для обычных полей добавляем одно поле
            $searchFields[] = $field . '^' . $fieldBoost;
        }
    }

    /**
     * Кэшированная проверка translatable поля
     */
    private function isFieldTranslatableCached(string $field, array $translatableConfig): bool
    {
        $currentTime = time();
        
        // Очищаем кэш если истекло время
        if (($currentTime - self::$translatableFieldCacheTime) > self::TRANSLATABLE_FIELD_CACHE_TTL) {
            self::$translatableFieldCache = [];
            self::$translatableFieldCacheTime = $currentTime;
        }
        
        $cacheKey = $field . '_' . md5(serialize($translatableConfig));
        
        if (!isset(self::$translatableFieldCache[$cacheKey])) {
            self::$translatableFieldCache[$cacheKey] = $this->isFieldTranslatable($field, $translatableConfig);
        }
        
        return self::$translatableFieldCache[$cacheKey];
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
        if (empty($relationFields)) {
            return;
        }

        foreach ($relationFields as $field => $fieldConfig) {
            // Оптимизированные проверки типов
            if (is_string($fieldConfig)) {
                // Простое поле в relation
                $fullField = $relationName . '.' . $fieldConfig;
                $this->addRelationFieldWithBoost($relationName, $fieldConfig, $fullField, $searchFields, $boostConfig, $translatableConfig);
            } elseif (is_array($fieldConfig)) {
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
        if ($this->isFieldTranslatableCached($fullField, $translatableConfig)) {
            // Для translatable полей добавляем поля для каждого языка
            $locales = $translatableConfig['locales'];
            $boostSuffix = '^' . $fieldBoost;
            
            foreach ($locales as $locale) {
                $searchFields[] = $fullField . '_' . $locale . $boostSuffix;
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
        if (empty($relationFields)) {
            return;
        }

        foreach ($relationFields as $field => $fieldConfig) {
            // Оптимизированные проверки типов
            if (is_string($fieldConfig)) {
                // Простое поле во вложенном relation
                $fullField = $relationPath . '.' . $fieldConfig;
                $this->addNestedRelationFieldWithBoost($relationPath, $fieldConfig, $fullField, $searchFields, $boostConfig, $translatableConfig);
            } elseif (is_array($fieldConfig)) {
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
        if ($this->isFieldTranslatableCached($fullField, $translatableConfig)) {
            // Для translatable полей добавляем поля для каждого языка
            $locales = $translatableConfig['locales'];
            $boostSuffix = '^' . $fieldBoost;
            
            foreach ($locales as $locale) {
                $searchFields[] = $fullField . '_' . $locale . $boostSuffix;
            }
        } else {
            // Для обычных полей добавляем одно поле
            $searchFields[] = $fullField . '^' . $fieldBoost;
        }
    }

    /**
     * Получает boost значение для поля во вложенном relation
     * 
     * @param string $relationPath Путь к relation
     * @param string $relationField Имя поля в relation
     * @param array $boostConfig Конфигурация boost
     * @return float Boost значение
     */
    protected function getNestedRelationBoost(string $relationPath, string $relationField, array $boostConfig): float
    {
        // Оптимизированный разбор пути
        $pathParts = explode('.', $relationPath);
        $currentConfig = $boostConfig;
        
        // Проходим по пути к relation
        foreach ($pathParts as $part) {
            if (isset($currentConfig[$part]) && is_array($currentConfig[$part])) {
                $currentConfig = $currentConfig[$part];
            } else {
                return 1.0; // Значение по умолчанию
            }
        }
        
        // Возвращаем boost для конкретного поля
        return $currentConfig[$relationField] ?? 1.0;
    }

    /**
     * Форматирует результаты поиска
     * 
     * Преобразует ответ Elasticsearch в удобную коллекцию Laravel
     * с добавлением подсветки, метаданных поиска и данных из БД.
     * 
     * @param array $response Ответ от Elasticsearch
     * @param array $config Конфигурация модели
     * @param array $options Дополнительные опции
     * @return Collection Коллекция результатов с метаданными
     */
    protected function formatResults(array $response, array $config, array $options = []): Collection
    {
        $hits = $response['hits']['hits'] ?? [];
        $total = $response['hits']['total']['value'] ?? 0;
        $maxScore = $response['hits']['max_score'] ?? 0;

        // Извлекаем ID из результатов Elasticsearch (оптимизированно)
        $ids = [];
        $scores = [];
        $highlights = [];
        
        foreach ($hits as $hit) {
            $id = $hit['_id'];
            $ids[] = $id;
            $scores[$id] = $hit['_score'] ?? 0;
            $highlights[$id] = $hit['highlight'] ?? [];
        }

        // Если есть ID и настроены return_fields, загружаем данные из БД
        if (!empty($ids) && isset($config['return_fields'])) {
            $loadFromDb = $options['load_from_db'] ?? true;
            if ($loadFromDb) {
                $dbResults = $this->loadDataFromDatabase($ids, $config);
                
                // Объединяем данные из БД с результатами Elasticsearch
                $results = [];
                foreach ($dbResults as $item) {
                    $id = $item['id'];
                    
                    // Добавляем скор из Elasticsearch
                    $item['_score'] = $scores[$id] ?? 0;
                    $item['_id'] = $id;
                    
                    // Добавляем подсветку
                    //$this->addHighlightsToItem($item, $highlights[$id] ?? []); временно отключено
                    
                    $results[] = $item;
                }
            } else {
                // Если load_from_db false, возвращаем только данные из Elasticsearch
                $results = $this->processElasticsearchOnlyResults($hits);
            }
        } else {
            // Fallback: возвращаем только данные из Elasticsearch
            $results = $this->processElasticsearchOnlyResults($hits);
        }

        // Создаем коллекцию и добавляем метаданные
        $collection = collect($results);
        $collection->put('_meta', [
            'total' => $total,
            'max_score' => $maxScore,
            'took' => $response['took'] ?? 0,
        ]);

        return $collection;
    }

    /**
     * Обрабатывает результаты только из Elasticsearch
     * 
     * @param array $hits Результаты из Elasticsearch
     * @return array Обработанные результаты
     */
    private function processElasticsearchOnlyResults(array $hits): array
    {
        $results = [];
        foreach ($hits as $hit) {
            $source = $hit['_source'] ?? [];
            $highlight = $hit['highlight'] ?? [];

            // Добавляем подсветку к исходным данным
            $this->addHighlightsToItem($source, $highlight);

            // Добавляем скор и ID
            $source['_score'] = $hit['_score'];
            $source['_id'] = $hit['_id'];

            $results[] = $source;
        }
        
        return $results;
    }

    /**
     * Добавляет подсветку к элементу результата
     * 
     * @param array $item Элемент результата
     * @param array $highlights Подсветка из Elasticsearch
     */
    private function addHighlightsToItem(array &$item, array $highlights): void
    {
        foreach ($highlights as $field => $fragments) {
            $item['highlight_' . $field] = implode(' ... ', $fragments);
        }
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
        $prefix = $this->getCachedIndexPrefix();
        
        if ($prefix) {
            $indexName = $prefix . '_' . $indexName;
        }

        return $indexName;
    }

    /**
     * Получает кэшированный префикс индекса
     * 
     * @return string Префикс индекса
     */
    private function getCachedIndexPrefix(): string
    {
        $currentTime = time();
        
        if (self::$indexPrefixCache === null || 
            ($currentTime - self::$indexPrefixCacheTime) > self::INDEX_PREFIX_CACHE_TTL) {
            self::$indexPrefixCache = Config::get('elastic.index.prefix', '');
            self::$indexPrefixCacheTime = $currentTime;
        }
        
        return self::$indexPrefixCache;
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

        // Кэшируем результаты запроса к БД
        $cacheKey = 'db_results_' . $modelClass . '_' . md5(serialize($ids) . serialize($config['return_fields'] ?? []));
        
        return Cache::remember($cacheKey, 3600, function() use ($ids, $config, $modelClass, $startTime) {
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
                
                // Если есть relations, добавляем foreign keys и primary key
                if (!empty($relations)) {
                    $foreignKeys = $this->getForeignKeysForRelations($modelClass, array_keys($relations));
                    $selectFields = array_merge($selectFields, $foreignKeys);
                    
                    // Добавляем primary key, если его нет в selectFields
                    // Это необходимо для загрузки HasMany, HasOne relations
                    $primaryKey = (new $modelClass())->getKeyName();
                    if (!in_array($primaryKey, $selectFields)) {
                        $selectFields[] = $primaryKey;
                    }
                }
                
                $query->select($selectFields);
            }
            
            // Добавляем отношения с их собственными select
            foreach ($relations as $relation => $relationConfig) {
                $query->with([$relation => function ($query) use ($relationConfig) {
                    $this->applyRelationConfig($query, $relationConfig);
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
        });
    }

    /**
     * Строит конфигурацию запроса для отношения
     * 
     * @param array $relationFields Поля отношения
     * @return array Конфигурация запроса
     */
    protected function buildRelationQuery(array $relationFields): array
    {
        // Кэшируем результат построения запроса для relations
        $cacheKey = 'relation_query_' . md5(serialize($relationFields));
        
        return Cache::remember($cacheKey, 3600, function() use ($relationFields) {
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
        });
    }

    /**
     * Применяет конфигурацию к запросу отношения
     * 
     * @param \Illuminate\Database\Eloquent\Builder $query Запрос
     * @param array $config Конфигурация отношения
     */
    protected function applyRelationConfig($query, array $config): void
    {
        // Применяем select для текущего уровня
        if (isset($config['select']) && !empty($config['select'])) {
            $query->select($config['select']);
        }
        
        // Рекурсивно применяем вложенные relations
        if (isset($config['with']) && !empty($config['with'])) {
            foreach ($config['with'] as $nestedRelation => $nestedConfig) {
                $query->with([$nestedRelation => function ($nestedQuery) use ($nestedConfig) {
                    $this->applyRelationConfig($nestedQuery, $nestedConfig);
                }]);
            }
        }
    }

    /**
     * Получает имя класса модели из конфигурации
     * 
     * @param array $config Конфигурация модели
     * @return string|null Имя класса модели
     */
    protected function getModelClassFromConfig(array $config): ?string
    {
        // Кэшируем результат поиска модели по конфигурации
        $cacheKey = 'model_class_' . md5(serialize($config));
        
        return Cache::remember($cacheKey, 3600, function() use ($config) {
            $models = Config::get('elastic.models', []);
            
            foreach ($models as $modelClass => $modelConfig) {
                if ($modelConfig === $config) {
                    return $modelClass;
                }
            }
            
            return null;
        });
    }

    /**
     * Получает foreign keys для всех указанных отношений
     * 
     * @param string $modelClass Имя класса модели
     * @param array $relations Массив имен отношений
     * @return array Массив foreign keys
     */
    protected function getForeignKeysForRelations(string $modelClass, array $relations): array
    {
        if (empty($relations)) {
            return [];
        }

        $foreignKeys = [];
        
        foreach ($relations as $relation) {
            $foreignKey = $this->getForeignKeyForRelation($modelClass, $relation);
            if ($foreignKey) {
                $foreignKeys[] = $foreignKey;
            }
        }
        
        return array_unique($foreignKeys);
    }

    /**
     * Получает foreign key для конкретного отношения
     * 
     * @param string|object $model Имя класса модели или экземпляр модели
     * @param string $relation Имя отношения
     * @return string|null Foreign key для отношения
     */
    protected function getForeignKeyForRelation($model, string $relation): ?string
    {
        $modelClass = is_string($model) ? $model : get_class($model);
        
        // Создаем экземпляр модели только если нужно
        $modelInstance = is_object($model) ? $model : new $model();
        
        // Получаем информацию о relation
        $relationMethod = $modelInstance->$relation();
        
        if ($relationMethod instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
            // Для BelongsTo нужен foreign key в основной таблице
            return $relationMethod->getForeignKeyName();
        } elseif ($relationMethod instanceof \Illuminate\Database\Eloquent\Relations\HasOne) {
            // Для HasOne foreign key находится в связанной таблице, но нам нужен primary key основной модели
            // Laravel автоматически использует primary key для загрузки HasOne
            return null;
        } elseif ($relationMethod instanceof \Illuminate\Database\Eloquent\Relations\HasMany) {
            // Для HasMany foreign key находится в связанной таблице, но нам нужен primary key основной модели
            // Laravel автоматически использует primary key для загрузки HasMany
            return null;
        } elseif ($relationMethod instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
            // Для BelongsToMany нужен foreign key в pivot таблице
            return $relationMethod->getForeignPivotKeyName();
        }
        
        return null;
    }

    /**
     * Проверяет существование индекса в Elasticsearch
     * 
     * @param string $indexName Имя индекса
     * @return bool True если индекс существует, false в противном случае
     */
    protected function indexExists(string $indexName): bool
    {
        // Кэшируем результат проверки существования индекса на 5 минут
        $cacheKey = 'index_exists_' . $indexName;
        
        return Cache::remember($cacheKey, 300, function() use ($indexName) {
            try {
                // Используем API Elasticsearch 8.x для проверки существования индекса
                $response = $this->elasticsearch->indices()->exists(['index' => $indexName]);
                return $response->getStatusCode() === 200;
            } catch (\Exception $e) {
                return false;
            }
        });
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
        if (empty($translatableFields)) {
            return [];
        }

        $hash = [];
        
        foreach ($translatableFields as $key => $translatableField) {
            // Оптимизированные проверки типов
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
                            if (is_string($subFieldValue)) {
                                // Простое поле в relation (числовой ключ)
                                $hash[$relationField . '.' . $subFieldValue] = true;
                            } elseif (is_array($subFieldValue)) {
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
        $highlightFields = [];
        $translatableConfig = $this->getTranslatableConfig($config);
        
        $this->extractHighlightFieldsFromConfig(
            $config['searchable_fields'] ?? [], 
            $highlightFields,
            $translatableConfig
        );

        return $highlightFields;
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
        if (empty($searchableFields)) {
            return;
        }

        foreach ($searchableFields as $field => $fieldConfig) {
            // Оптимизированные проверки типов
            if (is_string($fieldConfig)) {
                // Простое поле (числовой ключ)
                $this->addHighlightField($fieldConfig, $highlightFields, $translatableConfig);
            } elseif (is_array($fieldConfig)) {
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
        if ($this->isFieldTranslatableCached($field, $translatableConfig)) {
            // Для translatable полей добавляем поля для каждого языка
            $locales = $translatableConfig['locales'];
            
            foreach ($locales as $locale) {
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
        if (empty($relationFields)) {
            return;
        }

        foreach ($relationFields as $field => $fieldConfig) {
            // Оптимизированные проверки типов
            if (is_string($fieldConfig)) {
                // Простое поле в relation
                $fullField = $relationName . '.' . $fieldConfig;
                $this->addHighlightField($fullField, $highlightFields, $translatableConfig);
            } elseif (is_array($fieldConfig)) {
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
        if (empty($relationFields)) {
            return;
        }

        foreach ($relationFields as $field => $fieldConfig) {
            // Оптимизированные проверки типов
            if (is_string($fieldConfig)) {
                // Простое поле во вложенном relation
                $fullField = $relationPath . '.' . $fieldConfig;
                $this->addHighlightField($fullField, $highlightFields, $translatableConfig);
            } elseif (is_array($fieldConfig)) {
                // Еще более вложенное relation
                $this->extractNestedRelationHighlightFields($relationPath . '.' . $field, $fieldConfig, $highlightFields, $translatableConfig);
            }
        }
    }
} 