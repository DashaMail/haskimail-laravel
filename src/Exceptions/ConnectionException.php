<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Exceptions;

use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * The Haskimail API could not be reached or did not answer in time.
 */
final class ConnectionException extends RuntimeException implements HaskimailException, TransportExceptionInterface
{
    use TransportFailure;

    private bool $mightHaveBeenSent = true;

    public static function fromThrowable(Throwable $previous, bool $requestWasSent): self
    {
        $exception = new self('Could not reach the Haskimail API: '.$previous->getMessage(), 0, $previous);
        $exception->mightHaveBeenSent = $requestWasSent;

        return $exception;
    }

    /**
     * False only when the connection failed before the request was transmitted
     * (DNS, connect or TLS failure). After a read timeout the message may have
     * been accepted, so repeating the request can deliver it twice.
     */
    public function mightHaveBeenSent(): bool
    {
        return $this->mightHaveBeenSent;
    }
}
