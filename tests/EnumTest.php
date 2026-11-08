<?php

declare(strict_types=1);

use ImageConverter\ConversionStatus;
use ImageConverter\OutputFormat;
use ImageConverter\SourceType;

test('enum values', function (string $enum, array $values) {
    expect(array_map(static fn ($case) => $case->value, $enum::cases()))->toBe($values);
})->with([
    'output format' => [OutputFormat::class, ['webp', 'jpeg', 'png', 'avif', 'original', 'gif', 'tiff', 'bmp']],
    'status' => [ConversionStatus::class, ['success', 'failed']],
    'source type' => [SourceType::class, ['url', 'upload']],
]);

test('unknown enum values give null', function (string $enum) {
    expect($enum::tryFrom('nope'))->toBeNull();
})->with([OutputFormat::class, ConversionStatus::class, SourceType::class]);
