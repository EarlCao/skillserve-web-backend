<?php

namespace Tests\Feature;

use App\Shared\Helpers\QrPh;
use Tests\TestCase;

/**
 * Writing a booking's amount into a provider's own GCash QR Ph code: the
 * account fields must survive untouched, or the money goes somewhere else.
 */
class QrPhTest extends TestCase
{
    /** A static GCash-style P2P code, as "Receive via QR" produces. */
    public static function staticCode(): string
    {
        $account = '0012com.p2pqrpay'.'0111GXCHPHM2XXX'.'020899964403'.'0315217020000000656';
        $body = '000201'
            .'010211'
            .'27'.str_pad((string) strlen($account), 2, '0', STR_PAD_LEFT).$account
            .'52046016'
            .'5303608'
            .'5802PH'
            .'5914JUAN DELA CRUZ'
            .'6011Quezon City'
            .'6304';

        return $body.QrPh::crc($body);
    }

    public function test_the_checksum_is_crc16_ccitt_false(): void
    {
        // The standard check value for this CRC.
        $this->assertSame('29B1', QrPh::crc('123456789'));
    }

    public function test_a_gcash_code_is_read(): void
    {
        $fields = QrPh::parse(self::staticCode());

        $this->assertNotNull($fields);
        $this->assertSame('11', $fields['01']);
        $this->assertSame('JUAN DELA CRUZ', QrPh::name(self::staticCode()));
    }

    public function test_a_damaged_or_foreign_code_is_refused(): void
    {
        $code = self::staticCode();

        $this->assertFalse(QrPh::isValid(substr($code, 0, -1).'0'), 'bad checksum');
        $this->assertFalse(QrPh::isValid('https://example.com/pay'), 'not EMV');

        $foreign = str_replace('5802PH', '5802SG', substr($code, 0, -4));
        $this->assertFalse(QrPh::isValid($foreign.QrPh::crc($foreign)), 'not Philippine');
    }

    public function test_the_amount_is_written_in_and_the_account_kept(): void
    {
        $original = QrPh::parse(self::staticCode());
        $priced = QrPh::withAmount(self::staticCode(), 200);

        $fields = QrPh::parse($priced);
        $this->assertNotNull($fields, 'still a valid code');
        $this->assertSame('12', $fields['01'], 'marked dynamic');
        $this->assertSame('200.00', $fields['54']);
        $this->assertSame('608', $fields['53']);
        $this->assertSame($original['27'], $fields['27'], 'same account');
        $this->assertSame($original['59'], $fields['59']);

        // The amount sits before the country, as EMV orders the fields.
        $this->assertLessThan(strpos($priced, '5802PH'), strpos($priced, '5406200.00'));
    }

    public function test_writing_a_new_amount_replaces_the_old_one(): void
    {
        $twice = QrPh::withAmount(QrPh::withAmount(self::staticCode(), 200), 1234.5);

        $this->assertSame('1234.50', QrPh::parse($twice)['54']);
        $this->assertSame(1, substr_count($twice, '5407'));
    }
}
