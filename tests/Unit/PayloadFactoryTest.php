<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Tests\Unit;

use DateTimeImmutable;
use Haskimail\Laravel\Exceptions\InvalidMessageException;
use Haskimail\Laravel\Mail\Headers\HaskimailHeader;
use Haskimail\Laravel\Mail\Headers\MessageStreamHeader;
use Haskimail\Laravel\Mail\Headers\TemplateHeader;
use Haskimail\Laravel\Mail\Headers\TrackLinksHeader;
use Haskimail\Laravel\Mail\Headers\TrackOpensHeader;
use Haskimail\Laravel\Transport\PayloadFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

final class PayloadFactoryTest extends TestCase
{
    private static function email(): Email
    {
        return (new Email)
            ->from(new Address('shop@example.com', 'Магазин'))
            ->to('ivan@example.com')
            ->subject('Ваш заказ №1042 оформлен')
            ->html('<p>Здравствуйте!</p>')
            ->text('Здравствуйте!');
    }

    /**
     * @return array<string, mixed>
     */
    private static function make(Email $email, ?string $stream = null, ?Envelope $envelope = null): array
    {
        return (new PayloadFactory($stream))->make($email, $envelope ?? Envelope::create($email))->fields;
    }

    public function test_it_maps_a_complete_message(): void
    {
        $email = self::email()
            ->addCc(new Address('copy@example.com', 'Copy'))
            ->addBcc('hidden@example.com')
            ->replyTo('support@example.com', 'help@example.com');
        $email->getHeaders()
            ->add(new TagHeader('orders'))
            ->add(new MetadataHeader('order_id', '1042'))
            ->add(new MetadataHeader('client', 'web'))
            ->addTextHeader('X-Order-Id', '1042');

        self::assertSame([
            'From' => 'Магазин <shop@example.com>',
            'To' => 'ivan@example.com',
            'Cc' => 'Copy <copy@example.com>',
            'Bcc' => 'hidden@example.com',
            'ReplyTo' => 'support@example.com, help@example.com',
            'Subject' => 'Ваш заказ №1042 оформлен',
            'Tag' => 'orders',
            'Headers' => [['Name' => 'X-Order-Id', 'Value' => '1042']],
            'Metadata' => ['order_id' => '1042', 'client' => 'web'],
            'HtmlBody' => '<p>Здравствуйте!</p>',
            'TextBody' => 'Здравствуйте!',
        ], self::make($email));
    }

    public function test_minimal_message_contains_only_required_fields(): void
    {
        $email = (new Email)->from('a@example.com')->to('b@example.com')->text('hi');

        self::assertSame(['From' => 'a@example.com', 'To' => 'b@example.com', 'TextBody' => 'hi'], self::make($email));
    }

    public function test_envelope_only_recipients_become_bcc_and_duplicates_are_removed(): void
    {
        $email = self::email()->addCc('copy@example.com');
        $envelope = new Envelope(new Address('shop@example.com'), [
            new Address('ivan@example.com'),
            new Address('COPY@example.com'),
            new Address('audit@example.com'),
            new Address('Audit@Example.com'),
        ]);

        $payload = self::make($email, envelope: $envelope);

        self::assertSame('audit@example.com', $payload['Bcc']);
    }

    public function test_reserved_and_mime_headers_are_never_forwarded(): void
    {
        $email = self::email();
        $email->getHeaders()->addDateHeader('Date', new DateTimeImmutable)->addPathHeader('Return-Path', 'bounce@example.com');
        $email->getHeaders()->addIdHeader('Message-ID', 'my-id-123@example.com');

        self::assertSame([['Name' => 'Message-ID', 'Value' => '<my-id-123@example.com>']], self::make($email)['Headers']);
    }

    public function test_only_one_tag_is_allowed(): void
    {
        $email = self::email();
        $email->getHeaders()->add(new TagHeader('a'))->add(new TagHeader('b'));

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('single tag');

        self::make($email);
    }

