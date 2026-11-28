<?php

declare(strict_types=1);

namespace ImageConverter\Support;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Writes a file so that its path only ever holds the complete content: the data goes into a temporary file in the same
 * directory, which is then moved into place. Nothing is left behind when writing fails.
 *
 * @internal
 */
final class AtomicFile
{
    /**
     * @param  Closure(resource): int  $writer  Fills the file and returns the number of bytes written.
     * @param  bool  $overwrite  False never replaces an existing file, not even one created while writing.
     *
     * @throws InvalidArgumentException when the path is unusable or taken; the writer was not called
     * @throws RuntimeException when the file cannot be created or moved into place
     */
    public static function write(string $path, bool $overwrite, Closure $writer): int
    {
        // `scheme://` would make PHP use a stream wrapper (`phar://`, `ftp://`, ...) instead of the file system.
        if ($path === '' || str_contains($path, "\0") || preg_match('~\A[A-Za-z0-9+.-]{2,}://~', $path) === 1) {
            throw new InvalidArgumentException('The path must be a plain local file path');
        }

        $name = basename($path);

        if (str_ends_with($path, '/') || str_ends_with($path, DIRECTORY_SEPARATOR) || $name === '.' || $name === '..') {
            throw new InvalidArgumentException('The path must name a file, not a directory');
        }

        $directory = dirname($path);

        if (! is_dir($directory)) {
            throw new InvalidArgumentException("The directory {$directory} does not exist");
        }

        if (is_dir($path)) {
            throw new InvalidArgumentException("{$path} is a directory");
        }

        if (! $overwrite && self::exists($path)) {
            throw new InvalidArgumentException("{$path} already exists, pass overwrite: true to replace it");
        }

        $temporary = $directory.DIRECTORY_SEPARATOR.'.download-'.bin2hex(random_bytes(8)).'.part';
        $handle = @fopen($temporary, 'xb');

        if ($handle === false) {
            throw new RuntimeException("Cannot create a file in {$directory}");
        }

        try {
            $bytes = $writer($handle);

            if (! fflush($handle)) {
                throw new RuntimeException('Could not write the downloaded data, the disk may be full');
            }

            fclose($handle);
            self::publish($temporary, $path, $overwrite);

            return $bytes;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private static function exists(string $path): bool
    {
        // A dangling symlink counts: writing to it would create a file somewhere else.
        return file_exists($path) || is_link($path);
    }

    private static function publish(string $temporary, string $path, bool $overwrite): void
    {
        if ($overwrite) {
            if (! @rename($temporary, $path)) {
                throw new RuntimeException("Cannot move the downloaded file to {$path}");
            }

            return;
        }

        // Unlike rename(), link() fails when the target exists, so a file created meanwhile is never replaced.
        if (function_exists('link') && self::quietly(static fn (): bool => link($temporary, $path))) {
            return;
        }

        clearstatcache(true, $path);

        // File systems without hard links: claim the name exclusively, then move the content over the claim.
        $claim = self::exists($path) ? false : @fopen($path, 'xb');

        if ($claim === false) {
            throw new RuntimeException("Cannot create {$path}, it may have been created meanwhile");
        }

        fclose($claim);

        if (! @rename($temporary, $path)) {
            @unlink($path);

            throw new RuntimeException("Cannot move the downloaded file to {$path}");
        }
    }

    /**
     * @param  Closure(): bool  $operation  Its warning is expected and not reported, not even to error handlers.
     */
    private static function quietly(Closure $operation): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
