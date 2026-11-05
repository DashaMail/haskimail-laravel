<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Mail\Headers;

/**
 * Names of the control headers understood by the Haskimail transport.
 *
 * They are consumed by the transport and never sent to recipients. They can be added
 * either with the typed header classes in this namespace or as plain text headers:
 *
 *     $message->getHeaders()->addTextHeader(HaskimailHeader::MESSAGE_STREAM, '2081');
 */
final class HaskimailHeader
{
    public const PREFIX = 'X-Haskimail-';

    public const MESSAGE_STREAM = 'X-Haskimail-Message-Stream';

    public const TRACK_OPENS = 'X-Haskimail-Track-Opens';

    public const TRACK_LINKS = 'X-Haskimail-Track-Links';

    public const TEMPLATE = 'X-Haskimail-Template';

    private function __construct() {}
}
