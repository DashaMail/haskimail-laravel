<?php

declare(strict_types=1);

namespace Haskimail\Laravel;

use Composer\InstalledVersions;
use GuzzleHttp\Exception\ConnectException as GuzzleConnectException;
use GuzzleHttp\Exception\TransferException;
use Haskimail\Laravel\Contracts\Client;
use Haskimail\Laravel\Data\BatchResult;
use Haskimail\Laravel\Data\SendResult;
use Haskimail\Laravel\Exceptions\ApiException;
use Haskimail\Laravel\Exceptions\ConfigurationException;
use Haskimail\Laravel\Exceptions\ConnectionException;
use Haskimail\Laravel\Exceptions\InvalidMessageException;
use Haskimail\Laravel\Support\MessageValidator;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use JsonException;
use OutOfBoundsException;
use SensitiveParameter;

/**
 * Thin, strict client for the Haskimail sending API.
 *
 * Message arrays use the exact field names of the API (From, To, HtmlBody, ...),
 * see https://haskimail.ru/developer/api/email-api.
 */
final class HaskimailClient implements Client
{
    public const DEFAULT_ENDPOINT = 'https://api.haskimail.ru';

    /** Token that validates send requests without sending anything. */
    public const TEST_TOKEN = 'HASKIMAIL_API_TEST';

    public const MAX_RECIPIENTS = 50;

    public const MAX_BATCH_SIZE = 500;

    private const MAX_RETRY_DELAY_MS = 5_000;

    private const MAX_RETRY_AFTER_SECONDS = 10;

    /** Upper bound for the time spent sleeping between attempts of one request. */
    private const MAX_TOTAL_RETRY_WAIT_MS = 20_000;

    /**
     * cURL errors that guarantee the request never reached the API, so it is safe to repeat:
     * 5/6 = could not resolve proxy/host, 7 = could not connect, 35 = TLS handshake failed.
     */
    private const SAFE_CURL_ERRORS = [5, 6, 7, 35];

    private readonly string $token;

    private readonly string $endpoint;

    public function __construct(
        private readonly HttpFactory $http,
        #[SensitiveParameter] string $token,
        string $endpoint = self::DEFAULT_ENDPOINT,
        private readonly float $timeout = 30.0,
        private readonly float $connectTimeout = 10.0,
        private readonly int $retries = 2,
        private readonly int $retryDelayMs = 200,
    ) {
        $this->token = self::validateToken($token);
        $this->endpoint = self::validateEndpoint($endpoint);

        if ($timeout <= 0 || $connectTimeout <= 0) {
            throw ConfigurationException::invalidOption('timeout', 'timeouts must be positive numbers of seconds.');
        }

        if ($retries < 0 || $retries > 10) {
            throw ConfigurationException::invalidOption('retries', 'must be between 0 and 10.');
        }

        if ($retryDelayMs < 0) {
            throw ConfigurationException::invalidOption('retry_delay', 'must not be negative.');
        }
    }

