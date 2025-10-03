<?php

namespace Maratmiftahov\LaravelElastic\Console;

use Elastic\Elasticsearch\Client;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

class IndexCommand extends Command
{
    protected $signature = 'elastic:index {--model=} {--reindex} {--create-only} {--delete-only} {--chunk=1000}';

    protected $description = 'Create/recreate indices and index Eloquent models into Elasticsearch';

    public function handle(Client $client): int
    {
        $modelClass = $this->option('model');
        $reindex = (bool) $this->option('reindex');
        $createOnly = (bool) $this->option('create-only');
        $deleteOnly = (bool) $this->option('delete-only');
        $chunkSize = (int) $this->option('chunk');

        $modelsConfig = config('elastic.models', []);
        if ($modelClass) {
            $modelsConfig = array_intersect_key($modelsConfig, array_flip([$modelClass]));
            if (empty($modelsConfig)) {
                $this->error("Model {$modelClass} is not configured in elastic.models");
                return 1;
            }
        }

        foreach ($modelsConfig as $class => $cfg) {
            $indexBase = $cfg['index'] ?? \Illuminate\Support\Str::slug(\class_basename($class));
            $indexPrefix = config('elastic.index_settings.prefix', '');
            $indexName = $indexPrefix ? $indexPrefix . '_' . $indexBase : $indexBase;

            if ($deleteOnly || $reindex) {
                $this->deleteIndexIfExists($client, $indexName);
                if ($deleteOnly) {
                    $this->info("Deleted index {$indexName}");
                    continue;
                }
            }

            $this->createIndexIfNotExists($client, $indexName, $cfg);
            if ($createOnly) {
                $this->info("Created index {$indexName}");
                continue;
            }

            if ($reindex) {
                $this->info("Recreated index {$indexName}");
            }

            $this->bulkIndexModel($client, $class, $indexName, $cfg, $chunkSize);
        }

        return 0;
    }

    protected function deleteIndexIfExists(Client $client, string $index): void
    {
        try {
            $exists = $client->indices()->exists(['index' => $index]);
            $existsBool = is_object($exists) && method_exists($exists, 'asBool') ? $exists->asBool() : (bool) $exists;
            if ($existsBool) {
                $client->indices()->delete(['index' => $index]);
            }
        } catch (\Throwable $e) {
            $this->warn("Delete index {$index} failed: {$e->getMessage()}");
        }
    }

    protected function createIndexIfNotExists(Client $client, string $index, array $cfg): void
    {
        try {
            $exists = $client->indices()->exists(['index' => $index]);
            $existsBool = is_object($exists) && method_exists($exists, 'asBool') ? $exists->asBool() : (bool) $exists;
            if (! $existsBool) {
                $body = [
                    'settings' => $this->buildIndexSettings(),
                    'mappings' => $this->createAutoMapping($cfg),
                ];
                $client->indices()->create(['index' => $index, 'body' => $body]);
            }
        } catch (\Throwable $e) {
            $this->error("Create index {$index} failed: {$e->getMessage()}");
        }
    }

    protected function buildIndexSettings(): array
    {
        $indexSettings = config('elastic.index_settings');
        $analysis = config('elastic.analysis');

        return [
            'number_of_shards' => $indexSettings['number_of_shards'] ?? 1,
            'number_of_replicas' => $indexSettings['number_of_replicas'] ?? 0,
            'analysis' => $analysis,
        ];
    }

    protected function createAutoMapping(array $cfg): array
    {
        $properties = [];
        $searchable = $cfg['searchable_fields'] ?? [];

        $locales = config('elastic.translatable.locales', []);
        $indexLocalized = (bool) (config('elastic.translatable.index_localized_fields', true));

        $this->addFieldsToProperties($properties, $searchable, $locales, $indexLocalized);

        // Computed fields
        if (! empty($cfg['computed_fields'])) {
            foreach ($cfg['computed_fields'] as $field => $def) {
                $properties[$field] = [
                    'type' => $def['type'] ?? 'text',
                    'analyzer' => $def['analyzer'] ?? 'full_text_en',
                ];
            }
        }

        return [
            'properties' => $properties,
        ];
    }