    public function test_message_stream_defaults_and_can_be_overridden_per_message(): void
    {
        self::assertSame('2081', self::make(self::email(), '2081')['MessageStream']);

        $email = self::email();
        $email->getHeaders()->add(new MessageStreamHeader('broadcasts-1'));
        $payload = self::make($email, '2081');

        self::assertSame('broadcasts-1', $payload['MessageStream']);
        self::assertArrayNotHasKey('Headers', $payload, 'Control headers must not leak to recipients.');
    }

    public function test_plain_text_control_headers_are_supported_case_insensitively(): void
    {
        $email = self::email();
        $email->getHeaders()
            ->addTextHeader('x-haskimail-message-stream', '77')
            ->addTextHeader(HaskimailHeader::TRACK_OPENS, 'false')
            ->addTextHeader(HaskimailHeader::TRACK_LINKS, 'TRUE');

        $payload = self::make($email);

        self::assertSame('77', $payload['MessageStream']);
        self::assertFalse($payload['TrackOpens']);
        self::assertTrue($payload['TrackLinks']);
    }

    public function test_tracking_headers(): void
    {
        $email = self::email();
        $email->getHeaders()->add(new TrackOpensHeader)->add(new TrackLinksHeader);
        $payload = self::make($email);

        self::assertTrue($payload['TrackOpens']);
        self::assertTrue($payload['TrackLinks']);
    }

    public function test_invalid_control_header_values_are_rejected(): void
    {
        $email = self::email();
        $email->getHeaders()->addTextHeader(HaskimailHeader::TRACK_OPENS, 'maybe');

        $this->expectException(InvalidMessageException::class);
        self::make($email);
    }

    public function test_unknown_control_headers_are_rejected_to_catch_typos(): void
    {
        $email = self::email();
        $email->getHeaders()->addTextHeader('X-Haskimail-Mesage-Stream', '1');

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('Unknown Haskimail control header');
        self::make($email);
    }

    public function test_template_by_id_drops_bodies_and_keeps_envelope_fields(): void
    {
        $email = self::email();
        $email->getHeaders()->add(new TemplateHeader(4521, ['{{name}}' => 'Иван']))->add(new TagHeader('orders'));

        $result = (new PayloadFactory('2081'))->make($email, Envelope::create($email));

        self::assertTrue($result->usesTemplate);
        self::assertSame(4521, $result->fields['TemplateId']);
        self::assertEquals((object) ['{{name}}' => 'Иван'], $result->fields['TemplateModel']);
        self::assertSame('orders', $result->fields['Tag']);
        self::assertSame('2081', $result->fields['MessageStream']);
        self::assertArrayNotHasKey('HtmlBody', $result->fields);
        self::assertArrayNotHasKey('TextBody', $result->fields);
    }

    public function test_template_by_alias_with_empty_model_encodes_an_object(): void
    {
        $email = (new Email)->from('a@example.com')->to('b@example.com')->html(' ');
        $email->getHeaders()->addTextHeader(HaskimailHeader::TEMPLATE, 'welcome');

        $fields = self::make($email);

        self::assertSame('welcome', $fields['TemplateAlias']);
        self::assertSame('{}', json_encode($fields['TemplateModel']));
        self::assertArrayNotHasKey('Subject', $fields);
    }

    public function test_attachments_are_base64_encoded_with_content_type(): void
    {
        $email = self::email()->attach('%PDF-1.4 binary', 'invoice.pdf', 'application/pdf');

        self::assertSame([[
            'Name' => 'invoice.pdf',
            'Content' => base64_encode('%PDF-1.4 binary'),
            'ContentType' => 'application/pdf',
        ]], self::make($email)['Attachments']);
    }

