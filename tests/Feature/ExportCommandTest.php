<?php

use Illuminate\Support\Facades\File;
use ParsDesign\Grow4MeDataConnector\Resources\ResourceRegistry;
use ParsDesign\Grow4MeDataConnector\Tests\Fixtures\NonExportableProductResourceAdapter;
use ParsDesign\Grow4MeDataConnector\Tests\Fixtures\Product;

use function Pest\Laravel\artisan;

it('streams adapter-defined columns to safe csv and xml files', function (): void {
    Product::query()->create([
        'name' => '=HYPERLINK("https://bad.example")',
        'description' => 'A & B',
        'active' => true,
    ]);
    $directory = sys_get_temp_dir().'/grow4me-export-'.fake()->uuid();
    $csv = $directory.'/products.csv';
    $xml = $directory.'/products.xml';

    try {
        artisan('grow4me:export', [
            'resource' => 'products',
            'path' => $csv,
            '--format' => 'csv',
        ])->assertSuccessful();
        artisan('grow4me:export', [
            'resource' => 'products',
            'path' => $xml,
            '--format' => 'xml',
        ])->assertSuccessful();

        expect(File::get($csv))
            ->toContain('external_id,active,source_updated_at,Name,Description')
            ->toContain("'=HYPERLINK")
            ->and(File::get($xml))
            ->toContain('<products>')
            ->toContain('<name>=HYPERLINK(&quot;https://bad.example&quot;)</name>')
            ->toContain('<description>A &amp; B</description>');
    } finally {
        File::deleteDirectory($directory);
    }
});

it('rejects export when the adapter has no explicit export columns', function (): void {
    config()->set('grow4me-connector.resources', [NonExportableProductResourceAdapter::class]);
    app()->forgetInstance(ResourceRegistry::class);

    artisan('grow4me:export', [
        'resource' => 'non-exportable-products',
        'path' => sys_get_temp_dir().'/not-created.csv',
        '--format' => 'csv',
    ])->expectsOutputToContain('does not define export columns')
        ->assertFailed();
});
