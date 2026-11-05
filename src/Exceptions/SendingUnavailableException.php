<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Exceptions;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * The account cannot send right now (ErrorCode 4, 35) or Haskimail asked to try
 * again later (ErrorCode 36, 100), although the API answered HTTP 422.
 *
 * Unlike InvalidRequestException this is a provider-side condition, so Laravel's
 * failover mailer switches to the next mailer.
 */
final class SendingUnavailableException extends ApiException implements TransportExceptionInterface
{
    use TransportFailure;
}
