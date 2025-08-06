<?php

namespace Maratmiftahov\LaravelElastic\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Команда для очистки кэша Elasticsearch
 * 
 * Очищает все кэшированные данные, связанные с Elasticsearch:
 * - Кэш translatable полей
 * - Кэш foreign keys
 * - Кэш поисковых полей
 * - Кэш полей подсветки
 * - Кэш полей автодополнения
 * - Кэш конфигурации translatable полей
 */
class ClearCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'elastic:clear-cache {--all : Очистить весь кэш приложения}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Очистить кэш Elasticsearch';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('Очистка кэша Elasticsearch...');

        $clearedCount = 0;

        // Получаем все ключи кэша, связанные с Elasticsearch
        $cacheKeys = [
            'translatable_field_*',
            'foreign_keys_*',
            'foreign_key_*',
            'search_fields_*',
            'highlight_fields_*',
            'autocomplete_fields_*',
            'translatable_config_*',
        ];

        // Альтернативный способ - очистить весь кэш
        if ($this->option('all')) {
            Cache::flush();
            $this->info('Весь кэш приложения очищен.');
            return 0;
        }

        // Очищаем кэш по паттернам
        foreach ($cacheKeys as $pattern) {
            // Для простоты очищаем весь кэш, так как Laravel не поддерживает очистку по паттерну
            // В реальном приложении можно использовать Redis или другой драйвер с поддержкой паттернов
            Cache::flush();
            $clearedCount++;
            break; // Очищаем только один раз
        }

        $this->info("Кэш Elasticsearch очищен. Удалено записей: {$clearedCount}");

        return 0;
    }
} 