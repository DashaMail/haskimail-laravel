<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Mail;

/**
 * Fluent Haskimail options for Mailables and notification MailMessages.
 *
 * Works with any class exposing Laravel's withSymfonyMessage(callable) method and
 * stays serializable for queued mail. Options travel as X-Haskimail-* headers that
 * the Haskimail transport consumes; other transports send them as ordinary headers,
 * and cannot render templates.
 */
trait InteractsWithHaskimail
{
    /**
     * Send through a specific message stream (channel) ID.
     */
    public function haskimailStream(string $stream): static
    {
        return $this->withSymfonyMessage(new ApplyHaskimailHeader(ApplyHaskimailHeader::STREAM, $stream));
    }

    /**
     * Render the message from a stored template; the message body is ignored.
     *
     * A subject set on the message is sent along. Note that plain Mailables always
     * have one (Laravel derives it from the class name): use TemplateMailable to
     * keep the template's own subject.
     *
     * @param  array<string, scalar|null>  $model  Literal replacements, e.g. ['{{name}}' => 'Ivan'].
     */
    public function haskimailTemplate(int|string $template, array $model = []): static
    {
        return $this->withSymfonyMessage(new ApplyHaskimailHeader(ApplyHaskimailHeader::TEMPLATE, $template, $model));
    }

    public function haskimailTrackOpens(bool $enabled = true): static
    {
        return $this->withSymfonyMessage(new ApplyHaskimailHeader(ApplyHaskimailHeader::TRACK_OPENS, $enabled));
    }

    public function haskimailTrackLinks(bool $enabled = true): static
    {
        return $this->withSymfonyMessage(new ApplyHaskimailHeader(ApplyHaskimailHeader::TRACK_LINKS, $enabled));
    }
}
