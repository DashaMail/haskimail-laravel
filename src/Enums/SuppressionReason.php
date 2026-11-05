<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Enums;

enum SuppressionReason: string
{
    case HardBounce = 'HardBounce';
    case SpamComplaint = 'SpamComplaint';
    case ManualSuppression = 'ManualSuppression';
}
