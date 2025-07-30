<?php

namespace Maratmiftahov\LaravelElastic;

use Elastic\Elasticsearch\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

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
            return $this->formatResults($response->asArray(), $config);
        } catch (\Exception $e) {
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
     * - настройки релевантности из конфигурации
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
        $searchFields = $this->getSearchFields($config, $options['fields'] ?? null, $options['boost'] ?? null);
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
     * Получает поля для поиска из конфигурации
     * 
     * Поддерживает multi-field mapping для различных анализаторов
     * и boost значения из опций поиска (не из маппингов).
     * 
     * @param array $config Конфигурация модели
     * @param array|null $fields Поля для поиска (если указаны)
     * @param array|null $boost Boost значения для полей
     * @return array Массив полей с boost значениями
     */
    protected function getSearchFields(array $config, ?array $fields, ?array $boost): array
    {
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
        
        foreach ($config['searchable_fields'] ?? [] as $field => $fieldConfig) {
            // Используем boost из опций поиска, а не из маппинга
            $fieldBoost = $boost[$field] ?? 1.0;
            $searchFields[] = $field . '^' . $fieldBoost;

            // Добавляем multi-field mappings
            if (isset($fieldConfig['fields'])) {
                foreach ($fieldConfig['fields'] as $subField => $subFieldConfig) {
                    $subFieldName = $field . '.' . $subField;
                    $subFieldBoost = $boost[$subFieldName] ?? 1.0;
                    $searchFields[] = $subFieldName . '^' . $subFieldBoost;
                }
            }
        }

        return $searchFields;
    }

    /**
     * Получает поля для автодополнения
     * 
     * Ищет специальные поля с autocomplete анализатором
     * или использует обычные поля без boost значений из маппинга.
     * 
     * @param array $config Конфигурация модели
     * @return array Массив полей для автодополнения
     */
    protected function getAutocompleteFields(array $config): array
    {
        $autocompleteFields = [];
        
        foreach ($config['searchable_fields'] ?? [] as $field => $fieldConfig) {
            if (isset($fieldConfig['fields']['autocomplete'])) {
                // Используем поле autocomplete без boost из маппинга
                $autocompleteFields[] = $field . '.autocomplete';
            } else {
                // Используем обычное поле без boost из маппинга
                $autocompleteFields[] = $field;
            }
        }

        return $autocompleteFields;
    }

    /**
     * Получает поля для подсветки результатов
     * 
     * Настраивает подсветку для текстовых полей с оптимальными параметрами
     * для отображения фрагментов текста с совпадениями.
     * 
     * @param array $config Конфигурация модели
     * @return array Конфигурация подсветки для Elasticsearch
     */
    protected function getHighlightFields(array $config): array
    {
        $highlightFields = [];
        
        foreach ($config['searchable_fields'] ?? [] as $field => $fieldConfig) {
            if ($fieldConfig['type'] === 'text') {
                $highlightFields[$field] = [
                    'type' => 'unified',
                    'fragment_size' => 150,
                    'number_of_fragments' => 3,
                ];
            }
        }

        return $highlightFields;
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
        // Проверяем, существует ли relation
        if (!method_exists($model, $relation)) {
            return null;
        }
        
        // Получаем relation метод
        $relationMethod = new \ReflectionMethod($model, $relation);
        $relationInstance = $relationMethod->invoke($model);
        
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
} 