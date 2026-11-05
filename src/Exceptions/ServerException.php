<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Exceptions;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Haskimail failed to process the request (HTTP 5xx).
 *
 * 500 and 503 are documented as retryable. A 500, 502 or 504 does not say whether
 * the message was accepted, see mightHaveBeenSent().
 */
final class ServerException extends ApiException implements TransportExceptionInterface
{
    use TransportFailure;

    public function mightHaveBeenSent(): bool
    {
        return $this->httpStatus() !== 503;
    }
}
