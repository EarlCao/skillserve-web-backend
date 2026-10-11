<?php

namespace App\Shared\Helpers;

/**
 * QR Ph codes: the Philippine national QR standard (EMV® merchant-presented
 * QR) that GCash, Maya and the banks all scan.
 *
 * A provider's GCash "Receive via QR" code is a QR Ph code. It is a string of
 * tag–length–value fields — `00` format, `01` static/dynamic, `26`–`51` the
 * account, `53` currency, `54` amount, `58` country, `59` name, `60` city —
 * ending in `63`, a CRC-16 over everything before it.
 *
 * This class reads such a code and writes a copy with an amount in it, so a
 * customer scanning it sees the exact amount pre-filled and pays the provider
 * directly. SkillServe never sees the money (ADR-021); it only rewrites the
 * code the provider already published.
 */
final class QrPh
{
    /** ISO 4217 numeric code for the Philippine peso. */
    public const PESO = '608';

    /**
     * The top-level fields of [$payload], keyed by tag, in order — or null when
     * it is not a well-formed, checksum-valid Philippine QR Ph code.
     *
     * @return array<string, string>|null
     */
    public static function parse(string $payload): ?array
    {
        $payload = trim($payload);

        if (strlen($payload) < 20 || strlen($payload) > 512 || ! str_ends_with(substr($payload, 0, -4), '6304')) {
            return null;
        }

        if (strtoupper(substr($payload, -4)) !== self::crc(substr($payload, 0, -4))) {
            return null;
        }

        $fields = [];
        $offset = 0;
        $length = strlen($payload);

        while ($offset < $length) {
            $tag = substr($payload, $offset, 2);
            $size = substr($payload, $offset + 2, 2);

            if (strlen($tag) !== 2 || ! ctype_digit($tag) || ! ctype_digit($size)) {
                return null;
            }

            $value = substr($payload, $offset + 4, (int) $size);
            if (strlen($value) !== (int) $size) {
                return null;
            }

            $fields[$tag] = $value;
            $offset += 4 + (int) $size;
        }

        $hasAccount = collect(array_keys($fields))
            ->contains(fn (string $tag): bool => (int) $tag >= 26 && (int) $tag <= 51);

        $valid = ($fields['00'] ?? null) === '01'
            && $hasAccount
            && ($fields['58'] ?? 'PH') === 'PH'
            && ($fields['53'] ?? self::PESO) === self::PESO;

        return $valid ? $fields : null;
    }

    public static function isValid(string $payload): bool
    {
        return self::parse($payload) !== null;
    }

    /** The account name the code carries (tag 59), or null. */
    public static function name(string $payload): ?string
    {
        $name = self::parse($payload)['59'] ?? null;

        return $name === null || trim($name) === '' ? null : trim($name);
    }

    /**
     * [$payload] with [$amount] pesos set, marked dynamic (`01` = `12`) and
     * re-checksummed. The account fields are copied untouched, so the money
     * still goes exactly where the original code sent it.
     *
     * @throws \InvalidArgumentException when [$payload] is not a valid code
     */
    public static function withAmount(string $payload, float $amount): string
    {
        $fields = self::parse($payload)
            ?? throw new \InvalidArgumentException('Not a valid QR Ph code.');

        unset($fields['63']);
        $fields['01'] = '12';
        $fields['53'] = self::PESO;
        $fields['54'] = number_format(round($amount, 2), 2, '.', '');

        ksort($fields, SORT_STRING);

        $body = '';
        foreach ($fields as $tag => $value) {
            $body .= $tag.str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT).$value;
        }
        $body .= '6304';

        return $body.self::crc($body);
    }

    /** CRC-16/CCITT-FALSE (poly 0x1021, init 0xFFFF), as EMV QR specifies. */
    public static function crc(string $data): string
    {
        $crc = 0xFFFF;

        for ($i = 0, $n = strlen($data); $i < $n; $i++) {
            $crc ^= ord($data[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }

        return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}
