<?php

declare(strict_types=1);

namespace ImageConverter\Support;

use InvalidArgumentException;

/**
 * The rules for a local file that goes into a key-value store as an upload for the Actor.
 *
 * @internal
 */
final class UploadFile
{
    /** The largest source file the Actor and its backend accept. */
    public const MAX_BYTES = 25 * 1024 * 1024;

    /** Apify's limit for record keys: up to 256 of `a-zA-Z0-9!-_.'()`. The Actor's limit is 256 too. */
    private const MAX_KEY_LENGTH = 256;

    private const KEY_CHARS = "A-Za-z0-9!_.'()\-";

    private const CONTENT_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'gif' => 'image/gif',
        'tif' => 'image/tiff',
        'tiff' => 'image/tiff',
        'bmp' => 'image/bmp',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
    ];

    public static function content(string $path): string
    {
        // `scheme://` would make PHP use a stream wrapper (`phar://`, `https://`, ...) instead of the file system.
        if (str_contains($path, "\0") || preg_match('~\A[A-Za-z0-9+.-]{2,}://~', $path) === 1) {
            throw new InvalidArgumentException('The path must be a plain local file path');
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("{$path} is not a readable file");
        }

        // One byte more than allowed, so the size is judged on what was read, not on an earlier look at the file.
        $content = file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);

        if ($content === false) {
            throw new InvalidArgumentException("{$path} cannot be read");
        }

        if ($content === '') {
            throw new InvalidArgumentException("{$path} is empty");
        }

        if (strlen($content) > self::MAX_BYTES) {
            throw new InvalidArgumentException("{$path} is larger than 25 MB, the Actor's limit per image");
        }

        return $content;
    }

    /** The given key after validation, or one made from the file name. */
    public static function key(string $path, ?string $key): string
    {
        if ($key === null) {
            $key = (string) preg_replace('/[^'.self::KEY_CHARS.']+/', '-', basename($path));

            if (strlen($key) > self::MAX_KEY_LENGTH) {
                $extension = pathinfo($key, PATHINFO_EXTENSION);
                $suffix = $extension === '' || strlen($extension) > 10 ? '' : ".{$extension}";
                $key = substr($key, 0, self::MAX_KEY_LENGTH - strlen($suffix)).$suffix;
            }
        }

        // `.` and `..` would change the request path.
        $pattern = '/\A(?!\.{1,2}\z)['.self::KEY_CHARS.']{1,'.self::MAX_KEY_LENGTH.'}\z/';

        if (preg_match($pattern, $key) !== 1) {
            throw new InvalidArgumentException("A record key must be 1 to 256 characters of a-zA-Z0-9!-_.'()");
        }

        return $key;
    }

    /**
     * The Actor needs the record back as bytes. Apify's client hands it JSON, XML and `text/*` records as data or
     * text, so only image types (no `+xml`) and `application/octet-stream` are allowed.
     */
    public static function contentType(string $path, ?string $contentType): string
    {
        $contentType ??= self::CONTENT_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))]
            ?? 'application/octet-stream';

        if (preg_match('~\A(?:image/[A-Za-z0-9.-]+|application/octet-stream)\z~', $contentType) !== 1) {
            throw new InvalidArgumentException(
                'contentType must be application/octet-stream or an image type without +xml'
            );
        }

        return $contentType;
    }
}
