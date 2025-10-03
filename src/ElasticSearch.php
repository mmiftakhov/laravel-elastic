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
    ) {
    }

    public function search(string $modelClass, string $query, array $options = []): array
    {
        $modelsConfig = \config('elastic.models');
        $cfg = $modelsConfig[$modelClass] ?? null;
        if (! $cfg) {
            throw new \InvalidArgumentException("Model {$modelClass} is not configured in elastic.models");
        }

        $indexBase = $cfg['index'] ?? Str::slug(\class_basename($modelClass));
        $indexPrefix = \config('elastic.index_settings.prefix', '');
        $index = $indexPrefix ? $indexPrefix . '_' . $indexBase : $indexBase;

        $params = $this->buildSearchParams([$index], $cfg, $query, $options);

        $cacheTtl = \config('elastic.cache.ttl');
        $useCache = (bool) (\config('elastic.cache.enabled', true) && ! empty($options['cache']));
        $cacheKey = null;
        if ($useCache && $this->cache) {
            $cacheKey = 'elastic:' . md5(json_encode($params));
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
        $modelsConfig = \config('elastic.models');
        $indices = [];
        $perModelCfg = [];
        foreach ($modelClasses as $class) {
            $cfg = $modelsConfig[$class] ?? null;
            if (! $cfg) {
                continue;
            }
            $indexBase = $cfg['index'] ?? Str::slug(\class_basename($class));
            $indexPrefix = \config('elastic.index_settings.prefix', '');
            $indices[] = $indexPrefix ? $indexPrefix . '_' . $indexBase : $indexBase;
            $perModelCfg[$class] = $cfg;
        }

        $params = $this->buildSearchParams($indices, null, $query, $options);

        $response = $this->client->search($params)->asArray();
        // Without per-doc type we just return raw hits with index info
        return $response;
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
                'fields' => array_fill_keys(\config('elastic.search.highlight.fields', ['*']), new \stdClass()),
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

        return [
            'index' => implode(',', $indices),
            'body' => $searchBody,
        ];
    }

    protected function buildQuery(?array $cfg, string $query, array $options): array
    {
        $fuzzyCfg = \config('elastic.search.fuzzy');
        $fuzzyEnabled = (bool) ($options['fuzzy'] ?? $fuzzyCfg['enabled'] ?? true);
        $operator = $options['operator'] ?? \config('elastic.search.default.operator', 'and');

        // Build field list including localized variants and subfields
        $fields = $this->expandFields(($cfg['searchable_fields'] ?? []));
        $fieldsWithBoost = $this->applyBoosts($fields, $cfg['searchable_fields_boost'] ?? []);

        $multiMatch = [
            'multi_match' => [
                'query' => $query,
                'type' => \config('elastic.search.default.type', 'multi_match'),
                'operator' => $operator,
                'fields' => $fieldsWithBoost,
            ],
        ];
        if ($fuzzyEnabled) {
            $multiMatch['multi_match']['fuzziness'] = $fuzzyCfg['fuzziness'] ?? 'AUTO';
            $multiMatch['multi_match']['prefix_length'] = $fuzzyCfg['prefix_length'] ?? 2;
        }

        $keywordBoost = (float) (\config('elastic.search.keyword_match.boost', 15.0));
        $exactBoost = (float) (\config('elastic.search.exact_match.boost', 10.0));

        $should = [];
        // exact keyword clause
        foreach ($fields as $f) {
            $should[] = [
                'term' => [
                    $f . '.keyword' => [
                        'value' => $query,
                        'boost' => $keywordBoost,
                    ],
                ],
            ];
        }

        // exact text clause
        $should[] = [
            'match_phrase' => [
                implode(' ', $fields) => [
                    'query' => $query,
                    'boost' => $exactBoost,
                ],
            ],
        ];

        // autocomplete clause
        foreach ($fields as $f) {
            $should[] = [
                'match' => [
                    $f . '.autocomplete' => [
                        'query' => $query,
                        'boost' => 1.0,
                    ],
                ],
            ];
        }

        return [
            'bool' => [
                'must' => [$multiMatch],
                'should' => $should,
            ],
        ];
    }

    protected function expandFields(array $searchable): array
    {
        $locales = \config('elastic.translatable.locales', []);
        $indexLocalized = (bool) (\config('elastic.translatable.index_localized_fields', true));
        $flat = [];
        $stack = $searchable;

        $walker = function ($fields, $prefix = '') use (&$flat, &$walker, $locales, $indexLocalized) {
            foreach ($fields as $key => $value) {
                if (is_int($key)) {
                    $field = $prefix ? $prefix . '__' . $value : $value;
                    if ($indexLocalized) {
                        foreach ($locales as $locale) {
                            $flat[] = $field . '_' . $locale;
                        }
                    } else {
                        $flat[] = $field;
                    }
                } else {
                    $walker((array) $value, $prefix ? $prefix . '__' . $key : $key);
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
                $result[] = ['terms' => [$field => array_values($value)]];
            } else {
                $result[] = ['term' => [$field => $value]];
            }
        }
        return $result;
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
        $model = new $modelClass();
        $query = $model->newQuery()->whereKey($ids);

        // eager load relations based on return_fields
        foreach ($returnFields as $key => $value) {
            if (! is_int($key)) {
                $query->with([$key]);
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


