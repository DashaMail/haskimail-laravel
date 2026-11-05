<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Exceptions;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Wrong, missing or unsuitable API token (HTTP 401, ErrorCode 10), or the request
 * IP is not on the account allow-list (HTTP 403).
 */
final class AuthenticationException extends ApiException implements TransportExceptionInterface
{
    use TransportFailure;
}
