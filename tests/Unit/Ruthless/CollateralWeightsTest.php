<?php

namespace Tests\Unit\Ruthless;

use App\Services\Dhiran\DhiranCollateral;
use App\Services\MetalRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Net weight, fine (pure metal) weight and purity limits of a pledged item: the single source of truth for the
 * New Loan page (whose JavaScript mirrors these) and for the server. No database rows.
 */
class CollateralWeightsTest extends TestCase
{
    // ── Net weight = gross - stone, never negative, 6 dp ────────────────────

    #[DataProvider('nets')]
    public function test_net_weight(float $gross, float $stone, float $expected): void
    {
        $this->assertSame($expected, MetalRegistry::netWeight($gross, $stone));
    }

    public static function nets(): array
    {
        return [
            'plain'                      => [10.5, 0.5, 10.0],
            'no stone'                   => [12.345, 0.0, 12.345],
            'float noise is rounded off' => [0.3, 0.1, 0.2],
            'stone equals gross'         => [5.0, 5.0, 0.0],
            'stone above gross is zero'  => [5.0, 5.001, 0.0],
            'six places kept'            => [10.1234567, 0.0, 10.123457],
        ];
    }

    // ── Fine weight per metal and standard purity ───────────────────────────

    #[DataProvider('fines')]
    public function test_fine_weight_of_standard_purities(string $metal, float $purity, float $net, float $expected): void
    {
        $this->assertSame($expected, MetalRegistry::fineWeight($metal, $net, $purity));
    }

    public static function fines(): array
    {
        return [
            'gold 24K 100 g'  => ['gold', 24, 100, 100.0],
            'gold 23K 100 g'  => ['gold', 23, 100, 95.833333],
            'gold 22K 100 g'  => ['gold', 22, 100, 91.666667],
            'gold 21K 100 g'  => ['gold', 21, 100, 87.5],
            'gold 20K 100 g'  => ['gold', 20, 100, 83.333333],
            'gold 18K 100 g'  => ['gold', 18, 100, 75.0],
            'gold 14K 100 g'  => ['gold', 14, 100, 58.333333],
            'gold 9K 10 g'    => ['gold', 9, 10, 3.75],
            'gold 22K 0.001'  => ['gold', 22, 0.001, 0.000917],
            'silver 999'      => ['silver', 999, 100, 99.9],
            'silver 970'      => ['silver', 970, 100, 97.0],
            'silver 925'      => ['silver', 925, 100, 92.5],
            'silver 900'      => ['silver', 900, 100, 90.0],
            'silver 835'      => ['silver', 835, 100, 83.5],
            'silver 800'      => ['silver', 800, 100, 80.0],
            'silver 925 odd'  => ['silver', 925, 12.345, 11.419125],
        ];
    }

    public function test_gold_fineness_is_karat_over_24_in_one_place(): void
    {
        $this->assertEqualsWithDelta(22 / 24, MetalRegistry::goldFineness(22), 1e-15);
        $this->assertEqualsWithDelta(MetalRegistry::goldFineness(22), MetalRegistry::fineWeightMultiplier('gold', 22), 1e-15);
    }

    // ── Standard purities (picker) ──────────────────────────────────────────

    public function test_standard_purities_with_hallmark_fineness(): void
    {
        $presets = MetalRegistry::PURITY_PRESETS;
        $this->assertSame([24 => 999, 23 => 958, 22 => 916, 21 => 875, 20 => 833, 18 => 750, 14 => 585, 9 => 375], $presets['gold']);
        $this->assertSame([999 => 999, 970 => 970, 925 => 925, 900 => 900, 835 => 835, 800 => 800], $presets['silver']);
    }

    public function test_preset_table_for_the_page_carries_the_server_multiplier(): void
    {
        $table = MetalRegistry::purityPresetTable();
        $this->assertSame(['gold', 'silver'], array_keys($table));
        foreach ($table as $metal => $rows) {
            foreach ($rows as $row) {
                $this->assertSame(MetalRegistry::fineWeightMultiplier($metal, $row['value']), $row['multiplier']);
            }
        }
        $this->assertSame(['value' => 22, 'label' => '22K (916)', 'multiplier' => 22 / 24], $table['gold'][2]);
        $this->assertSame(['value' => 925, 'label' => '925', 'multiplier' => 0.925], $table['silver'][2]);
    }