    protected function addFieldsToProperties(array & $props, array $fields, array $locales, bool $indexLocalized, string $path = ''): void
    {
        foreach ($fields as $key => $value) {
            if (is_int($key)) {
                $field = $value;
                $full = $path ? $path . '.' . $field : $field;
                $this->addFieldToMapping($props, $full, $locales, $indexLocalized);
            } else {
                $relation = $key;
                $nestedPath = $path ? $path . '.' . $relation : $relation;
                $this->addFieldsToProperties($props, (array) $value, $locales, $indexLocalized, $nestedPath);
            }
        }
    }

    protected function addFieldToMapping(array & $props, string $fullField, array $locales, bool $indexLocalized): void
    {
        $segments = explode('.', $fullField);
        $baseField = end($segments) ?: $fullField;
        $type = $this->detectFieldType($baseField);

        if ($type === 'text') {
            $definition = [
                'type' => 'text',
                'analyzer' => 'full_text_en',
                'fields' => [
                    'keyword' => ['type' => 'keyword', 'ignore_above' => 256],
                    'autocomplete' => ['type' => 'text', 'analyzer' => 'autocomplete', 'search_analyzer' => 'standard'],
                ],
            ];

            if ($indexLocalized) {
                foreach ($locales as $locale) {
                    $props[$this->fieldKey($fullField . '_' . $locale)] = $definition;
                }
            } else {
                $props[$this->fieldKey($fullField)] = $definition;
            }
        } else {
            if ($type === 'keyword') {
                $props[$this->fieldKey($fullField)] = [
                    'type' => 'keyword',
                    'ignore_above' => 256,
                    'fields' => [
                        'text' => [
                            'type' => 'text',
                            'analyzer' => 'code_analyzer',
                        ],
                        'autocomplete' => [
                            'type' => 'text',
                            'analyzer' => 'autocomplete',
                            'search_analyzer' => 'standard',
                        ],
                    ],
                ];
            } else {
                $props[$this->fieldKey($fullField)] = ['type' => $type];
            }
        }
    }

    protected function detectFieldType(string $field): string
    {
        $lower = strtolower($field);
        if ($this->isCodeField($lower)) {
            return 'keyword';
        }
        if (str_ends_with($lower, '_id')) {
            return 'long';
        }
        if (preg_match('/(price|amount|total|sum)$/', $lower)) {
            return 'double';
        }
        if (preg_match('/^(is_|has_)/', $lower)) {
            return 'boolean';
        }
        if (preg_match('/(_at|_date)$/', $lower)) {
            return 'date';
        }
        return 'text';
    }

    protected function isCodeField(string $field): bool
    {
        $codeNames = [
            'code', 'sku', 'slug', 'isbn', 'article_number', 'barcode', 'ean', 'upc', 'model', 'part_number'
        ];
        foreach ($codeNames as $name) {
            if (str_contains($field, $name)) {
                return true;
            }
        }
        return false;
    }

    protected function bulkIndexModel(Client $client, string $modelClass, string $index, array $cfg, int $chunkSize): void
    {
        /** @var Model $model */
        $model = new $modelClass();

        $query = $model->newQuery();
        $relations = $this->collectRelations($cfg['searchable_fields'] ?? []);
        if (! empty($relations)) {
            $query->with(array_values($relations));
        }

        $total = $query->count();
        $this->info("Indexing {$modelClass} ({$total} records) → {$index}");

        $query->chunkById($chunkSize, function ($items) use ($client, $index, $cfg) {
            $body = [];
            foreach ($items as $item) {
                $doc = $this->buildDocument($item, $cfg);
                $body[] = [
                    'index' => [
                        '_index' => $index,
                        '_id' => $item->getKey(),
                    ],
                ];
                $body[] = $doc;
            }
            if (! empty($body)) {
                $client->bulk(['body' => $body]);
            }
            $this->output->write('.');
        });

        $this->newLine();
        $this->info('Done');
    }

