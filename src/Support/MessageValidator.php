<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Support;

use Haskimail\Laravel\Exceptions\InvalidMessageException;
use stdClass;

/**
 * Rejects API payloads that Haskimail would accept but turn into something unsafe
 * or that it is documented to reject.
 *
 * Verified against the live API: a line break in Subject or in a Headers value is
 * not rejected but written into the message as a separate header, so every
 * single-line field is checked here before any request is made.
 *
 * @internal
 */
final class MessageValidator
{
    /** Fields rendered into single-line MIME headers. */
    private const SINGLE_LINE_FIELDS = ['From', 'To', 'Cc', 'Bcc', 'ReplyTo', 'Subject', 'Tag', 'MessageStream', 'TemplateAlias'];

    /**
     * Documented maximum lengths, counted in UTF-16 code units. "From" (255) is not
     * checked: the live API accepts longer values, which RFC 2047-encoded names need.
     */
    private const MAX_LENGTH = ['Subject' => 2000, 'Tag' => 1000];

    /** Control characters except TAB, plus Unicode line and paragraph separators. */
    private const LINE_BREAKING = '/[\x00-\x08\x0A-\x1F\x7F\x{0085}\x{2028}\x{2029}]/u';

    private const HEADER_NAME = '/^[\x21-\x39\x3B-\x7E]+$/D';

    /** type/subtype with optional simple parameters (e.g. charset), but never a file name. */
    private const MEDIA_TYPE = '/^[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*\/[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]*(?:\s*;\s*(?!(?:file)?name\b)[A-Za-z0-9-]+=(?:"[^"\\\\\x00-\x1F]*"|[A-Za-z0-9._-]+))*$/Di';

    /**
     * @param  array<array-key, mixed>  $message
     */
    public static function validate(array $message, bool $requireTemplate = false, string $context = 'Message'): void
    {
        if ($message === [] || array_is_list($message)) {
            throw new InvalidMessageException($context.' must be a non-empty associative array of API fields.');
        }

        if ($requireTemplate && ! isset($message['TemplateId']) && ! isset($message['TemplateAlias'])) {
            throw new InvalidMessageException($context.' requires "TemplateId" or "TemplateAlias".');
        }

        foreach (self::SINGLE_LINE_FIELDS as $field) {
            if (! array_key_exists($field, $message) || $message[$field] === null) {
                continue;
            }

            if (! is_scalar($message[$field]) || is_bool($message[$field])) {
                throw new InvalidMessageException(sprintf('%s field "%s" must be a string.', $context, $field));
            }

            self::singleLine($context.' field "'.$field.'"', (string) $message[$field]);
        }

        foreach (self::MAX_LENGTH as $field => $max) {
            if (is_string($message[$field] ?? null) && self::utf16Length($message[$field]) > $max) {
                throw new InvalidMessageException(sprintf('%s field "%s" is longer than %d characters.', $context, $field, $max));
            }
        }

        self::validateHeaders($message['Headers'] ?? null, $context);
        self::validateTemplateModel($message['TemplateModel'] ?? null, $context);
        self::validateMetadata($message['Metadata'] ?? null, $context);
        self::validateAttachments($message['Attachments'] ?? null, $context);
    }

    public static function singleLine(string $field, string $value): string
    {
        if (preg_match(self::LINE_BREAKING, $value) !== 0) {
            throw new InvalidMessageException(sprintf('%s must not contain line breaks or control characters.', $field));
        }

        return $value;
    }

    public static function mediaType(string $value): string
    {
        if (preg_match(self::MEDIA_TYPE, $value) !== 1) {
            throw new InvalidMessageException(sprintf('Attachment content type "%s" must be "type/subtype" without a file name parameter.', $value));
        }

        return $value;
    }

    public static function headerName(string $name): string
    {
        if (preg_match(self::HEADER_NAME, $name) !== 1) {
            throw new InvalidMessageException(sprintf('Invalid header name "%s".', $name));
        }

        return $name;
    }

    private static function validateHeaders(mixed $headers, string $context): void
    {
        if ($headers === null) {
            return;
        }

        if (! is_array($headers) || ! array_is_list($headers)) {
            throw new InvalidMessageException($context.' field "Headers" must be a list of {"Name", "Value"} objects.');
        }

        foreach ($headers as $header) {
            if (! is_array($header) || ! is_string($header['Name'] ?? null) || ! is_scalar($header['Value'] ?? null)) {
                throw new InvalidMessageException($context.' field "Headers" must be a list of {"Name", "Value"} objects.');
            }

            self::headerName($header['Name']);
            self::singleLine('Header "'.$header['Name'].'"', (string) $header['Value']);
        }
    }

    private static function validateMetadata(mixed $metadata, string $context): void
    {
        if ($metadata === null) {
            return;
        }

        if ($metadata instanceof stdClass) {
            $metadata = get_object_vars($metadata);
        }

        if (! is_array($metadata) || ($metadata !== [] && array_is_list($metadata))) {
            throw new InvalidMessageException($context.' field "Metadata" must be an object.');
        }

        foreach ($metadata as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                throw new InvalidMessageException(sprintf('Metadata "%s" must be a scalar value.', $key));
            }

            self::singleLine('Metadata key', (string) $key);
            self::singleLine('Metadata "'.$key.'"', (string) $value);
        }
    }

    /**
     * Model values are substituted into the template subject too, where a line break
     * would become an injected header.
     */
    private static function validateTemplateModel(mixed $model, string $context): void
    {
        if ($model === null) {
            return;
        }

        if ($model instanceof stdClass) {
            $model = get_object_vars($model);
        }

        if (! is_array($model)) {
            throw new InvalidMessageException($context.' field "TemplateModel" must be an object.');
        }

        foreach ($model as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                throw new InvalidMessageException(sprintf('TemplateModel "%s" must be a scalar value.', $key));
            }

            self::singleLine('TemplateModel "'.$key.'"', (string) $value);
        }
    }

    private static function validateAttachments(mixed $attachments, string $context): void
    {
        if ($attachments === null) {
            return;
        }

        if (! is_array($attachments) || ! array_is_list($attachments)) {
            throw new InvalidMessageException($context.' field "Attachments" must be a list.');
        }

        foreach ($attachments as $attachment) {
            if (! is_array($attachment) || ! is_string($attachment['Name'] ?? null) || ! is_string($attachment['Content'] ?? null) || ! is_string($attachment['ContentType'] ?? null)) {
                throw new InvalidMessageException('Each attachment needs string "Name", "Content" and "ContentType" fields.');
            }

            self::singleLine('Attachment name', $attachment['Name']);
            self::mediaType($attachment['ContentType']);

            if (isset($attachment['ContentID'])) {
                self::singleLine('Attachment ContentID', is_string($attachment['ContentID']) ? $attachment['ContentID'] : '');
            }
        }
    }

    private static function utf16Length(string $value): int
    {
        $converted = mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');

        return intdiv(strlen($converted), 2);
    }
}
