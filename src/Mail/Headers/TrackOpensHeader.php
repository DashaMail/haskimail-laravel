<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Mail\Headers;

use Symfony\Component\Mime\Header\UnstructuredHeader;

/**
 * Enable or disable the open-tracking pixel for this message.
 */
final class TrackOpensHeader extends UnstructuredHeader
{
    public function __construct(bool $enabled = true)
    {
        parent::__construct(HaskimailHeader::TRACK_OPENS, $enabled ? 'true' : 'false');
    }
}
