<?php

namespace Maratmiftahov\LaravelElastic\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Elastic\Elasticsearch\Client;

/**
 * Команда для поиска в Elasticsearch
 * 
 * Эта команда позволяет тестировать поиск в индексах Elasticsearch
 * с поддержкой различных опций: поиск по конкретной модели, ограничения результатов,
 * поиск в конкретных полях, использование определенных анализаторов.
 * 
 * Поддерживаемые возможности:
 * - Поиск во всех моделях или конкретной модели
 * - Ограничение количества результатов
 * - Поиск в конкретных полях
 * - Использование определенных анализаторов
 * - Подсветка результатов поиска
 */
class SearchCommand extends Command
{
    /**
     * Сигнатура команды с доступными опциями
     * 
     * Аргументы:
     * query - Поисковый запрос
     * 
     * Опции:
     * --model=MODEL    - Поиск в конкретной модели
     * --limit=LIMIT    - Количество результатов (по умолчанию: 10)
     * --offset=OFFSET  - Смещение результатов (по умолчанию: 0)
     * --fields=FIELDS  - Список полей для поиска через запятую
     * --analyzer=ANALYZER - Анализатор для поиска
     */
    protected $signature = 'elastic:search {query} {--model=} {--limit=10} {--offset=0} {--fields=} {--analyzer=}';

    /**
     * Описание команды для справки
     */
    protected $description = 'Search in Elasticsearch indexes

Arguments:
  query                 Search query string

Options:
  --model=MODEL        Search in specific model (e.g., "App\\Models\\Product")
  --limit=LIMIT        Number of results to return (default: 10)
  --offset=OFFSET      Number of results to skip (default: 0)
  --fields=FIELDS      Comma-separated list of fields to search in
  --analyzer=ANALYZER  Analyzer to use for search (default: from config)';

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

        $query = $this->argument('query');
        $model = $this->option('model');
        $limit = (int) $this->option('limit');
        $offset = (int) $this->option('offset');
        $fields = $this->option('fields');
        $analyzer = $this->option('analyzer');

        $this->info("Searching for: '{$query}'");

        // Если указана конкретная модель, ищем только в ней
        if ($model) {
            return $this->searchInModel($model, $query, $limit, $offset, $fields, $analyzer);
        }

