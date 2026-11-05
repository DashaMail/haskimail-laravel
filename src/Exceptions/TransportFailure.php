<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Exceptions;

/**
 * Implements the debug accessors of Symfony's TransportExceptionInterface.
 *
 * @internal
 */
trait TransportFailure
{
    private string $debug = '';

    public function getDebug(): string
    {
        return $this->debug;
    }

    public function appendDebug(string $debug): void
    {
        $this->debug .= $debug;
    }
}
