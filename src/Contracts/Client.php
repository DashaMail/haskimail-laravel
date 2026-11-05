<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Contracts;

use Haskimail\Laravel\Data\BatchResult;
use Haskimail\Laravel\Data\SendResult;
use SensitiveParameter;

/**
 * Haskimail sending API. Type-hint this interface to be able to swap in
 * Haskimail::fake() or a test double.
 */
interface Client
{
    /**
     * POST /email.
     *
     * @param  array<string, mixed>  $message  API fields: From, To, Subject, HtmlBody, ...
     */
    public function send(array $message): SendResult;

    /**
     * POST /email/withTemplate.
     *
     * @param  array<string, mixed>  $message  API fields including TemplateId or TemplateAlias.
     */
    public function sendWithTemplate(array $message): SendResult;

    /**
     * POST /email/batch (1–500 messages).
     *
     * @param  list<array<string, mixed>>  $messages
     */
    public function sendBatch(array $messages): BatchResult;

    /**
     * POST /email/batchWithTemplates (1–500 messages).
     *
     * @param  list<array<string, mixed>>  $messages
     */
    public function sendBatchWithTemplates(array $messages): BatchResult;

    /**
     * A client authenticating with another server token (e.g. another Haskimail server).
     */
    public function withToken(#[SensitiveParameter] string $token): static;
}
