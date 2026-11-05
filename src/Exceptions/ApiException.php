<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Exceptions;

use Haskimail\Laravel\Enums\ErrorCode;
use RuntimeException;

/**
 * The Haskimail API rejected the request or answered unexpectedly.
 *
 * Subclasses describing transport-level failures (authentication, rate limiting,
 * server errors) also implement Symfony's TransportExceptionInterface, so Laravel's
 * failover / round-robin mailers switch to another mailer. Content errors
 * (InvalidRequestException) deliberately do not: re-sending a rejected message
 * through a different provider would bypass Haskimail's suppression lists.
 */
abstract class ApiException extends RuntimeException implements HaskimailException
{
    /**
     * @param  array<array-key, mixed>|null  $body  Decoded response body, if it was valid JSON.
     */
    final public function __construct(
        string $message,
        private readonly int $httpStatus,
        private readonly ?int $errorCode = null,
        private readonly ?string $apiMessage = null,
        private readonly ?array $body = null,
    ) {
        parent::__construct($message, $errorCode ?? 0);
    }

    /**
     * Build the most specific exception for an API error response.
     *
     * @param  array<array-key, mixed>|null  $body
     */
    public static function fromResponse(int $httpStatus, ?array $body): self
    {
        $errorCode = isset($body['ErrorCode']) && is_numeric($body['ErrorCode']) ? (int) $body['ErrorCode'] : null;
        $apiMessage = isset($body['Message']) && is_scalar($body['Message']) ? (string) $body['Message'] : null;

        $class = match (true) {
            $httpStatus >= 200 && $httpStatus < 300 && $errorCode === null => UnexpectedResponseException::class,
            $httpStatus >= 300 && $httpStatus < 400 => UnexpectedResponseException::class,
            $httpStatus === 401, $httpStatus === 403, $errorCode === ErrorCode::InvalidApiToken->value => AuthenticationException::class,
            $httpStatus === 429 => RateLimitException::class,
            $httpStatus >= 500 => ServerException::class,
            in_array($errorCode, [
                ErrorCode::AccountCannotSend->value,
                ErrorCode::SendingUnavailable->value,
                ErrorCode::TemporarySendingError->value,
                ErrorCode::InternalError->value,
            ], true) => SendingUnavailableException::class,
            default => InvalidRequestException::class,
        };

        $description = $apiMessage !== null && $apiMessage !== '' ? $apiMessage : 'Unexpected response';

        $message = $errorCode !== null
            ? sprintf('Haskimail API error %d: %s (HTTP %d).', $errorCode, $description, $httpStatus)
            : sprintf('Haskimail API error: %s (HTTP %d).', $description, $httpStatus);

        return new $class($message, $httpStatus, $errorCode, $apiMessage, $body);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * The Haskimail "ErrorCode" value, or null when the response carried none.
     */
    public function errorCode(): ?int
    {
        return $this->errorCode;
    }

    /**
     * The Haskimail "Message" value, exactly as returned by the API.
     */
    public function apiMessage(): ?string
    {
        return $this->apiMessage;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function body(): ?array
    {
        return $this->body;
    }

    public function is(ErrorCode $code): bool
    {
        return $this->errorCode === $code->value;
    }

    /**
     * Whether the documentation says the same request may succeed if repeated later
     * (HTTP 429/500/503, ErrorCode 36 or 100).
     *
     * 502/504 are deliberately excluded: a gateway error does not tell whether the
     * message was already accepted, and the API has no idempotency keys.
     */
    public function isRetryable(): bool
    {
        if (in_array($this->httpStatus, [429, 500, 503], true)) {
            return true;
        }

        return $this->errorCode !== null && (ErrorCode::tryFrom($this->errorCode)?->isRetryable() ?? false);
    }

    /**
     * Whether Haskimail may have accepted the message despite this error.
     * When true, repeating the request can deliver the message twice.
     */
    public function mightHaveBeenSent(): bool
    {
        return false;
    }
}