        // Иначе ищем во всех моделях
        return $this->searchInAllModels($query, $limit, $offset, $fields, $analyzer);
    }

    /**
     * Поиск в конкретной модели
     * 
     * @param string $modelClass Полное имя класса модели
     * @param string $query Поисковый запрос
     * @param int $limit Количество результатов
     * @param int $offset Смещение результатов
     * @param string|null $fields Поля для поиска
     * @param string|null $analyzer Анализатор для поиска
     * @return int Код возврата
     */
    protected function searchInModel(string $modelClass, string $query, int $limit, int $offset, ?string $fields, ?string $analyzer): int
    {
        $models = Config::get('elastic.models', []);
        
        if (!isset($models[$modelClass])) {
            $this->error("Model {$modelClass} is not configured for indexing.");
            return 1;
        }

        $config = $models[$modelClass];
        $indexName = $this->getIndexName($config);

        if (!$this->indexExists($indexName)) {
            $this->error("Index {$indexName} does not exist. Run 'php artisan elastic:index' first.");
            return 1;
        }

        $searchParams = $this->buildSearchParams($query, $config, $limit, $offset, $fields, $analyzer);
        $searchParams['index'] = $indexName;

        try {
            // Выполняем поиск через API Elasticsearch 8.x
            $response = $this->elasticsearch->search($searchParams);
            $this->displayResults($response->asArray(), $modelClass);
        } catch (\Exception $e) {
            $this->error("Search failed: " . $e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * Поиск во всех настроенных моделях
     * 
     * @param string $query Поисковый запрос
     * @param int $limit Количество результатов
     * @param int $offset Смещение результатов
     * @param string|null $fields Поля для поиска
     * @param string|null $analyzer Анализатор для поиска
     * @return int Код возврата
     */
    protected function searchInAllModels(string $query, int $limit, int $offset, ?string $fields, ?string $analyzer): int
    {
        $models = Config::get('elastic.models', []);
        
        if (empty($models)) {
            $this->error('No models configured for indexing.');
            return 1;
        }

        $this->info('Searching in all configured models...');

        foreach ($models as $modelClass => $config) {
            $indexName = $this->getIndexName($config);
            
            if (!$this->indexExists($indexName)) {
                $this->warn("Index {$indexName} does not exist, skipping.");
                continue;
            }

            $this->line("\n--- Searching in {$modelClass} ---");
            
            $searchParams = $this->buildSearchParams($query, $config, $limit, $offset, $fields, $analyzer);
            $searchParams['index'] = $indexName;

            try {
                $response = $this->elasticsearch->search($searchParams);
                $this->displayResults($response->asArray(), $modelClass);
            } catch (\Exception $e) {
                $this->error("Search failed for {$modelClass}: " . $e->getMessage());
            }
        }

        return 0;
    }

    /**
     * Строит параметры поиска для Elasticsearch
     * 
     * Создает структуру запроса с учетом всех настроек:
     * - multi_match запрос для поиска по нескольким полям
     * - настройки релевантности (fuzziness, minimum_should_match)
     * - подсветка результатов
     * - пагинация
     * 
     * @param string $query Поисковый запрос
     * @param array $config Конфигурация модели
     * @param int $limit Количество результатов
     * @param int $offset Смещение результатов
     * @param string|null $fields Поля для поиска
     * @param string|null $analyzer Анализатор для поиска
     * @return array Параметры для API Elasticsearch
     */
    protected function buildSearchParams(string $query, array $config, int $limit, int $offset, ?string $fields, ?string $analyzer): array
    {
        $searchFields = $this->getSearchFields($config, $fields);
        $searchSettings = Config::get('elastic.search.default', []);

        $searchParams = [
            'body' => [
                'query' => [
                    'multi_match' => [
                        'query' => $query,
                        'fields' => $searchFields,
                        'type' => 'best_fields',
                        'operator' => $searchSettings['operator'] ?? 'OR',
                        'fuzziness' => $searchSettings['fuzziness'] ?? 'AUTO',
                        'minimum_should_match' => $searchSettings['minimum_should_match'] ?? '75%',
                    ],
                ],
                'size' => $limit,
                'from' => $offset,
                'highlight' => [
                    'fields' => $this->getHighlightFields($config),
                ],
            ],
        ];

        if ($analyzer) {
            $searchParams['body']['query']['multi_match']['analyzer'] = $analyzer;
        }

        return $searchParams;
    }

    /**
     * Получает поля для поиска из конфигурации
     * 
     * Поддерживает multi-field mapping для различных анализаторов
     * и boost значения для настройки релевантности
     * 
     * @param array $config Конфигурация модели
     * @param string|null $fields Поля для поиска (если указаны)
     * @return array Массив полей с boost значениями
     */
    protected function getSearchFields(array $config, ?string $fields): array
    {
        if ($fields) {
            return array_map('trim', explode(',', $fields));
        }

        $searchFields = [];
        
        foreach ($config['searchable_fields'] ?? [] as $field => $fieldConfig) {
            $boost = $fieldConfig['boost'] ?? 1.0;
            $searchFields[] = $field . '^' . $boost;

            // Добавляем multi-field mappings
            if (isset($fieldConfig['fields'])) {
                foreach ($fieldConfig['fields'] as $subField => $subFieldConfig) {
                    $subBoost = $subFieldConfig['boost'] ?? 1.0;
                    $searchFields[] = $field . '.' . $subField . '^' . $subBoost;
                }
            }
        }

        return $searchFields;
    }

    /**
     * Получает поля для подсветки результатов
     * 
     * Настраивает подсветку для текстовых полей с оптимальными параметрами
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
     * Отображает результаты поиска в консоли
     * 
     * Форматирует и выводит результаты в виде таблицы
     * с информацией о релевантности и подсветкой совпадений
     * 
     * @param array $response Ответ от Elasticsearch
     * @param string $modelClass Имя модели
     */
    protected function displayResults(array $response, string $modelClass): void
    {
        $hits = $response['hits']['hits'] ?? [];
        $total = $response['hits']['total']['value'] ?? 0;
        $maxScore = $response['hits']['max_score'] ?? 0;

        $this->line("Found {$total} results (max score: {$maxScore})");

        if (empty($hits)) {
            $this->warn('No results found.');
            return;
        }

        $headers = ['Score', 'ID', 'Name', 'Category', 'Brand'];
        $rows = [];

        foreach ($hits as $hit) {
            $source = $hit['_source'] ?? [];
            $highlight = $hit['highlight'] ?? [];
            
            $name = $this->getHighlightedField($highlight, 'name') ?? $source['name'] ?? 'N/A';
            $category = $source['category'] ?? 'N/A';
            $brand = $source['brand'] ?? 'N/A';

            $rows[] = [
                round($hit['_score'], 2),
                $hit['_id'],
                $name,
                $category,
                $brand,
            ];
        }

        $this->table($headers, $rows);
    }

    /**
     * Получает подсвеченное значение поля
     * 
     * @param array $highlight Массив подсвеченных полей
     * @param string $field Имя поля
     * @return string|null Подсвеченное значение или null
     */
    protected function getHighlightedField(array $highlight, string $field): ?string
    {
        if (isset($highlight[$field])) {
            return implode(' ... ', $highlight[$field]);
        }

        return null;
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
} 