    /**
     * Return a copy of the client that authenticates with another server token.
     */
    public function withToken(#[SensitiveParameter] string $token): static
    {
        return new self($this->http, $token, $this->endpoint, $this->timeout, $this->connectTimeout, $this->retries, $this->retryDelayMs);
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function isTestMode(): bool
    {
        return $this->token === self::TEST_TOKEN;
    }

    /**
     * POST /email — send a single message.
     *
     * @param  array<string, mixed>  $message
     *
     * @throws ApiException when the message is rejected
     * @throws ConnectionException when the API cannot be reached
     */
    public function send(array $message): SendResult
    {
        return $this->sendOne('/email', $message);
    }

    /**
     * POST /email/withTemplate — send a single message rendered from a stored template.
     *
     * @param  array<string, mixed>  $message
     */
    public function sendWithTemplate(array $message): SendResult
    {
        return $this->sendOne('/email/withTemplate', $message, requireTemplate: true);
    }

    /**
     * POST /email/batch — send up to 500 messages in one request.
     *
     * Messages are accepted or rejected independently: inspect the returned BatchResult.
     *
     * @param  list<array<string, mixed>>  $messages
     */
    public function sendBatch(array $messages): BatchResult
    {
        return $this->sendMany('/email/batch', self::assertBatch($messages, requireTemplate: false));
    }

    /**
     * POST /email/batchWithTemplates — send up to 500 template messages in one request.
     *
     * @param  list<array<string, mixed>>  $messages
     */
    public function sendBatchWithTemplates(array $messages): BatchResult
    {
        return $this->sendMany('/email/batchWithTemplates', ['Messages' => self::assertBatch($messages, requireTemplate: true)]);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function sendOne(string $path, array $message, bool $requireTemplate = false): SendResult
    {
        MessageValidator::validate($message, $requireTemplate);

        [$response, $body] = $this->request($path, $message);

        if (! is_array($body) || array_is_list($body) || ! isset($body['ErrorCode'])) {
            // The API reports rejections with HTTP 422; a 2xx means the message was
            // accepted even if the body is unreadable. Throwing here would make
            // queue retries deliver the message twice.
            return new SendResult(
                to: is_string($message['To'] ?? null) ? $message['To'] : '',
                messageId: null,
                submittedAt: null,
                errorCode: 0,
                message: sprintf('Accepted (HTTP %d) with an unreadable response body.', $response->status()),
            );
        }

        $result = SendResult::fromArray($body);

        if (! $result->successful()) {
            throw ApiException::fromResponse($response->status(), $body);
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function sendMany(string $path, array $payload): BatchResult
    {
        [$response, $body] = $this->request($path, $payload);

        if (! is_array($body) || ! array_is_list($body)) {
            throw ApiException::fromResponse($response->status(), null);
        }

        return BatchResult::fromArray($body);
    }

    /**
     * Perform the HTTP call with bounded, idempotency-aware retries.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array{0: Response, 1: array<array-key, mixed>|null}
     */
    private function request(string $path, array $payload): array
    {
        $json = self::encode($payload);
        $attempt = 0;
        $waitedMs = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->http
                    ->baseUrl($this->endpoint)
                    ->timeout($this->timeout)
                    ->connectTimeout($this->connectTimeout)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'X-Haskimail-Server-Token' => $this->token,
                        'User-Agent' => self::userAgent(),
                    ])
                    ->withBody($json, 'application/json')
                    // Never follow redirects: Guzzle would resend the token header, possibly over http.
                    ->withoutRedirecting()
                    ->post($path);
            } catch (HttpConnectionException|TransferException $e) {
                $notSent = self::failedBeforeSending($e);

                if ($notSent && $attempt <= $this->retries && $this->backoff($attempt, '', $waitedMs)) {
                    continue;
                }

                throw ConnectionException::fromThrowable($e, ! $notSent);
            }

            $body = self::decode($response->body());

            if ($response->successful()) {
                return [$response, $body];
            }

            $exception = ApiException::fromResponse($response->status(), $body);

            if ($attempt <= $this->retries && self::safeToRepeat($exception) && $this->backoff($attempt, $response->header('Retry-After'), $waitedMs)) {
                continue;
            }

            throw $exception;
        }
    }

    /**
     * Sleep before the next attempt. Returns false when the wait budget is exhausted.
     */
    private function backoff(int $attempt, string $retryAfter, int &$waitedMs): bool
    {
        if (ctype_digit($retryAfter)) {
            $delay = min((int) $retryAfter, self::MAX_RETRY_AFTER_SECONDS) * 1000;
        } else {
            $base = min(self::MAX_RETRY_DELAY_MS, $this->retryDelayMs * (1 << min($attempt - 1, 10)));
            $delay = $base + ($base > 0 ? random_int(0, intdiv($base, 2)) : 0);
        }

        if ($waitedMs + $delay > self::MAX_TOTAL_RETRY_WAIT_MS) {
            return false;
        }

        $waitedMs += $delay;
        Sleep::for($delay)->milliseconds();

        return true;
    }

    /**
     * Whether the request certainly never reached the API, so repeating it is safe.
     */
    private static function failedBeforeSending(HttpConnectionException|TransferException $e): bool
    {
        $previous = $e instanceof GuzzleConnectException ? $e : $e->getPrevious();

        if (! $previous instanceof GuzzleConnectException) {
            return false;
        }

        $errno = $previous->getHandlerContext()['errno'] ?? null;

        if (is_int($errno) && in_array($errno, self::SAFE_CURL_ERRORS, true)) {
            return true;
        }

        // cURL error 28 is used for both connect and transfer timeouts; only the former is safe.
        return $errno === 28 && preg_match('/\b(Connection|Resolving) timed out\b|\bFailed to connect\b/i', $previous->getMessage()) === 1;
    }

    /**
     * Repeated automatically only when the request certainly was not processed:
     * 429 and 503, or ErrorCode 36/100 (the message was rejected with "try later").
     * A 500 is retryable but left to the caller (e.g. a queue with backoff): the
     * documentation asks to "retry later" and the message may have been accepted.
     */
    private static function safeToRepeat(ApiException $exception): bool
    {
        return $exception->isRetryable() && ! $exception->mightHaveBeenSent();
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private static function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $e) {
            throw new InvalidMessageException('The message cannot be encoded as JSON: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function decode(string $body): ?array
    {
        if ($body === '') {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<array-key, mixed>  $messages
     * @return list<array<string, mixed>>
     */
    private static function assertBatch(array $messages, bool $requireTemplate): array
    {
        if (! array_is_list($messages)) {
            throw new InvalidMessageException('Batch messages must be a list.');
        }

        $count = count($messages);

        if ($count === 0 || $count > self::MAX_BATCH_SIZE) {
            throw new InvalidMessageException(sprintf(
                'A batch must contain between 1 and %d messages, %d given. Use chunks for larger sends.',
                self::MAX_BATCH_SIZE,
                $count,
            ));
        }

        foreach ($messages as $index => $message) {
            if (! is_array($message)) {
                throw new InvalidMessageException(sprintf('Batch item #%d must be a non-empty associative array of API fields.', $index));
            }

            MessageValidator::validate($message, $requireTemplate, sprintf('Batch item #%d', $index));
        }

        /** @var list<array<string, mixed>> $messages */
        return $messages;
    }

    private static function validateToken(#[SensitiveParameter] string $token): string
    {
        $token = trim($token);

        if ($token === '') {
            throw ConfigurationException::missingToken();
        }

        // Header injection guard: tokens are opaque printable ASCII.
        if (preg_match('/^[\x21-\x7E]+$/D', $token) !== 1) {
            throw ConfigurationException::invalidOption('token', 'contains whitespace, control or non-ASCII characters.');
        }

        return $token;
    }

    private static function validateEndpoint(string $endpoint): string
    {
        $parts = parse_url($endpoint);

        if (
            $parts === false
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw ConfigurationException::insecureEndpoint($endpoint);
        }

        return rtrim($endpoint, '/');
    }

    public static function version(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('dashamail/haskimail-laravel') ?? 'dev';
        } catch (OutOfBoundsException) {
            return 'dev';
        }
    }

    private static function userAgent(): string
    {
        $laravel = class_exists(\Illuminate\Foundation\Application::class)
            ? ' Laravel/'.\Illuminate\Foundation\Application::VERSION
            : '';

        return sprintf('haskimail-laravel/%s PHP/%s%s', self::version(), PHP_VERSION, $laravel);
    }

    /**
     * Never leak the token through dd(), dump() or var_dump().
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'token' => '***',
            'timeout' => $this->timeout,
            'connectTimeout' => $this->connectTimeout,
            'retries' => $this->retries,
        ];
    }
}
