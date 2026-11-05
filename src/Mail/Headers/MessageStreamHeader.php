<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Mail\Headers;

use Symfony\Component\Mime\Header\UnstructuredHeader;

/**
 * Send the message through a specific message stream (channel) instead of the default one.
 */
final class MessageStreamHeader extends UnstructuredHeader
{
    public function __construct(string $stream)
    {
        parent::__construct(HaskimailHeader::MESSAGE_STREAM, $stream);
    }
}
