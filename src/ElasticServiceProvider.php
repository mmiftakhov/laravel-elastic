<?php

namespace Maratmiftahov\LaravelElastic;

use Illuminate\Support\ServiceProvider;
use Elastic\Elasticsearch\ClientBuilder;

/**
 * Сервис-провайдер для пакета Laravel Elastic
 * 
 * Регистрирует все необходимые сервисы и команды для работы с Elasticsearch:
 * - Клиент Elasticsearch как синглтон
 * - Сервис поиска ElasticSearch как синглтон
 * - Artisan команды для индексации и поиска
 * - Публикация конфигурационного файла
 * 
 * Поддерживает автоматическое обнаружение пакета Laravel
 * и интеграцию с системой конфигурации Laravel.
 */
class ElasticServiceProvider extends ServiceProvider
{
    /**
     * Регистрирует сервисы в контейнере зависимостей
     * 
     * Регистрирует:
     * - Клиент Elasticsearch как синглтон
     * - Сервис поиска ElasticSearch как синглтон
     * - Объединяет конфигурацию пакета с конфигурацией приложения
     */
    public function register(): void
    {
        // Объединяем конфигурацию пакета с конфигурацией приложения
        // Это позволяет переопределить настройки в config/elastic.php
        $this->mergeConfigFrom(
            __DIR__ . '/../config/elastic.php',
            'elastic'
        );

        // Регистрируем клиент Elasticsearch как синглтон
        // Используется для всех операций с Elasticsearch API
        $this->app->singleton(\Elastic\Elasticsearch\Client::class, function ($app) {
            $config = $app['config']->get('elastic');
            
            // Создаем клиент с настройками из конфигурации
            $clientBuilder = ClientBuilder::create();
            
            // Настраиваем хосты для подключения
            if (isset($config['hosts'])) {
                $clientBuilder->setHosts($config['hosts']);
            }
            
            // Настраиваем параметры соединения
            if (isset($config['connection'])) {
                $connection = $config['connection'];
                
                // Настраиваем повторные попытки
                if (isset($connection['retries'])) {
                    $clientBuilder->setRetries($connection['retries']);
                }
            }
            
            return $clientBuilder->build();
        });

        // Регистрируем сервис поиска ElasticSearch как синглтон
        // Предоставляет удобный API для поиска в индексах
        $this->app->singleton(\Maratmiftahov\LaravelElastic\ElasticSearch::class, function ($app) {
            return new \Maratmiftahov\LaravelElastic\ElasticSearch(
                $app->make(\Elastic\Elasticsearch\Client::class)
            );
        });
    }

    /**
     * Загружает сервисы после регистрации
     * 
     * Выполняет:
     * - Публикацию конфигурационного файла
     * - Регистрацию Artisan команд (только в консольном режиме)
     */
    public function boot(): void
    {
        // Публикуем конфигурационный файл в директорию config приложения
        // Позволяет пользователям кастомизировать настройки
        $this->publishes([
            __DIR__ . '/../config/elastic.php' => config_path('elastic.php'),
        ], 'config');

        // Регистрируем Artisan команды только в консольном режиме
        // Это оптимизирует производительность веб-приложений
        if ($this->app->runningInConsole()) {
            $this->commands([
                // Команда для индексации моделей в Elasticsearch
                \Maratmiftahov\LaravelElastic\Console\IndexCommand::class,
                // Команда для тестирования поиска в Elasticsearch
                \Maratmiftahov\LaravelElastic\Console\SearchCommand::class,
            ]);
        }
    }
} 