<?php

namespace Maratmiftahov\LaravelElastic;

use Elastic\Elasticsearch\Client;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ElasticSearch
{
    public function __construct(
        private Client $client,
        private ?CacheRepository $cache = null,
    ) {}

    public function search(string $modelClass, string $query, array $options = []): array
    {
        $modelsConfig = \config('elastic.models');
        $cfg = $modelsConfig[$modelClass] ?? null;
        if (! $cfg) {
            throw new \InvalidArgumentException("Model {$modelClass} is not configured in elastic.models");
        }

        $indexBase = $cfg['index'] ?? Str::slug(\class_basename($modelClass));
        $indexPrefix = \config('elastic.index_settings.prefix', '');
        $index = $indexPrefix ? $indexPrefix.'_'.$indexBase : $indexBase;

        $params = $this->buildSearchParams([$index], $cfg, $query, $options);

        $cacheTtl = \config('elastic.cache.ttl');
        $useCache = (bool) (\config('elastic.cache.enabled', true) && ! empty($options['cache']));
        $cacheKey = null;
        if ($useCache && $this->cache) {
            $cacheKey = 'elastic:'.md5(json_encode($params));
            $cached = $this->cache->get($cacheKey);
            if ($cached) {
                return $cached;
            }
        }

        $response = $this->client->search($params)->asArray();
        $result = $this->formatResults($response, $modelClass, $cfg, $options);

        if ($useCache && $this->cache) {
            $this->cache->put($cacheKey, $result, $cacheTtl);
        }

        return $result;
    }

    public function multiSearch(array $modelClasses, string $query, array $options = []): array
    {
        // New msearch implementation that returns formatted results per model class
        $modelsConfig = \config('elastic.models');
        $indexPrefix = \config('elastic.index_settings.prefix', '');

        $body = [];
        $map = [];
        foreach ($modelClasses as $class) {
            $cfg = $modelsConfig[$class] ?? null;
            if (! $cfg) {
                continue;
            }
            $indexBase = $cfg['index'] ?? Str::slug(\class_basename($class));
            $indexName = $indexPrefix ? $indexPrefix.'_'.$indexBase : $indexBase;
            $map[] = [$class, $cfg];
            $body[] = ['index' => $indexName];
            $body[] = $this->buildSearchBody($cfg, $query, $options);
        }

        if (empty($body)) {
            return [];
        }

        $response = $this->client->msearch(['body' => $body])->asArray();
        $results = [];
        $responses = $response['responses'] ?? [];
        foreach ($responses as $i => $res) {
            [$class, $cfg] = $map[$i];
            $results[$class] = $this->formatResults($res, $class, $cfg, $options);
        }

        return $results;
    }

    protected function buildSearchBody(array $cfg, string $query, array $options): array
    {
        $limit = $options['limit'] ?? \config('elastic.search.default.limit', 20);
        $offset = $options['offset'] ?? \config('elastic.search.default.offset', 0);

        $body = [
            'from' => $offset,
            'size' => $limit,
            'query' => $this->buildQuery($cfg, $query, $options),
        ];
        if (($options['highlight'] ?? \config('elastic.search.highlight.enabled', true)) === true) {
            $body['highlight'] = [
                'fields' => array_fill_keys(\config('elastic.search.highlight.fields', ['*']), new \stdClass),
                'fragment_size' => \config('elastic.search.highlight.fragment_size', 150),
                'number_of_fragments' => \config('elastic.search.highlight.number_of_fragments', 3),
            ];
        }
        if (! empty($options['filter'])) {
            $body['query'] = [
                'bool' => [
                    'must' => [$body['query']],
                    'filter' => $this->buildFilters((array) $options['filter']),
                ],
            ];
        }
        if (! empty($options['sort'])) {
            $body['sort'] = $this->buildSort((array) $options['sort']);
        }
        if (! empty($options['aggs'])) {
            $body['aggs'] = (array) $options['aggs'];
        }
        if (array_key_exists('track_total_hits', $options)) {
            $body['track_total_hits'] = (bool) $options['track_total_hits'];
        }

        return $body;
    }

    protected function buildSearchParams(array $indices, ?array $cfg, string $query, array $options): array
    {
        $limit = $options['limit'] ?? \config('elastic.search.default.limit', 20);
        $offset = $options['offset'] ?? \config('elastic.search.default.offset', 0);

        $searchBody = [
            'from' => $offset,
            'size' => $limit,
            'query' => $this->buildQuery($cfg, $query, $options),
        ];

        // highlight
        if (($options['highlight'] ?? \config('elastic.search.highlight.enabled', true)) === true) {
            $searchBody['highlight'] = [
                'fields' => array_fill_keys(\config('elastic.search.highlight.fields', ['*']), new \stdClass),
                'fragment_size' => \config('elastic.search.highlight.fragment_size', 150),
                'number_of_fragments' => \config('elastic.search.highlight.number_of_fragments', 3),
            ];
        }

        // filters
        if (! empty($options['filter'])) {
            $searchBody['query'] = [
                'bool' => [
                    'must' => [$searchBody['query']],
                    'filter' => $this->buildFilters((array) $options['filter']),
                ],
            ];
        }

        // sort
        if (! empty($options['sort'])) {
            $searchBody['sort'] = $this->buildSort((array) $options['sort']);
        }

        // aggregations
        if (! empty($options['aggs'])) {
            $searchBody['aggs'] = (array) $options['aggs'];
        }

        // allow callers to reduce count overhead
        if (array_key_exists('track_total_hits', $options)) {
            $searchBody['track_total_hits'] = (bool) $options['track_total_hits'];
        }

        return [
            'index' => implode(',', $indices),
            'body' => $searchBody,
        ];
    }

    protected function buildQuery(?array $cfg, string $query, array $options): array
    {
        $trimmed = trim((string) $query);
        // Пустой запрос: возвращаем match_all, чтобы категории/страницы листинга работали с фильтрами
        if ($trimmed === '') {
            return ['match_all' => new \stdClass];
        }

        $fuzzyCfg = \config('elastic.search.fuzzy');
        $fuzzyEnabled = (bool) ($options['fuzzy'] ?? $fuzzyCfg['enabled'] ?? true);
        $operator = $options['operator'] ?? \config('elastic.search.default.operator', 'and');

        // Build field list including localized variants and subfields
        $translatableFields = (array) ($cfg['translatable']['fields'] ?? []);
        $fields = $this->expandFields(($cfg['searchable_fields'] ?? []), $translatableFields);
        // keep only text/keyword-capable fields to avoid fuzzy on boolean/numeric fields
        $locales = \config('elastic.translatable.locales', []);
        $allowedTextBase = array_values(array_unique(array_merge($translatableFields, ['search_data', 'size_terms', 'code', 'isbn_code', 'title', 'slug'])));
        $fields = array_values(array_filter($fields, function ($f) use ($locales, $allowedTextBase) {
            $parts = explode('__', $f);
            $last = end($parts) ?: $f;
            // strip locale suffix if present
            foreach ($locales as $loc) {
                if (str_ends_with($last, '_'.$loc)) {
                    $last = substr($last, 0, -1 * (strlen($loc) + 1));
                    break;
                }
            }

            return in_array($last, $allowedTextBase, true);
        }));
        $fieldsWithBoost = $this->applyBoosts($fields, $cfg['searchable_fields_boost'] ?? []);

        $multiMatch = [
            'multi_match' => [
                'query' => $query,
                'type' => \config('elastic.search.default.type', 'best_fields'),
                'operator' => $operator,
                'fields' => $fieldsWithBoost,
                'lenient' => true, // Избегаем ошибок при поиске текста в числовых полях
            ],
        ];
        if ($fuzzyEnabled) {
            $multiMatch['multi_match']['fuzziness'] = $fuzzyCfg['fuzziness'] ?? 'AUTO';
            $multiMatch['multi_match']['prefix_length'] = $fuzzyCfg['prefix_length'] ?? 2;
        }

        $keywordBoost = (float) (\config('elastic.search.keyword_match.boost', 15.0));
        $exactBoost = (float) (\config('elastic.search.exact_match.boost', 10.0));
        $currentLocale = app()->getLocale();

        $should = [];
        $must = [];

        // Extract numeric tokens (potential size terms)
        $sizeTokens = $this->extractNumericTokens($query);
        $hasSizeField = in_array('size_terms', (array) ($cfg['searchable_fields'] ?? []), true)
            || array_key_exists('size_terms', (array) ($cfg['computed_fields'] ?? []));
        // Считаем запрос «размероподобным», только если в нём минимум два числа
        // или присутствуют разделители размеров (x, -).
        $isSizeLike = (count($sizeTokens) >= 2) || (preg_match('/[xX\-]/', $query) === 1);
        if ($hasSizeField && $isSizeLike) {
            $must[] = [
                'match' => [
                    'size_terms' => [
                        'query' => implode(' ', $sizeTokens),
                        'operator' => 'and',
                    ],
                ],
            ];
        }

        // Для численных запросов добавляем точное совпадение по code/isbn_code с высоким boost
        $isNumericQuery = preg_match('/^\d+$/', trim($query)) === 1;
        if ($isNumericQuery) {
            $numericBoost = 20.0; // Очень высокий приоритет для точного совпадения кода
            $should[] = [
                'term' => [
                    'code' => [
                        'value' => $query,
                        'boost' => $numericBoost,
                    ],
                ],
            ];
            $should[] = [
                'term' => [
                    'isbn_code' => [
                        'value' => $query,
                        'boost' => $numericBoost,
                    ],
                ],
            ];
        }
        // exact keyword clause
        foreach ($fields as $f) {
            $should[] = [
                'term' => [
                    $f.'.keyword' => [
                        'value' => $query,
                        'boost' => $keywordBoost,
                    ],
                ],
            ];
        }

        // exact text clause
        $should[] = [
            'multi_match' => [
                'query' => $query,
                'type' => \config('elastic.search.default.type', 'best_fields'),
                'fields' => $fields,
                'operator' => $operator,
                'boost' => $exactBoost,
                'lenient' => true,
            ],
        ];

        // bool_prefix clause for incremental typing prefix matches
        $should[] = [
            'multi_match' => [
                'query' => $query,
                'type' => 'bool_prefix',
                'fields' => $fields,
                'operator' => $operator,
                'boost' => 8.0,
                'lenient' => true,
            ],
        ];

        // autocomplete clause
        foreach ($fields as $f) {
            $should[] = [
                'match' => [
                    $f.'.autocomplete' => [
                        'query' => $query,
                        'boost' => 1.0,
                    ],
                ],
            ];
        }

        $bool = [
            'should' => array_merge([$multiMatch], $should),
            'minimum_should_match' => 1,
        ];
        if (! empty($must)) {
            $bool['must'] = $must;
        }

        // Обертываем в function_score для приоритизации товаров с quantity > 0
        $baseQuery = ['bool' => $bool];

        // Проверяем, есть ли поле quantity в searchable_fields
        $hasQuantityField = in_array('quantity', (array) ($cfg['searchable_fields'] ?? []), true);

        if ($hasQuantityField) {
            return [
                'function_score' => [
                    'query' => $baseQuery,
                    'boost_mode' => 'multiply',
                    'score_mode' => 'sum',
                    'functions' => [
                        [
                            'filter' => [
                                'range' => [
                                    'quantity' => ['gt' => 0],
                                ],
                            ],
                            'weight' => 1000, // Огромный вес для товаров с остатком
                        ],
                    ],
                ],
            ];
        }

        return $baseQuery;
    }

    protected function extractNumericTokens(string $query): array
    {
        $clean = preg_replace('/[^0-9.,]+/u', ' ', $query);
        $parts = preg_split('/\s+/', trim((string) $clean));
        $tokens = [];
        foreach ((array) $parts as $p) {
            $p = trim((string) $p);
            if ($p === '') {
                continue;
            }
            // normalize comma decimals to dot and trim surrounding dots
            $p = trim(str_replace(',', '.', $p), '.');
            // keep only digits (drop decimals to align with stored integers like 20, 47, 14)
            $digitsOnly = preg_replace('/[^0-9]/', '', $p);
            if ($digitsOnly !== '') {
                $tokens[] = $digitsOnly;
            }
        }

        return $tokens;
    }

    protected function expandFields(array $searchable, array $translatableFieldNames = []): array
    {
        $locales = \config('elastic.translatable.locales', []);
        $indexLocalized = (bool) (\config('elastic.translatable.index_localized_fields', true));
        $flat = [];
        $stack = $searchable;

        $walker = function ($fields, $prefix = '') use (&$flat, &$walker, $locales, $indexLocalized, $translatableFieldNames) {
            foreach ($fields as $key => $value) {
                if (is_int($key)) {
                    $field = $prefix ? $prefix.'__'.$value : $value;
                    $baseName = $value; // last segment
                    $shouldLocalize = $indexLocalized && in_array($baseName, $translatableFieldNames, true);
                    if ($shouldLocalize) {
                        foreach ($locales as $locale) {
                            $flat[] = $field.'_'.$locale;
                        }
                    } else {
                        $flat[] = $field;
                    }
                } else {
                    $walker((array) $value, $prefix ? $prefix.'__'.$key : $key);
                }
            }
        };

        $walker($searchable);

        return array_values(array_unique($flat));
    }

    protected function applyBoosts(array $fields, array $boosts): array
    {
        $result = [];
        foreach ($fields as $f) {
            $result[] = $f; // default no boost
        }

        // Note: boosts are applied in queries above using keyword/match clauses
        return $result;
    }

    protected function buildFilters(array $filters): array
    {
        $result = [];
        foreach ($filters as $field => $value) {
            if (is_array($value) && array_intersect(array_keys($value), ['gte', 'lte', 'gt', 'lt'])) {
                $result[] = ['range' => [$field => $value]];
            } elseif (is_array($value)) {
                $termField = $this->guessKeywordField($field);
                $result[] = ['terms' => [$termField => array_values($value)]];
            } else {
                $termField = $this->guessKeywordField($field);
                $result[] = ['term' => [$termField => $value]];
            }
        }

        return $result;
    }

    protected function guessKeywordField(string $field): string
    {
        if (str_ends_with($field, '.keyword')) {
            return $field;
        }
        // Для вложенных полей вида relation__id/slug/code – используем keyword сабфилд
        $last = $field;
        if (($pos = strrpos($field, '__')) !== false) {
            $last = substr($field, $pos + 2);
        }
        $lastLower = strtolower($last);
        // Для id (числовые поля) НЕ добавляем keyword
        if ($lastLower === 'id') {
            return $field;
        }
        if (in_array($lastLower, ['slug', 'code'], true)) {
            return $field.'.keyword';
        }

        return $field;
    }

    protected function buildSort(array $sort): array
    {
        $result = [];
        foreach ($sort as $field => $direction) {
            if (is_int($field)) {
                $result[] = $direction; // allow raw sort clauses
            } else {
                $result[] = [$field => ['order' => strtolower($direction) === 'desc' ? 'desc' : 'asc']];
            }
        }

        return $result;
    }

    protected function formatResults(array $response, string $modelClass, array $cfg, array $options): array
    {
        $hits = $response['hits']['hits'] ?? [];
        $ids = array_map(fn ($h) => $h['_id'], $hits);

        $returnFields = $cfg['return_fields'] ?? ['*'];
        /** @var Model $model */
        $model = new $modelClass;
        $query = $model->newQuery()->whereKey($ids);

        // Build optimized select for root model (only explicit scalar fields + primary key)
        $rootScalarFields = [];
        foreach ($returnFields as $k => $v) {
            if (is_int($k)) {
                $rootScalarFields[] = $v;
            }
        }
        $rootScalarFields[] = $model->getKeyName();
        $rootScalarFields = array_values(array_unique(array_filter($rootScalarFields)));
        if (! in_array('*', $rootScalarFields, true) && count($rootScalarFields) > 0) {
            $query->select($rootScalarFields);
        }

        // eager load relations based on return_fields
        foreach ($returnFields as $key => $value) {
            if (! is_int($key)) {
                $fields = (array) $value;
                if (! empty($fields) && ! in_array('*', $fields, true)) {
                    $query->with([$key => function ($q) use ($fields) {
                        // always include id for relation
                        $select = array_values(array_unique(array_merge(['id'], $fields)));
                        $q->select($select);
                    }]);
                } else {
                    $query->with([$key]);
                }
            }
        }

        $modelsById = $query->get()->keyBy(fn ($m) => (string) $m->getKey());
        $results = [];
        foreach ($hits as $hit) {
            $id = (string) $hit['_id'];
            $item = $modelsById[$id] ?? null;
            $results[] = [
                'id' => $id,
                'score' => $hit['_score'] ?? null,
                'highlight' => $hit['highlight'] ?? null,
                'model' => $item,
            ];
        }

        return [
            'total' => $response['hits']['total']['value'] ?? count($results),
            'items' => $results,
            'took_ms' => $response['took'] ?? null,
        ];
    }
}
