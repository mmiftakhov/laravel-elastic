<?php

namespace Maratmiftahov\LaravelElastic\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Elastic\Elasticsearch\Client;

class IndexCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'elastic:index {--model=} {--force} {--chunk=}';

    /**
     * The console command description.
     */
    protected $description = 'Index models into Elasticsearch

Options:
  --model=MODEL    Index only a specific model (e.g., "App\\Models\\Product")
  --force          Force reindexing (delete existing index)
  --chunk=SIZE     Set chunk size for bulk indexing (default: from config)';

    /**
     * The Elasticsearch client instance.
     */
    protected Client $elasticsearch;

    /**
     * Execute the console command.
     */
    public function handle(Client $elasticsearch): int
    {
        $this->elasticsearch = $elasticsearch;

        $this->info('Starting Elasticsearch indexing...');

        // Get models to index
        $models = $this->getModelsToIndex();
        
        if (empty($models)) {
            $this->error('No models configured for indexing.');
            return 1;
        }

        $bar = $this->output->createProgressBar(count($models));
        $bar->start();

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
     * Get models to index based on configuration and options.
     */
    protected function getModelsToIndex(): array
    {
        $models = Config::get('elastic.models', []);
        
        if ($specificModel = $this->option('model')) {
            if (!isset($models[$specificModel])) {
                throw new \InvalidArgumentException("Model {$specificModel} is not configured for indexing.");
            }
            return [$specificModel => $models[$specificModel]];
        }

        return $models;
    }

    /**
     * Index a specific model.
     */
    protected function indexModel(string $modelClass, array $config): void
    {
        $this->line("\nIndexing {$modelClass}...");

        // Get index name
        $indexName = $this->getIndexName($config);
        
        // Check if index exists
        if ($this->indexExists($indexName) && !$this->option('force')) {
            $this->warn("Index {$indexName} already exists. Use --force to reindex.");
            return;
        }

        // Create or recreate index
        $this->createIndex($indexName, $config);

        // Get model instance
        $model = new $modelClass();
        
        // Apply query conditions from configuration
        $query = $this->applyQueryConditions($model, $config);
        
        // Get total count
        $total = $query->count();
        $this->line("Found {$total} records to index.");

        if ($total === 0) {
            $this->warn("No records found for {$modelClass}.");
            return;
        }

        // Index data in chunks
        $configChunkSize = $config['chunk_size'] ?? 1000;
        $optionChunkSize = $this->option('chunk');
        
        if ($optionChunkSize) {
            $chunkSize = (int) $optionChunkSize;
        } else {
            $chunkSize = $configChunkSize;
        }
        
        $this->line("Using chunk size: {$chunkSize}");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunk($chunkSize, function ($records) use ($indexName, $config, $bar) {
            $this->indexChunk($records, $indexName, $config);
            $bar->advance($records->count());
        });

        $bar->finish();
        $this->newLine();
        
        $this->info("Successfully indexed {$total} records for {$modelClass}.");
    }

    /**
     * Get the index name for a model.
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
     * Check if an index exists.
     */
    protected function indexExists(string $indexName): bool
    {
        try {
            $response = $this->elasticsearch->indices()->exists(['index' => $indexName]);
            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Create an index with proper mapping.
     */
    protected function createIndex(string $indexName, array $config): void
    {
        $this->line("Creating index: {$indexName}");

        // Delete existing index if force option is used
        if ($this->option('force') && $this->indexExists($indexName)) {
            $this->elasticsearch->indices()->delete(['index' => $indexName]);
            $this->line("Deleted existing index: {$indexName}");
        }

        // Prepare index settings
        $settings = $this->getIndexSettings($config);
        
        // Prepare mapping
        $mapping = $this->getIndexMapping($config);

        // Create index
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
     * Get index settings from configuration.
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
     * Get index mapping from configuration.
     */
    protected function getIndexMapping(array $config): array
    {
        $properties = [];

        // Add searchable fields
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
        }

        // Add computed fields
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
     * Index a chunk of records.
     */
    protected function indexChunk($records, string $indexName, array $config): void
    {
        $body = [];

        foreach ($records as $record) {
            // Prepare document data
            $document = $this->prepareDocument($record, $config);
            
            $body[] = [
                'index' => [
                    '_index' => $indexName,
                    '_id' => $record->getKey(),
                ],
            ];
            
            $body[] = $document;
        }

        if (!empty($body)) {
            $this->elasticsearch->bulk(['body' => $body]);
        }
    }

    /**
     * Prepare document data for indexing.
     */
    protected function prepareDocument($record, array $config): array
    {
        $document = [];

        // Add stored fields
        foreach ($config['stored_fields'] ?? [] as $field) {
            if (array_key_exists($field, $record->getAttributes())) {
                $document[$field] = $record->getAttribute($field);
            }
        }

        // Add searchable fields
        foreach ($config['searchable_fields'] ?? [] as $field => $fieldConfig) {
            if (array_key_exists($field, $record->getAttributes())) {
                $document[$field] = $record->getAttribute($field);
            }
        }

        // Add computed fields
        foreach ($config['computed_fields'] ?? [] as $field => $fieldConfig) {
            $document[$field] = $this->computeField($record, $fieldConfig);
        }

        return $document;
    }

    /**
     * Compute a field value based on configuration.
     */
    protected function computeField($record, array $fieldConfig): mixed
    {
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

        if (isset($fieldConfig['transform'])) {
            return $this->transformField($record, $fieldConfig['transform']);
        }

        return null;
    }

    /**
     * Transform a field value.
     */
    protected function transformField($record, string $transform): mixed
    {
        // Add custom transformers here
        switch ($transform) {
            case 'price_range':
                $price = $record->getAttribute('price');
                if ($price <= 1000) return 'low';
                if ($price <= 10000) return 'medium';
                return 'high';
            
            default:
                return null;
        }
    }

    /**
     * Apply query conditions from configuration.
     */
    protected function applyQueryConditions($model, array $config)
    {
        $query = $model->newQuery();

        $conditions = $config['query_conditions'] ?? [];

        // Apply basic WHERE conditions
        foreach ($conditions['where'] ?? [] as $field => $value) {
            if ($value === 'not_null') {
                $query->whereNotNull($field);
            } elseif ($value === 'null') {
                $query->whereNull($field);
            } else {
                $query->where($field, $value);
            }
        }

        // Apply WHERE IN conditions
        foreach ($conditions['where_in'] ?? [] as $field => $values) {
            $query->whereIn($field, $values);
        }

        // Apply WHERE BETWEEN conditions
        foreach ($conditions['where_between'] ?? [] as $field => $values) {
            if (is_array($values) && count($values) === 2) {
                $query->whereBetween($field, $values);
            }
        }

        // Apply WHERE HAS conditions (for relationships)
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

        // Apply WHERE DOESN'T HAVE conditions
        foreach ($conditions['where_doesnt_have'] ?? [] as $relation) {
            $query->whereDoesntHave($relation);
        }

        // Apply custom callback conditions
        if (isset($conditions['where_callback']) && is_callable($conditions['where_callback'])) {
            $conditions['where_callback']($query);
        }

        return $query;
    }
} 