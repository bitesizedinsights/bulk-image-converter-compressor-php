<?php

declare(strict_types=1);

use ImageConverter\DTO\ConvertedImage;

function imageWithKey(?string $key): ConvertedImage
{
    return ConvertedImage::fromArray(['outputKey' => $key] + loadFixture('image_success'));
}

test('filename and extension come from the output key', function (string $key, string $extension): void {
    expect(imageWithKey($key)->getFilename())->toBe($key)
        ->and(imageWithKey($key)->getExtension())->toBe($extension);
})->with([
    ['00001-python-logo.webp', 'webp'],
    ['00002-photo.jpg', 'jpg'],
    ['01000-a.b_c-D.avif', 'avif'],
    ['00003-image.PNG', 'png'],
    ['00004-a..b.tiff', 'tiff'],
    ['00005-'.str_repeat('a', 80).'.bmp', 'bmp'],
]);

test('missing or unexpected keys give null', function (?string $key): void {
    expect(imageWithKey($key)->getFilename())->toBeNull()
        ->and(imageWithKey($key)->getExtension())->toBeNull();
})->with([
    'no key' => null,
    'empty' => '',
    'traversal' => '../../etc/passwd',
    'traversal after the index' => '00001-../../x.png',
    'backslash' => '00001-..\\x.png',
    'absolute' => '/00001-a.png',
    'directory' => '00001-a/b.png',
    'dot file' => '.htaccess',
    'dots only' => '..',
    'null byte' => "00001-a.php\0.png",
    'trailing newline' => "00001-a.png\n",
    'script extension' => '00001-shell.php',
    'double extension ending in a script' => '00001-a.png.phtml',
    'svg' => '00001-a.svg',
    'no extension' => '00001-a',
    'no index' => 'cat.png',
    'space' => '00001-a b.png',
    'percent encoding' => '00001-%2e%2e%2fa.png',
    'stream wrapper' => 'phar://00001-a.png',
    'windows stream' => '00001-a.png:x.png',
    'too long' => '00001-'.str_repeat('a', 300).'.png',
]);
