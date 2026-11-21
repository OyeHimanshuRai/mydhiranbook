<?php

namespace Tests\Unit\Ruthless;

use App\Rules\PanFormatRule;
use App\Services\Dhiran\DhiranCsv;
use App\Services\Historical\HistoricalSourceFileReader;
use App\Support\AadhaarMask;
use App\Support\Csv;
use App\Support\Mobile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Adversarial pure-helper tests: mobile, PAN, Aadhaar mask, CSV cell neutralising/round-trip, formula detection. */
class HelpersTest extends TestCase
{
    public static function mobiles(): array
    {
        return [
            'plain'                       => ['9812300099', '9812300099'],
            '+91 with spaces'             => ['+91 98123 00099', '9812300099'],
            '+91-dashes'                  => ['+91-98123-00099', '9812300099'],
            '0091 prefix'                 => ['0091 9812300099', '9812300099'],
            'trunk zero'                  => ['09812300099', '9812300099'],
            'parentheses'                 => ['(98123) 00099', '9812300099'],
            'eleven digits, not a prefix' => ['98123000999', null],   // rule: never trim a typo into a different valid number
            'nine digits'                 => ['981230009', null],
            'starts with 5'               => ['5812300099', null],
            'landline'                    => ['0224567890', null],
            'letters'                     => ['ABC9812300099', null],
            'letters inside'              => ['98123O0099', null],
            'empty'                       => ['', null],
            'whitespace only'             => ['   ', null],
            'plus only'                   => ['+', null],
            'arabic-indic digits'         => ['٩٨١٢٣٠٠٠٩٩', null],    // rule: only ASCII digits are a mobile
            'emoji'                       => ['98123😀00099', null],
            'sql'                         => ["9812300099'; DROP TABLE users;--", null],
            'null byte'                   => ["98123\x0000099", null],
            '91 + 11 digits'              => ['9198123000999', null],
        ];
    }

    #[DataProvider('mobiles')]
    public function test_mobile_normalisation_is_exact(string $input, ?string $expected): void
    {
        // Rule: a mobile is exactly ten digits starting 6-9 after an unambiguous prefix is stripped; else null.
        $this->assertSame($expected, Mobile::normalize($input));
    }

    public static function pans(): array
    {
        return [
            'valid'               => ['ABCDE1234F', true],
            'lowercase'           => ['abcde1234f', true],
            'padded'              => ['  ABCDE1234F  ', true],
            'too short'           => ['ABCDE1234', false],
            'too long'            => ['ABCDE1234FG', false],
            'digits first'        => ['12345ABCDE', false],
            'letter in digit pos' => ['ABCDE12E4F', false],
            'trailing newline'    => ["ABCDE1234F\nX", false],
            'unicode letters'     => ['ÀBCDE1234F', false],
            'empty'               => ['', false],
            'inner space'         => ['ABCD E1234F', false],
        ];
    }

    #[DataProvider('pans')]
    public function test_pan_rule(string $pan, bool $valid): void
    {
        // Rule: 5 letters + 4 digits + 1 letter, case-insensitive, surrounding whitespace ignored.
        $failed = false;
        (new PanFormatRule())->validate('pan', $pan, function () use (&$failed) { $failed = true; });
        $this->assertSame($valid, ! $failed);
    }

    public static function aadhaar(): array
    {
        return [
            'full'        => ['1234 5678 9012', 'XXXX-XXXX-9012'],
            'hyphens'     => ['1234-5678-9012', 'XXXX-XXXX-9012'],
            'already'     => ['XXXX-XXXX-9012', 'XXXX-XXXX-9012'],
            'three digits' => ['123', null],
            'empty'       => ['', null],
            'null'        => [null, null],
            'letters'     => ['abcd', null],
        ];
    }

    #[DataProvider('aadhaar')]
    public function test_aadhaar_never_keeps_more_than_last_four_digits(?string $in, ?string $out): void
    {
        // Rule: only the last 4 digits survive; nothing usable returns null.
        $this->assertSame($out, AadhaarMask::mask($in));
    }

    public static function cells(): array
    {
        return [
            'equals'       => ['=1+1', "'=1+1"],
            'plus formula' => ['+cmd|x', "'+cmd|x"],
            'minus formula' => ["-2+3+cmd|' /C calc'!A0", "'-2+3+cmd|' /C calc'!A0"],
            'at'           => ['@SUM(1)', "'@SUM(1)"],
            'tab'          => ["\tx", "'\tx"],
            'cr'           => ["\rx", "'\rx"],
            'negative number stays a number' => ['-500', '-500'],
            'plain'        => ['Asha', 'Asha'],
            'unicode'      => ['राम 🙂', 'राम 🙂'],
            'null'         => [null, ''],
            'true'         => [true, 'Yes'],
            'float'        => [12.5, '12.5'],
            'leading space then equals is inert' => [' =1', ' =1'],
        ];
    }

    #[DataProvider('cells')]
    public function test_csv_cell_neutralises_formula_leaders(mixed $in, string $out): void
    {
        // Rule: text starting = + - @ TAB CR is prefixed with an apostrophe; numbers and other text pass untouched.
        $this->assertSame($out, DhiranCsv::cell($in));
    }

    public function test_csv_writer_round_trips_backslashes_quotes_newlines_and_unicode(): void
    {
        // Rule: RFC 4180 with no escape char: a value ending in backslash must not swallow its closing quote.
        $row = ['C:\\path\\', 'say "hi"', "line1\nline2", 'राम', 'a,b', ''];
        $h = fopen('php://memory', 'w+');
        Csv::put($h, $row);
        Csv::put($h, ['next', 'row']);
        rewind($h);
        $this->assertSame($row, Csv::get($h));
        $this->assertSame(['next', 'row'], Csv::get($h));
        $this->assertFalse(Csv::get($h));
    }

    public static function formulas(): array
    {
        return [
            ['=cmd', true], ['@x', true], ["\tx", true], ['-1+1', true], ['+SUM(A1)', true], ['-500', false],
            ['+91 98123', false], ['-1,00,000', false], ['plain', false], ['', false],
        ];
    }

    #[DataProvider('formulas')]
    public function test_looks_like_formula(string $v, bool $expected): void
    {
        // Rule: =,@,tab,CR leaders are always formulas; +/- only when what follows is not a plain number.
        $this->assertSame($expected, HistoricalSourceFileReader::looksLikeFormula($v));
    }
}
