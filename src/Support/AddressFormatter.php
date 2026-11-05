<?php

declare(strict_types=1);

namespace Haskimail\Laravel\Support;

use Haskimail\Laravel\Exceptions\InvalidMessageException;
use Symfony\Component\Mime\Address;

/**
 * Formats addresses for the Haskimail API.
 *
 * Display names made only of letters, digits and spaces are sent as plain UTF-8,
 * as documented. Any other name is sent as RFC 2047 encoded-words: verified against
 * the live API, quoted names are split at commas ("Иван, маркетолог" arrives as
 * "маркетолог") and keep escaped quotes, while encoded-words arrive intact.
 *
 * @internal
 */
final class AddressFormatter
{
    private const PLAIN_NAME = '/^[\p{L}\p{M}\p{N} ]+$/Du';

    /**
     * Controls, invisible format characters (incl. bidi overrides), line/paragraph
     * separators and blank fillers. ZWNJ/ZWJ are kept: names and emoji need them.
     */
    private const UNSAFE_CHARACTERS = '/(?:(?![\x{200C}\x{200D}])[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]|[\x{115F}\x{1160}\x{2800}\x{3164}\x{FFA0}])+/u';

    /** Bytes of UTF-8 per encoded-word, keeping each word within the RFC 2047 limit of 75 characters. */
    private const ENCODED_WORD_BYTES = 45;

    /** Characters that would let one address be read as several by a list parser. */
    private const UNSAFE_ADDRESS = '/[\s,;<>"\\\\()\[\]]/';

    public static function format(Address $address): string
    {
        $email = $address->getEncodedAddress();

        if (preg_match(self::UNSAFE_ADDRESS, $email) === 1) {
            throw new InvalidMessageException(sprintf(
                'The address "%s" uses a quoted local part with separator characters, which Haskimail cannot accept safely.',
                $email,
            ));
        }

        $name = self::cleanName($address->getName());

        if ($name === '') {
            return $email;
        }

        if (preg_match(self::PLAIN_NAME, $name) === 1) {
            return $name.' <'.$email.'>';
        }

        return self::encodedWords($name).' <'.$email.'>';
    }

    private static function encodedWords(string $name): string
    {
        $words = [];
        $chunk = '';

        foreach (mb_str_split($name, 1, 'UTF-8') as $char) {
            if ($chunk !== '' && strlen($chunk) + strlen($char) > self::ENCODED_WORD_BYTES) {
                $words[] = '=?UTF-8?B?'.base64_encode($chunk).'?=';
                $chunk = '';
            }

            $chunk .= $char;
        }

        $words[] = '=?UTF-8?B?'.base64_encode($chunk).'?=';

        return implode(' ', $words);
    }

    /**
     * @param  array<Address>  $addresses
     */
    public static function formatList(array $addresses): string
    {
        return implode(', ', array_map(self::format(...), $addresses));
    }

    private static function cleanName(string $name): string
    {
        $clean = preg_replace(self::UNSAFE_CHARACTERS, ' ', $name);

        if ($clean === null) {
            throw new InvalidMessageException('A display name is not valid UTF-8.');
        }

        return trim((string) preg_replace('/ {2,}/', ' ', $clean));
    }
}