    // ── Purity bounds per metal ─────────────────────────────────────────────

    #[DataProvider('bounds')]
    public function test_purity_bounds(string $metal, float $purity, bool $ok): void
    {
        $this->assertSame($ok, MetalRegistry::purityInRange($metal, $purity));
    }

    public static function bounds(): array
    {
        return [
            'gold 1K'            => ['gold', 1, true],
            'gold 22.5K'         => ['gold', 22.5, true],
            'gold 24K'           => ['gold', 24, true],
            'gold 0'             => ['gold', 0, false],
            'gold above 24'      => ['gold', 24.01, false],
            'gold silver value'  => ['gold', 925, false],
            'gold 3 decimals'    => ['gold', 22.123, false],
            'silver 100'         => ['silver', 100, true],
            'silver 925.5'       => ['silver', 925.5, true],
            'silver 999.99'      => ['silver', 999.99, true],
            'silver karat value' => ['silver', 22, false],
            'silver below 100'   => ['silver', 99.99, false],
            'silver 1000'        => ['silver', 1000, false],
            'silver 3 decimals'  => ['silver', 925.123, false],
        ];
    }

    // ── The server refuses what the card refuses ────────────────────────────

    private function item(array $over = []): array
    {
        return $over + ['metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 10, 'stone_weight' => 0, 'rate_per_gram_at_pledge' => 6000];
    }

    public function test_server_accepts_standard_and_custom_purities(): void
    {
        foreach ([['gold', 23], ['gold', 21], ['gold', 9], ['gold', 22.5], ['silver', 970], ['silver', 835], ['silver', 925.5]] as [$metal, $purity]) {
            $this->assertSame([], DhiranCollateral::errors([$this->item(['metal_type' => $metal, 'purity' => $purity])]), "$metal $purity");
        }
    }

    public function test_server_refuses_purity_out_of_range_for_the_metal(): void
    {
        foreach ([['gold', 925], ['gold', 0], ['gold', 22.123], ['silver', 22], ['silver', 1000], ['silver', 99]] as [$metal, $purity]) {
            $this->assertArrayHasKey('items.0.purity', DhiranCollateral::errors([$this->item(['metal_type' => $metal, 'purity' => $purity])]), "$metal $purity");
        }
    }

    public function test_server_refuses_stone_at_or_above_gross(): void
    {
        $this->assertArrayHasKey('items.0.stone_weight', DhiranCollateral::errors([$this->item(['stone_weight' => 10.001])]));
        $this->assertArrayHasKey('items.0.stone_weight', DhiranCollateral::errors([$this->item(['stone_weight' => 10])]));
        $this->assertSame([], DhiranCollateral::errors([$this->item(['stone_weight' => 9.999])]));
    }

    public function test_appraised_items_have_weights_optional_but_checked_when_given(): void
    {
        // Rule: a typed value needs no weights; when gross is given, stone and purity are checked like a melt-valued item.
        $this->assertSame([], DhiranCollateral::errors([['metal_type' => 'gold', 'value_mode' => 'appraised', 'market_value' => 500]]));
        $this->assertSame([], DhiranCollateral::errors([['metal_type' => 'other', 'market_value' => 500]]));
        $this->assertSame([], DhiranCollateral::errors([['metal_type' => 'silver', 'value_mode' => 'appraised', 'market_value' => 500, 'purity' => 925, 'gross_weight' => 10, 'stone_weight' => 1]]));
        $this->assertSame([], DhiranCollateral::errors([['metal_type' => 'other', 'market_value' => 500, 'gross_weight' => 10, 'stone_weight' => 1]]));
        $bad = DhiranCollateral::errors([['metal_type' => 'gold', 'value_mode' => 'appraised', 'market_value' => 500, 'purity' => 925, 'gross_weight' => 1, 'stone_weight' => 9]]);
        $this->assertArrayHasKey('items.0.purity', $bad);
        $this->assertArrayHasKey('items.0.stone_weight', $bad);
        $this->assertArrayHasKey('items.0.stone_weight', DhiranCollateral::errors([['metal_type' => 'other', 'market_value' => 500, 'gross_weight' => 1, 'stone_weight' => 1]]));
        $this->assertArrayHasKey('items.0.market_value', DhiranCollateral::errors([['metal_type' => 'gold', 'value_mode' => 'appraised', 'gross_weight' => 10, 'purity' => 22]]));
    }
}
