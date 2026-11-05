<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Enums;

/**
 * Values of the "bounce_category" field of the bounce webhook.
 */
enum BounceCategory: string
{
    case Hard = 'hrd';
    case Soft = 'sft';
    case Blocked = 'blk';
    case Spam = 'spm';
}
