<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Exceptions;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * The API answered with a body this client cannot interpret (not JSON, wrong shape).
 *
 * For a successful HTTP status the request was most likely processed.
 */
final class UnexpectedResponseException extends ApiException implements TransportExceptionInterface
{
    use TransportFailure;

    public function mightHaveBeenSent(): bool
    {
        return $this->httpStatus() < 300;
    }
}
