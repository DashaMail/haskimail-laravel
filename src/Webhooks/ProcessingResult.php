<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Webhooks;

enum ProcessingResult
{
    /** Listeners ran successfully. */
    case Processed;

    /** The event was already processed; it was skipped. */
    case Duplicate;

    /** The same event is being processed right now; Haskimail should retry later. */
    case InProgress;
}
