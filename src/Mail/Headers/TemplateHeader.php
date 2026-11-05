<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Mail\Headers;

use Haskimail\Laravel\Exceptions\InvalidMessageException;
use Haskimail\Laravel\Support\MessageValidator;
use Symfony\Component\Mime\Header\UnstructuredHeader;

/**
 * Render the message from a stored Haskimail template (POST /email/withTemplate).
 *
 * The template model is kept in memory only; it is never serialized into a MIME header.
 */
final class TemplateHeader extends UnstructuredHeader
{
    /**
     * @param  int|string  $template  Numeric TemplateId or TemplateAlias.
     * @param  array<string, scalar|null>  $model  Literal replacements, e.g. ['{{name}}' => 'Ivan'].
     */
    public function __construct(
        private readonly int|string $template,
        private readonly array $model = [],
    ) {
        if (is_int($template) ? $template <= 0 : trim($template) === '') {
            throw new InvalidMessageException('Template must be a positive TemplateId or a non-empty TemplateAlias.');
        }

        foreach ($model as $key => $value) {
            if (! is_string($key) || $key === '' || ($value !== null && ! is_scalar($value))) {
                throw new InvalidMessageException('Template model must map non-empty string keys to scalar values.');
            }

            // Values are substituted into the subject as well: no line breaks.
            MessageValidator::singleLine('TemplateModel "'.$key.'"', (string) $value);
        }

        parent::__construct(HaskimailHeader::TEMPLATE, (string) $template);
    }

    public function template(): int|string
    {
        return $this->template;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function model(): array
    {
        return $this->model;
    }
}
