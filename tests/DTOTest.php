<?php

declare(strict_types=1);

use ImageConverter\ConversionStatus;
use ImageConverter\DTO\ConvertedImage;
use ImageConverter\SourceType;

test('image from array with all fields', function () {
    $i = ConvertedImage::fromArray(loadFixture('image_success'));

    expect($i->index)->toBe(1)
        ->and($i->source)->toBe('https://www.python.org/static/img/python-logo.png')
        ->and($i->sourceType)->toBe(SourceType::Url)
        ->and($i->status)->toBe(ConversionStatus::Success)
        ->and($i->outputUrl)->toEndWith('00001-python-logo.webp')
        ->and($i->outputKey)->toBe('00001-python-logo.webp')
        ->and($i->sourceFormat)->toBe('png')
        ->and($i->outputFormat)->toBe('webp')
        ->and($i->sourceBytes)->toBe(15770)
        ->and($i->outputBytes)->toBe(6178)
        ->and($i->bytesSaved)->toBe(9592)
        ->and($i->savedPercent)->toBe(60.8)
        ->and($i->sourceWidth)->toBe(601)
        ->and($i->outputHeight)->toBe(203)
        ->and($i->error)->toBeNull();
});

test('image helpers on a successful row', function () {
    $i = ConvertedImage::fromArray(loadFixture('image_success'));

    expect($i->isSuccessful())->toBeTrue()
        ->and($i->isFailed())->toBeFalse()
        ->and($i->isLarger())->toBeFalse()
        ->and($i->wasResized())->toBeFalse();
});

test('failed image row', function () {
    $i = ConvertedImage::fromArray(loadFixture('image_failed'));

    expect($i->isFailed())->toBeTrue()
        ->and($i->isSuccessful())->toBeFalse()
        ->and($i->outputUrl)->toBeNull()
        ->and($i->error)->toBe('The file is not a supported or readable image.')
        ->and($i->isLarger())->toBeFalse()
        ->and($i->wasResized())->toBeFalse();
});

test('image with only required fields', function () {
    $i = ConvertedImage::fromArray(loadFixture('image_minimal'));

    expect($i->sourceType)->toBe(SourceType::Upload)
        ->and($i->isSuccessful())->toBeTrue()
        ->and($i->sourceBytes)->toBeNull()
        ->and($i->savedPercent)->toBeNull()
        ->and($i->wasResized())->toBeFalse();
});

test('resized and larger images are detected', function () {
    $i = ConvertedImage::fromArray([
        ...loadFixture('image_success'),
        'outputWidth' => 300,
        'outputHeight' => 101,
        'bytesSaved' => -120,
        'savedPercent' => -3,
    ]);

    expect($i->wasResized())->toBeTrue()
        ->and($i->isLarger())->toBeTrue()
        ->and($i->savedPercent)->toBe(-3.0);
});

test('unknown status or source type is rejected', function (string $field) {
    expect(fn () => ConvertedImage::fromArray([...loadFixture('image_success'), $field => 'weird']))
        ->toThrow(InvalidArgumentException::class, "Unknown {$field}");
})->with(['status', 'sourceType']);

test('wrong field types are rejected', function () {
    expect(fn () => ConvertedImage::fromArray([...loadFixture('image_success'), 'sourceBytes' => 'big']))
        ->toThrow(InvalidArgumentException::class, 'sourceBytes');
});

test('image round trips through toArray and json', function (string $fixture) {
    $image = ConvertedImage::fromArray(loadFixture($fixture));

    $viaArray = ConvertedImage::fromArray($image->toArray());
    $viaJson = ConvertedImage::fromArray(json_decode(json_encode($image, JSON_THROW_ON_ERROR), true));

    expect($viaArray)->toEqual($image)
        ->and($viaJson)->toEqual($image);
})->with(['image_success', 'image_failed', 'image_minimal']);

test('toArray uses the Actor field names', function () {
    $array = ConvertedImage::fromArray(loadFixture('image_success'))->toArray();

    expect($array)->toHaveKeys(['sourceType', 'outputUrl', 'bytesSaved', 'savedPercent', 'outputHeight'])
        ->and($array['sourceType'])->toBe('url')
        ->and($array['status'])->toBe('success');
});
