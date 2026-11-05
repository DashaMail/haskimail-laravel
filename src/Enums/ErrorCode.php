<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Enums;

/**
 * Haskimail API "ErrorCode" values relevant to sending, as documented at
 * https://haskimail.ru/developer/api/overview.
 *
 * The API may return codes that are not listed here; use ErrorCode::tryFrom().
 */
enum ErrorCode: int
{
    case Success = 0;
    case MissingRequiredFields = 3;
    case AccountCannotSend = 4;
    case InvalidEmailAddress = 6;
    case InvalidApiToken = 10;
    case NotFoundOrIncompleteRequest = 12;
    case ActionForbidden = 15;
    case AttachmentTooLarge = 26;
    case InvalidAttachmentType = 33;
    case SendingUnavailable = 35;
    case TemporarySendingError = 36;
    case InvalidHeaders = 40;
    case SenderAddressNotConfirmed = 52;
    case InternalError = 100;
    case InvalidBatchOrTestModeValue = 300;
    case SenderNotConfirmed = 400;
    case InvalidRequestField = 403;
    case UnknownApiMethod = 404;
    case ForbiddenAttachmentType = 411;
    case RecipientNotConfirmed = 412;
    case TemplateNotFound = 1101;
    case MessageStreamNotFound = 1226;
    case CannotSendThroughInboundStream = 1236;

    /**
     * Codes for which the documentation explicitly says "repeat the request later".
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::TemporarySendingError, self::InternalError => true,
            default => false,
        };
    }
}
