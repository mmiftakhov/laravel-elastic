<?php

namespace Maratmiftahov\LaravelElastic\Tests;

use Maratmiftahov\LaravelElastic\Console\IndexCommand;

class IndexCommandMappingTest extends TestCase
{
    public function test_create_auto_mapping_builds_text_and_code_fields()
    {
        $cmd = new IndexCommand();

        $cfg = [
            'searchable_fields' => [
                'title',
                'sku',
                'category' => ['title'],
            ],
        ];

        $ref = new \ReflectionClass($cmd);
        $m = $ref->getMethod('createAutoMapping');
        $m->setAccessible(true);
        $mapping = $m->invoke($cmd, $cfg);

        \PHPUnit\Framework\Assert::assertArrayHasKey('properties', $mapping);
        $props = $mapping['properties'];

        $hasTitle = array_key_exists('title', $props) || array_key_exists('title_en', $props);
        \PHPUnit\Framework\Assert::assertTrue($hasTitle);

        // sku is treated as code/keyword with subfields
        \PHPUnit\Framework\Assert::assertArrayHasKey('sku', $props);
        \PHPUnit\Framework\Assert::assertSame('keyword', $props['sku']['type']);
        \PHPUnit\Framework\Assert::assertArrayHasKey('text', $props['sku']['fields']);
        \PHPUnit\Framework\Assert::assertArrayHasKey('autocomplete', $props['sku']['fields']);
    }
}


