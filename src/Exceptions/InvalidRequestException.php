<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Exceptions;

/**
 * The message was rejected because of its content or the account state
 * (HTTP 4xx, usually 422 with an ErrorCode: invalid address, unconfirmed sender,
 * unknown stream or template, ...).
 *
 * Repeating the same request will fail again. This exception intentionally does
 * not trigger Laravel's failover mailer.
 */
final class InvalidRequestException extends ApiException {}
