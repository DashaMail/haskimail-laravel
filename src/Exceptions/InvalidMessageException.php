<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Exceptions;

use InvalidArgumentException;

/**
 * Thrown before any HTTP request is made when a message cannot possibly be accepted
 * by the Haskimail API (no sender, too many recipients, forbidden attachment, ...).
 */
final class InvalidMessageException extends InvalidArgumentException implements HaskimailException {}
