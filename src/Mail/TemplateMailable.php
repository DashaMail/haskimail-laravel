<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Support\HtmlString;

/**
 * Base class for Mailables whose content is a stored Haskimail template.
 *
 * Requires the "haskimail" mailer: other transports would send an empty message.
 *
 *     final class OrderShipped extends TemplateMailable
 *     {
 *         public function __construct(public Order $order) {}
 *
 *         public function template(): int|string { return 'order-shipped'; }
 *
 *         public function templateModel(): array
 *         {
 *             return ['{{name}}' => $this->order->customer_name, '{{order}}' => (string) $this->order->id];
 *         }
 *     }
 */
abstract class TemplateMailable extends Mailable
{
    use InteractsWithHaskimail;

    /**
     * Numeric TemplateId or TemplateAlias.
     */
    abstract public function template(): int|string;

    /**
     * Literal replacements applied to the template subject and bodies.
     *
     * @return array<string, scalar|null>
     */
    public function templateModel(): array
    {
        return [];
    }

    protected function prepareMailableForDelivery()
    {
        parent::prepareMailableForDelivery();

        $this->haskimailTemplate($this->template(), $this->templateModel());
    }

    /**
     * Unlike regular Mailables, no subject is derived from the class name, so the
     * template's own subject is used unless one is set explicitly.
     *
     * @param  \Illuminate\Mail\Message  $message
     * @return $this
     */
    protected function buildSubject($message)
    {
        if (is_string($this->subject) && $this->subject !== '') {
            $message->subject($this->subject);
        }

        return $this;
    }

    /**
     * The body is rendered by Haskimail. Symfony still requires one, so a blank
     * placeholder is used; the transport never sends it.
     *
     * @return array<string, HtmlString>
     */
    protected function buildView()
    {
        return ['html' => new HtmlString('')];
    }
}
