<?php

declare(strict_types=1);

namespace ImageConverter\Support;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Exception\MalformedUriException;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use ImageConverter\Config;
use ImageConverter\Exception\ApiException;
use ImageConverter\Exception\RateLimitException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;
use Throwable;

/**
 * Fetches a key-value store record of the Apify API into a stream.
 *
 * The first URL must be a record URL on the host of `Config::$baseUrl`; only that host gets the API token. Redirects
 * are followed by hand: https only, and the token is dropped as soon as the URL is not such a record URL. No message
 * or previous exception carries a URL, which may hold a signature.
 *
 * @internal
 */
final readonly class Downloader
{
    private const MAX_REDIRECTS = 3;

    /** Minimum seconds a whole download gets, whatever `Config::$timeout` says. */
    private const MIN_WINDOW_SECONDS = 30;

    private const CHUNK_BYTES = 65536;

    private const MAX_EMPTY_READS = 100;

    private const BROKEN = 'Download failed: the connection broke while reading the file';

    /** What the retry hook is told instead of the path, which names the store and the file. */
    private const LOG_PATH = '/key-value-stores/{id}/records/{key}';

    public function __construct(private Config $config, private ClientInterface $http, private Retrier $retrier) {}

    /**
     * Streams the record into `$sink` and returns the number of bytes written.
     *
     * @param  resource  $sink
     * @param  string|null  $token  Null downloads without authentication.
     * @param  int|null  $expectedBytes  The size the file must have, when known.
     *
     * @throws ApiException
     * @throws RateLimitException
     */
    public function download(string $url, mixed $sink, ?string $token, int $maxBytes, ?int $expectedBytes): int
    {
        $uri = $this->parse($url);

        if ($uri === null || ! $this->isRecordUrl($uri)) {
            throw new ApiException(
                'Download refused: outputUrl is not an https key-value store record URL on the Apify API host'
            );
        }

        $deadline = microtime(true) + max($this->config->timeout, self::MIN_WINDOW_SECONDS);

        for ($redirects = 0; ; $redirects++) {
            $response = $this->send($uri, $this->isRecordUrl($uri) ? $token : null, $deadline);
            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 300) {
                return $this->copy($response, $sink, $maxBytes, $expectedBytes, $deadline);
            }

            if (! in_array($status, [301, 302, 303, 307, 308], true)) {
                throw new ApiException("Download failed with status {$status}", $status);
            }

            if ($redirects >= self::MAX_REDIRECTS) {
                throw new ApiException('Download failed: too many redirects');
            }

            $location = $this->parse($response->getHeaderLine('Location'), $uri);

            if ($location === null) {
                throw new ApiException('Download refused: the redirect target is not a valid https URL');
            }

            $uri = $location;
        }
    }

    /** An absolute https URL without user info; relative references are resolved against `$base`. */
    private function parse(string $url, ?UriInterface $base = null): ?UriInterface
    {
        // Whitespace, control characters, backslashes and non-ASCII are read differently by different URL parsers.
        if ($url === '' || preg_match('/[^\x21-\x7e]|\\\\/', $url) === 1) {
            return null;
        }

        try {
            $uri = new Uri($url);

            if ($base !== null) {
                $uri = UriResolver::resolve($base, $uri);
            }
        } catch (MalformedUriException) {
            return null;
        }

        return $uri->getScheme() === 'https' && $uri->getHost() !== '' && $uri->getUserInfo() === '' ? $uri : null;
    }

    private function isRecordUrl(UriInterface $uri): bool
    {
        $api = new Uri($this->config->baseUrl);

        if (strcasecmp($uri->getHost(), $api->getHost()) !== 0 || $uri->getPort() !== $api->getPort()) {
            return false;
        }

        $pattern = '~\A'.preg_quote(rtrim($api->getPath(), '/'), '~').'/key-value-stores/([^/]+)/records/([^/]+)\z~';

        if (preg_match($pattern, $uri->getPath(), $match) !== 1) {
            return false;
        }

        // Decoded, neither part may be a dot segment or hold a separator that could lead to another API path.
        return preg_match('/\A(?!\.{1,2}\z)[A-Za-z0-9._~-]+\z/', rawurldecode($match[1])) === 1
            && preg_match('/\A(?!\.{1,2}\z)[A-Za-z0-9!_.\'()-]+\z/', rawurldecode($match[2])) === 1;
    }

    private function send(UriInterface $uri, ?string $token, float $deadline): ResponseInterface
    {
        $options = [
            'allow_redirects' => false,
            'stream' => true,
            'headers' => $token === null ? [] : ['Authorization' => "Bearer {$token}"],
        ];

        for ($attempt = 0; ; $attempt++) {
            $options['timeout'] = $this->retrier->requestTimeout($this->config->requestTimeout, $deadline);
            $options['read_timeout'] = $options['timeout'];

            try {
                return $this->http->request('GET', (string) $uri, $options);
            } catch (GuzzleException $e) {
                if ($this->retrier->retry($e, 'GET', self::LOG_PATH, $attempt, $deadline)) {
                    continue;
                }

                $this->fail($e);
            }
        }
    }

    private function fail(GuzzleException $e): never
    {
        if (! $e instanceof BadResponseException) {
            throw new ApiException('Download failed: the file server could not be reached or did not answer in time');
        }

        $status = $e->getResponse()->getStatusCode();

        if ($status === 429) {
            throw new RateLimitException('Rate limit exceeded', Retrier::retryAfter($e->getResponse()) ?? 60);
        }

        $hint = match ($status) {
            401, 403 => ': access denied, set the API key of the account that owns the store',
            404 => ': no such file, the run\'s storage may have expired or been deleted, or it is not accessible',
            default => '',
        };

        throw new ApiException("Download failed with status {$status}{$hint}", $status);
    }

    /**
     * @param  resource  $sink
     */
    private function copy(
        ResponseInterface $response,
        mixed $sink,
        int $maxBytes,
        ?int $expectedBytes,
        float $deadline,
    ): int {
        $length = $response->getHeaderLine('Content-Length');
        $length = ctype_digit($length) ? (int) $length : null;
        $tooLarge = "Download refused: the file is larger than the limit of {$maxBytes} bytes";

        if ($length !== null && $length > $maxBytes) {
            throw new ApiException($tooLarge);
        }

        $body = $response->getBody();
        $total = 0;
        $empty = 0;

        try {
            while (! $body->eof()) {
                if (microtime(true) >= $deadline) {
                    throw new ApiException('Download failed: it did not finish in time');
                }

                $chunk = $this->read($body);

                if ($chunk === '') {
                    // A stalled or broken stream keeps returning nothing without reaching its end.
                    if (++$empty >= self::MAX_EMPTY_READS) {
                        throw new ApiException(self::BROKEN);
                    }

                    usleep(10_000);

                    continue;
                }

                $empty = 0;
                $total += strlen($chunk);

                if ($total > $maxBytes) {
                    throw new ApiException($tooLarge);
                }

                if (@fwrite($sink, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Could not write the downloaded data, the disk may be full');
                }
            }
        } finally {
            $body->close();
        }

        if (($length !== null && $total !== $length) || ($expectedBytes !== null && $total !== $expectedBytes)) {
            throw new ApiException(
                "Download failed: the file is incomplete or has an unexpected size ({$total} bytes)"
            );
        }

        return $total;
    }

    private function read(StreamInterface $body): string
    {
        try {
            return $body->read(self::CHUNK_BYTES);
        } catch (Throwable) {
            throw new ApiException(self::BROKEN);
        }
    }
}
