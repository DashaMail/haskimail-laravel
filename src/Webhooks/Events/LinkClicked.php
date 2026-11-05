<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Webhooks\Events;

/**
 * The recipient clicked a tracked link (event "clicked"); fired for every click.
 */
final class LinkClicked extends HaskimailWebhookEvent {}
