<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace MauticPlugin\IntellectITBotFilterBundle\Helper;

/**
 * Replaces byte sequences that are not valid UTF-8 so a value can be stored in a
 * utf8mb4 column.
 *
 * Why this exists: when MySQL runs with STRICT_TRANS_TABLES (the default sql_mode), an
 * invalid sequence is an ERROR (1366 Incorrect string value), not a truncating
 * warning. Anything client-controlled that reaches a utf8mb4 column can therefore
 * abort the INSERT and, through Doctrine, close the EntityManager for the rest of
 * the request.
 *
 * Invalid sequences are replaced with U+FFFD rather than the whole value being
 * dropped, so a mostly-ASCII value that carries a few legacy-encoded bytes (a
 * latin-1 form field, say) still arrives readable. A pure binary payload
 * degrades to a short run of replacement characters, which is the intended
 * outcome: the row is recorded instead of lost.
 *
 * NUL is handled too, and it is a SEPARATE failure with a separate cause. NUL is
 * perfectly valid UTF-8 (U+0000), so an encoding check alone lets it through — but
 * `Mautic\CoreBundle\Doctrine\Type\ArrayType::convertToDatabaseValue()` throws a
 * ConversionException for any serialized array containing `chr(0)`, i.e. before the
 * query ever reaches MySQL. Two different layers reject two different byte classes,
 * and a payload can carry both; sanitising for only one still loses the row.
 */
final class Utf8Sanitiser
{
    /**
     * mb_substitute_character() code point for U+FFFD REPLACEMENT CHARACTER.
     */
    private const SUBSTITUTE = 0xFFFD;

    /**
     * The same character as SUBSTITUTE, as a UTF-8 string, for the NUL replacement
     * that does not go through mbstring.
     */
    private const REPLACEMENT = "\u{FFFD}";

    public static function isValid(string $value): bool
    {
        return mb_check_encoding($value, 'UTF-8');
    }

    /**
     * @param int|null $maxChars Truncate to this many CHARACTERS after sanitising.
     *                           Pass the column's CHARACTER_MAXIMUM_LENGTH for a
     *                           varchar: U+FFFD is three bytes, so replacing bytes
     *                           can lengthen a value, and an over-long value is
     *                           error 1406 under STRICT_TRANS_TABLES — the same
     *                           class of failure this helper exists to prevent.
     */
    public static function sanitiseString(string $value, ?int $maxChars = null): string
    {
        // NUL first, and not for encoding reasons: it is valid UTF-8, so
        // mb_check_encoding() returns true and the block below would leave it in
        // place, after which Mautic's ArrayType refuses the whole array.
        if (str_contains($value, "\0")) {
            $value = str_replace("\0", self::REPLACEMENT, $value);
        }

        if (!mb_check_encoding($value, 'UTF-8')) {
            $previous = mb_substitute_character();
            mb_substitute_character(self::SUBSTITUTE);

            try {
                // Converting UTF-8 to UTF-8 is the documented idiom for replacing
                // invalid sequences with the substitute character.
                $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            } finally {
                mb_substitute_character($previous);
            }
        }

        if (null !== $maxChars && mb_strlen($value, 'UTF-8') > $maxChars) {
            $value = mb_substr($value, 0, $maxChars, 'UTF-8');
        }

        return $value;
    }

    /**
     * Sanitises strings anywhere inside a scalar, list or nested map.
     *
     * Array KEYS are sanitised too, because a query-string parameter name is as
     * client-controlled as its value. Two keys that differ only in their invalid
     * bytes therefore collapse into one and the later value wins — accepted,
     * since the alternative is losing the whole row.
     *
     * $maxChars applies to string VALUES only, never to keys.
     */
    public static function sanitise(mixed $value, ?int $maxChars = null): mixed
    {
        if (is_string($value)) {
            return self::sanitiseString($value, $maxChars);
        }

        if (!is_array($value)) {
            return $value;
        }

        $clean = [];
        foreach ($value as $key => $item) {
            $cleanKey         = is_string($key) ? self::sanitiseString($key) : $key;
            $clean[$cleanKey] = self::sanitise($item, $maxChars);
        }

        return $clean;
    }
}
