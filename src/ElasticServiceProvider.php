<?php

namespace Maratmiftahov\LaravelElastic;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Illuminate\Support\ServiceProvider;
use Maratmiftahov\LaravelElastic\Console\IndexCommand;

class ElasticServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/elastic.php', 'elastic');

        $this->app->singleton(Client::class, function () {
            $hosts = config('elastic.hosts', ['http://localhost:9200']);
            return ClientBuilder::create()
                ->setHosts($hosts)
                ->build();
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/elastic.php' => $this->app->configPath('elastic.php'),
        ], 'elastic-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                IndexCommand::class,
            ]);
        }
    }
}


