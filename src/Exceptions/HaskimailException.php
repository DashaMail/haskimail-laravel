<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Exceptions;

use Throwable;

/**
 * Marker interface implemented by every exception thrown by this package.
 *
 * Catch this interface to handle any Haskimail-specific failure in one place.
 */
interface HaskimailException extends Throwable {}
