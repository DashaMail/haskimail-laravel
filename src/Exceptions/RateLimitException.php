<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Exceptions;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Too many requests (HTTP 429). Retry later.
 */
final class RateLimitException extends ApiException implements TransportExceptionInterface
{
    use TransportFailure;
}