    public function test_inline_images_reference_the_cid_used_in_html(): void
    {
        // Symfony / Laravel style: html references "cid:<name>".
        $email = self::email()->html('<img src="cid:logo.png">')->embed('PNG', 'logo.png', 'image/png');
        self::assertSame('cid:logo.png', self::make($email)['Attachments'][0]['ContentID']);

        // Laravel's $message->embed() style: html references the generated content id.
        $part = (new DataPart('PNG', 'logo.png', 'image/png'))->asInline();
        $email = self::email()->html('<img src="cid:'.$part->getContentId().'">')->addPart($part);
        self::assertSame('cid:'.$part->getContentId(), self::make($email)['Attachments'][0]['ContentID']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forbiddenNames(): iterable
    {
        yield 'exe' => ['setup.exe'];
        yield 'upper case' => ['SETUP.EXE'];
        yield 'trailing dot' => ['run.bat.'];
        yield 'trailing space' => ['run.vbs '];
    }

    #[DataProvider('forbiddenNames')]
    public function test_forbidden_attachment_types_are_rejected_locally(string $name): void
    {
        $email = self::email()->attach('MZ', $name);

        $this->expectException(InvalidMessageException::class);
        self::make($email);
    }

    public function test_attachment_names_cannot_carry_paths_or_control_characters(): void
    {
        $email = self::email()->attach('x', "../../etc/pass\r\nwd.txt");

        self::assertSame('passwd.txt', self::make($email)['Attachments'][0]['Name']);
    }

    public function test_more_than_fifty_recipients_are_rejected(): void
    {
        $email = self::email();
        foreach (range(1, 50) as $i) {
            $email->addBcc("user{$i}@example.com");
        }

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('at most 50 recipients');
        self::make($email);
    }

    public function test_message_needs_a_body_or_template(): void
    {
        $email = (new Email)->from('a@example.com')->to('b@example.com')->attach('x', 'a.txt');

        $this->expectException(InvalidMessageException::class);
        self::make($email);
    }

    public function test_multiple_from_addresses_are_rejected(): void
    {
        $email = self::email()->addFrom('second@example.com');

        $this->expectException(InvalidMessageException::class);
        self::make($email);
    }

    public function test_message_without_to_is_rejected(): void
    {
        $email = (new Email)->from('a@example.com')->bcc('b@example.com')->text('x');

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('"To"');
        self::make($email);
    }

    public function test_stream_bodies_are_read(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, '<b>stream</b>');
        $email = self::email()->html($stream);

        self::assertSame('<b>stream</b>', self::make($email)['HtmlBody']);
    }

    public function test_header_injection_is_refused(): void
    {
        $email = self::email();
        $email->getHeaders()->addTextHeader('X-Campaign', "a\r\nBcc: evil@example.com");

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('line breaks');
        self::make($email);
    }

    public function test_track_links_can_be_disabled(): void
    {
        $email = self::email();
        $email->getHeaders()->add(new TrackLinksHeader(false))->add(new TrackOpensHeader(false));

        $payload = self::make($email);
        self::assertFalse($payload['TrackLinks']);
        self::assertFalse($payload['TrackOpens']);
    }

    /**
     * @return iterable<string, array{int|string, array<array-key, mixed>}>
     */
    public static function invalidTemplates(): iterable
    {
        yield 'zero id' => [0, []];
        yield 'blank alias' => ['  ', []];
        yield 'list model' => [1, ['a']];
        yield 'nested model' => [1, ['{{x}}' => ['nested']]];
    }

    /**
     * @param  array<array-key, mixed>  $model
     */
    #[DataProvider('invalidTemplates')]
    public function test_invalid_templates_are_rejected(int|string $template, array $model): void
    {
        $this->expectException(InvalidMessageException::class);

        /** @phpstan-ignore argument.type */
        new TemplateHeader($template, $model);
    }

    public function test_non_ascii_header_values_are_sent_as_utf8_not_mime_encoded(): void
    {
        $value = 'Иван Петров, отдел продаж — очень длинное значение, которое Symfony свернула бы';
        $email = self::email();
        $email->getHeaders()->addTextHeader('X-Customer', $value);

        self::assertSame([['Name' => 'X-Customer', 'Value' => $value]], self::make($email)['Headers']);
    }

    public function test_structured_headers_are_unfolded(): void
    {
        $email = self::email();
        $email->getHeaders()->addIdHeader('References', array_map(fn (int $i): string => "message-{$i}@example.com", range(1, 6)));

        $value = self::make($email)['Headers'][0]['Value'];
        self::assertIsString($value);
        self::assertStringNotContainsString("\n", $value);
        self::assertStringContainsString('<message-6@example.com>', $value);
    }

    public function test_line_breaks_are_refused_in_every_single_line_field(): void
    {
        $cases = [
            'tag' => fn (Email $e) => $e->getHeaders()->add(new TagHeader("a\r\nb")),
            'metadata' => fn (Email $e) => $e->getHeaders()->add(new MetadataHeader('k', "a\nb")),
            'stream' => fn (Email $e) => $e->getHeaders()->add(new MessageStreamHeader("1\r\nX: y")),
            'alias' => fn (Email $e) => $e->getHeaders()->add(new TemplateHeader("a\nb")),
        ];

        foreach ($cases as $name => $mutate) {
            $email = self::email();
            $mutate($email);

            try {
                self::make($email);
                self::fail("Line break accepted in {$name}");
            } catch (InvalidMessageException $e) {
                self::assertStringContainsString('line breaks', $e->getMessage());
            }
        }
    }

    public function test_subject_line_breaks_are_normalized(): void
    {
        self::assertSame('Hello world', self::make(self::email()->subject("Hello\r\n  world"))['Subject']);
    }

    public function test_numeric_strings_are_aliases_only_integers_are_ids(): void
    {
        $email = self::email();
        $email->getHeaders()->add(new TemplateHeader('2024'));

        $fields = self::make($email);
        self::assertSame('2024', $fields['TemplateAlias']);
        self::assertArrayNotHasKey('TemplateId', $fields);
    }

    public function test_addresses_that_could_split_into_several_recipients_are_refused(): void
    {
        $email = self::email()->to(new Address('"x,attacker@evil.test,"@example.com'));

        $this->expectException(InvalidMessageException::class);
        self::make($email);
    }

    public function test_display_names_cannot_hide_behind_bidi_or_control_characters(): void
    {
        $email = self::email()->from(new Address('shop@example.com', "Bank\u{202E}moc.evil\u{0085}"));

        self::assertSame('=?UTF-8?B?'.base64_encode('Bank moc.evil').'?= <shop@example.com>', self::make($email)['From']);
    }

    public function test_parameterized_headers_keep_their_parameters(): void
    {
        $email = self::email();
        $email->getHeaders()->addParameterizedHeader('X-Meta', 'value', ['a' => 'b']);

        self::assertSame([['Name' => 'X-Meta', 'Value' => 'value; a=b']], self::make($email)['Headers']);
    }

    public function test_disguised_attachment_names_are_cleaned_and_checked(): void
    {
        $email = self::email()->attach('x', "invoice\u{202E}fdp.html");
        self::assertSame('invoicefdp.html', self::make($email)['Attachments'][0]['Name']);

        foreach (["invoice.exe\u{200B}", 'invoice.exe::$DATA'] as $name) {
            try {
                self::make(self::email()->attach('MZ', $name));
                self::fail("{$name} was accepted");
            } catch (InvalidMessageException $e) {
                self::assertStringContainsString('.exe', $e->getMessage());
            }
        }
    }

    public function test_attachment_media_type_parameters_are_refused(): void
    {
        $email = self::email()->addPart(new DataPart('x', 'invoice.pdf', 'application/pdf; name="payload.exe"'));

        $this->expectException(InvalidMessageException::class);
        self::make($email);
    }

    public function test_control_characters_are_refused_in_headers_and_flattened_in_subjects(): void
    {
        self::assertSame('a b', self::make(self::email()->subject("a\u{000B}\u{2028}b"))['Subject']);

        $email = self::email();
        $email->getHeaders()->addTextHeader('X-Note', "a\u{001B}[31mb");
        $this->expectException(InvalidMessageException::class);
        self::make($email);
    }
}
