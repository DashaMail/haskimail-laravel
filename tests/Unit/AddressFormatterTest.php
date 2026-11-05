<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Tests\Unit;

use Haskimail\Laravel\Exceptions\InvalidMessageException;
use Haskimail\Laravel\Support\AddressFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Address;

final class AddressFormatterTest extends TestCase
{
    private static function encoded(string $name): string
    {
        return '=?UTF-8?B?'.base64_encode($name).'?=';
    }

    /**
     * @return iterable<string, array{Address, string}>
     */
    public static function addresses(): iterable
    {
        yield 'bare address' => [new Address('ivan@example.com'), 'ivan@example.com'];
        yield 'latin name' => [new Address('ivan@example.com', 'Ivan Petrov'), 'Ivan Petrov <ivan@example.com>'];
        yield 'cyrillic name stays readable' => [new Address('shop@example.com', 'Магазин'), 'Магазин <shop@example.com>'];
        // The live API splits quoted names at commas; encoded-words survive intact.
        yield 'comma is encoded' => [new Address('r@example.com', 'Иван Иванов, маркетолог.'), self::encoded('Иван Иванов, маркетолог.').' <r@example.com>'];
        yield 'dot is encoded' => [new Address('r@example.com', 'Ivan P.'), self::encoded('Ivan P.').' <r@example.com>'];
        yield 'quotes and backslashes are encoded' => [new Address('r@example.com', 'The "Best\\Shop"'), self::encoded('The "Best\\Shop"').' <r@example.com>'];
        yield 'angle brackets cannot forge an address' => [new Address('r@example.com', 'Evil <attacker@evil.test>'), self::encoded('Evil <attacker@evil.test>').' <r@example.com>'];
        yield 'encoded-word lookalike is encoded again' => [new Address('r@example.com', '=?utf-8?B?0JHQsNC90Lo=?='), self::encoded('=?utf-8?B?0JHQsNC90Lo=?=').' <r@example.com>'];
        yield 'line breaks are removed' => [new Address('r@example.com', "Ivan\r\nBcc: x@evil.test"), self::encoded('IvanBcc: x@evil.test').' <r@example.com>'];
        yield 'invisible and bidi characters are removed' => [new Address('r@example.com', "Bank\u{202E}\u{200B}\u{2028}\u{061C}Online"), 'Bank Online <r@example.com>'];
        yield 'filler-only name is dropped' => [new Address('r@example.com', "\u{3164}\u{3164}"), 'r@example.com'];
        yield 'blank name is dropped' => [new Address('r@example.com', "  \t "), 'r@example.com'];
        yield 'IDN domain is punycoded' => [new Address('info@пример.рф'), 'info@xn--e1afmkfd.xn--p1ai'];
    }

    #[DataProvider('addresses')]
    public function test_it_formats_addresses(Address $address, string $expected): void
    {
        self::assertSame($expected, AddressFormatter::format($address));
    }

    public function test_long_names_are_split_into_valid_encoded_words(): void
    {
        $name = str_repeat('Интернет-магазин «Ромашка», ', 4);
        $formatted = AddressFormatter::format(new Address('r@example.com', $name));

        preg_match_all('/=\?UTF-8\?B\?([A-Za-z0-9+\/=]+)\?=/', $formatted, $words);
        foreach ($words[0] as $word) {
            self::assertLessThanOrEqual(75, strlen($word));
        }
        self::assertSame(trim($name), implode('', array_map('base64_decode', $words[1])));
        self::assertStringEndsWith(' <r@example.com>', $formatted);
    }

    public function test_it_formats_lists(): void
    {
        self::assertSame(
            'a@example.com, B <b@example.com>',
            AddressFormatter::formatList([new Address('a@example.com'), new Address('b@example.com', 'B')]),
        );
    }

    public function test_quoted_local_parts_with_separators_are_refused(): void
    {
        $this->expectException(InvalidMessageException::class);

        AddressFormatter::format(new Address('"x,attacker@evil.test,"@example.com'));
    }

    public function test_joiners_used_by_names_and_emoji_are_kept(): void
    {
        $family = "👨\u{200D}👩\u{200D}👧";
        self::assertStringContainsString(base64_encode($family), AddressFormatter::format(new Address('r@example.com', $family)));

        self::assertSame('=?UTF-8?B?'.base64_encode("Ali\u{200C}reza").'?= <r@example.com>', AddressFormatter::format(new Address('r@example.com', "Ali\u{200C}reza")));
        self::assertSame('r@example.com', AddressFormatter::format(new Address('r@example.com', "\u{2800}\u{2800}")));
    }
}