    protected function collectRelations(array $fields, string $path = ''): array
    {
        $relations = [];
        foreach ($fields as $key => $value) {
            if (is_int($key)) {
                continue;
            }
            $relation = $path ? $path . '.' . $key : $key;
            $relations[$relation] = $relation;
            $relations = array_merge($relations, $this->collectRelations((array) $value, $relation));
        }
        return $relations;
    }

    protected function buildDocument(Model $model, array $cfg): array
    {
        $doc = [];
        $fields = $cfg['searchable_fields'] ?? [];
        $locales = config('elastic.translatable.locales', []);
        $fallback = config('elastic.translatable.fallback_locale');
        $indexLocalized = (bool) (config('elastic.translatable.index_localized_fields', true));

        $this->extractFields($doc, $model, $fields, $locales, $fallback, $indexLocalized);

        // Computed fields
        if (! empty($cfg['computed_fields'])) {
            foreach ($cfg['computed_fields'] as $field => $def) {
                $sourceFields = (array) ($def['source_fields'] ?? []);
                $doc[$field] = $this->computeField($doc, $sourceFields);
            }
        }

        return $doc;
    }

    protected function extractFields(array & $doc, Model $model, array $fields, array $locales, ?string $fallback, bool $indexLocalized, string $path = ''): void
    {
        foreach ($fields as $key => $value) {
            if (is_int($key)) {
                $field = $value;
                $full = $path ? $path . '.' . $field : $field;
                $val = \data_get($model, $full);

                if (is_array($val) || is_object($val)) {
                    // translatable JSON assumed
                    if ($indexLocalized) {
                        foreach ($locales as $locale) {
                            $this->mergeDocValue($doc, $this->fieldKey($full . '_' . $locale), \data_get($val, $locale, \data_get($val, $fallback)));
                        }
                    } else {
                        $this->mergeDocValue($doc, $this->fieldKey($full), \data_get($val, $fallback));
                    }
                } else {
                    $this->mergeDocValue($doc, $this->fieldKey($full), $val);
                }
            } else {
                $relation = $key;
                $nestedPath = $path ? $path . '.' . $relation : $relation;
                $related = \data_get($model, $relation);
                if ($related instanceof \Illuminate\Database\Eloquent\Collection) {
                    foreach ($related as $relModel) {
                        $nestedDoc = [];
                        $this->extractFields($nestedDoc, $relModel, (array) $value, $locales, $fallback, $indexLocalized, $nestedPath);
                        foreach ($nestedDoc as $k => $v) {
                            $this->mergeDocValue($doc, $k, $v);
                        }
                    }
                } elseif ($related instanceof Model) {
                    $nestedDoc = [];
                    $this->extractFields($nestedDoc, $related, (array) $value, $locales, $fallback, $indexLocalized, $nestedPath);
                    foreach ($nestedDoc as $k => $v) {
                        $this->mergeDocValue($doc, $k, $v);
                    }
                }
            }
        }
    }

    protected function fieldKey(string $path): string
    {
        return str_replace('.', '__', $path);
    }

    protected function computeField(array $doc, array $sourceFields): string
    {
        $values = [];
        foreach ($sourceFields as $sf) {
            if (isset($doc[$this->fieldKey($sf)])) {
                $values[] = (string) $doc[$this->fieldKey($sf)];
            }
        }
        return trim(implode(' ', array_filter($values)));
    }

    protected function mergeDocValue(array & $doc, string $key, $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (! array_key_exists($key, $doc)) {
            $doc[$key] = $value;
            return;
        }
        if (is_array($doc[$key])) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $doc[$key][] = $v;
                }
            } else {
                $doc[$key][] = $value;
            }
            return;
        }
        // scalar existing
        if ($doc[$key] !== $value) {
            $doc[$key] = [$doc[$key], $value];
        }
    }
}


