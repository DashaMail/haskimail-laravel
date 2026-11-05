<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Transport;

/**
 * @internal
 */
final class TransportPayload
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(
        public readonly bool $usesTemplate,
        public readonly array $fields,
    ) {}
}
