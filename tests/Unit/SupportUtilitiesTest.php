<?php

use ParsDesign\Grow4MeDataConnector\Exceptions\InvalidResourcePayload;
use ParsDesign\Grow4MeDataConnector\Services\PayloadValidator;
use ParsDesign\Grow4MeDataConnector\Support\CsvCellSanitizer;
use ParsDesign\Grow4MeDataConnector\Support\MediaUrlNormalizer;
use ParsDesign\Grow4MeDataConnector\Support\TextNormalizer;

it('normalizes public text without executable markup', function (): void {
    expect(app(TextNormalizer::class)->normalize(
        '<p>  Product&nbsp; description </p><script>alert(1)</script>',
    ))->toBe('Product description');
});

it('accepts only public https media outside the configured local host exception', function (): void {
    config()->set('app.url', 'http://leasingim.test');
    $normalizer = app(MediaUrlNormalizer::class);

    expect($normalizer->normalize('https://cdn.example.com/product.jpg'))->toBe('https://cdn.example.com/product.jpg')
        ->and($normalizer->normalize('http://leasingim.test/product.jpg'))->toBe('https://leasingim.test/product.jpg')
        ->and($normalizer->normalize('https://cdn.example.com/products/image[1].jpg'))->toBe('https://cdn.example.com/products/image%5B1%5D.jpg')
        ->and($normalizer->normalize('https://cdn.example.com/products/image%5B1%5D.jpg'))->toBe('https://cdn.example.com/products/image%5B1%5D.jpg')
        ->and($normalizer->normalize('https://cdn.example.com/products/sample image.jpg'))->toBeNull()
        ->and($normalizer->normalize('https://user:secret@cdn.example.com/product.jpg'))->toBeNull()
        ->and($normalizer->normalize('https://cdn.example.com/product.jpg#fragment'))->toBeNull()
        ->and($normalizer->normalize('http://untrusted.example/product.jpg'))->toBeNull()
        ->and($normalizer->normalize('javascript:alert(1)'))->toBeNull();
});

it('protects exported spreadsheet cells from formula execution', function (): void {
    expect(app(CsvCellSanitizer::class)->sanitizeRow([
        '=SUM(1,2)',
        "\t+CMD",
        'Safe value',
        42,
    ]))->toBe([
        "'=SUM(1,2)",
        "'\t+CMD",
        'Safe value',
        42,
    ]);
});

it('validates built-in product and dynamic entry payload contracts', function (): void {
    $validator = app(PayloadValidator::class);

    $validator->validate('products', 'catalog.product.v1', [
        'name' => 'Valid product',
        'images' => ['https://cdn.example.com/product.jpg'],
        'detail_url' => 'https://example.com/products/1',
        'attributes' => ['color' => 'Blue'],
    ]);
    $validator->validate('suppliers', 'dynamic.entry.v1', [
        'title' => 'Supplier',
        'slug' => 'supplier',
        'status' => 'published',
        'fields' => ['city' => 'Istanbul'],
    ]);

    expect(fn (): mixed => $validator->validate('products', 'catalog.product.v1', [
        'name' => '',
        'images' => ['http://example.com/product.jpg'],
    ]))->toThrow(InvalidResourcePayload::class, 'images must contain only HTTPS URLs');
});
