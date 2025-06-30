<?php

namespace Maratmiftahov\LaravelElastic;

use Illuminate\Support\ServiceProvider;
use Elastic\Elasticsearch\ClientBuilder;

class ElasticServiceProvider extends ServiceProvider
{
    /**
     * Register the Elasticsearch client singleton and merge package config.
     */
    public function register(): void
    {
        // Merge the default config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/elastic.php',
            'elastic'
        );

        // Bind the Elasticsearch client as a singleton (по типу!)
        $this->app->singleton(\Elastic\Elasticsearch\Client::class, function ($app) {
            $config = $app['config']->get('elastic');
            return \Elastic\Elasticsearch\ClientBuilder::create()
                ->setHosts($config['hosts'] ?? ['localhost:9200'])
                ->build();
        });
    }

    /**
     * Publish the config file and register commands.
     */
    public function boot(): void
    {
        // Publish config to the application's config directory
        $this->publishes([
            __DIR__ . '/../config/elastic.php' => config_path('elastic.php'),
        ], 'config');

        // Register Artisan commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Maratmiftahov\LaravelElastic\Console\IndexCommand::class,
            ]);
        }
    }
} 