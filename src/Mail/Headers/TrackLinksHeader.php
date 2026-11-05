<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Mail\Headers;

use Symfony\Component\Mime\Header\UnstructuredHeader;

/**
 * Enable or disable link tracking for this message ("TrackLinks", a boolean in the
 * email API). Without it the server setting applies.
 */
final class TrackLinksHeader extends UnstructuredHeader
{
    public function __construct(bool $enabled = true)
    {
        parent::__construct(HaskimailHeader::TRACK_LINKS, $enabled ? 'true' : 'false');
    }
}
