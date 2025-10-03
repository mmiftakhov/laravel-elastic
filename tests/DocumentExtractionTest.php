<?php

namespace Maratmiftahov\LaravelElastic\Tests;

use Illuminate\Database\Eloquent\Model;
use Maratmiftahov\LaravelElastic\Console\IndexCommand;

class DummyCategory extends Model
{
    protected $guarded = [];
}

class DummyProduct extends Model
{
    protected $guarded = [];
    public function category()
    {
        return $this->belongsTo(DummyCategory::class);
    }
}

class DocumentExtractionTest extends TestCase
{
    public function test_extracts_translatable_and_relations()
    {
        $cmd = new IndexCommand();
        $product = new DummyProduct([
            'title' => ['en' => 'Bike', 'lv' => 'Velosipēds'],
            'sku' => 'A-27-650-130',
        ]);
        $product->setRelation('category', new DummyCategory(['title' => ['en' => 'Parts', 'lv' => 'Daļas']]));

        $cfg = [
            'searchable_fields' => [
                'title',
                'sku',
                'category' => ['title'],
            ],
        ];

        $ref = new \ReflectionClass($cmd);
        $m = $ref->getMethod('buildDocument');
        $m->setAccessible(true);
        $doc = $m->invoke($cmd, $product, $cfg);

        // Ensure localized fields are present
        \PHPUnit\Framework\Assert::assertArrayHasKey('title_en', $doc);
        \PHPUnit\Framework\Assert::assertArrayHasKey('title_lv', $doc);

        // Ensure relation field merged
        \PHPUnit\Framework\Assert::assertArrayHasKey('category__title_en', $doc);
        \PHPUnit\Framework\Assert::assertSame('Parts', $doc['category__title_en']);
    }
}


