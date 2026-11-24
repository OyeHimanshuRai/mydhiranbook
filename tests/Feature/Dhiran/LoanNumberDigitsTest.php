<?php

namespace Tests\Feature\Dhiran;

use App\Models\Customer;
use App\Models\Dhiran\DhiranSettings;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A loan number is the prefix plus the sequence padded to the shop's chosen length (6 = DH-000001, 1 = DH-1). */
class LoanNumberDigitsTest extends TestCase
{
    use RefreshDatabase;

    private function numbers(int $digits, int $howMany): array
    {
        $shop = self::createDhiranShop(['name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390025000', 'owner_first_name' => 'O', 'owner_last_name' => 'W',
            'owner_mobile' => '9390025000', 'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true, 'loan_number_digits' => $digits, 'default_min_lock_months' => 0, 'processing_fee_value' => 0]);
        $svc = app(DhiranService::class);

        return TenantContext::runFor($shop->id, function () use ($shop, $svc, $howMany) {
            $c = Customer::create(['shop_id' => $shop->id, 'first_name' => 'Num', 'last_name' => 'Ber', 'mobile' => '9815640001']);
            $out = [];
            for ($i = 0; $i < $howMany; $i++) {
                $out[] = $svc->createLoan($shop, $c, [['description' => 'Chain', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 20, 'rate_per_gram_at_pledge' => 6000]],
                    ['principal_amount' => 10000, 'gold_rate_on_date' => 6000, 'created_by' => null])->loan_number;
            }

            return $out;
        });
    }

    public function test_the_default_is_six_digits_as_before(): void
    {
        $this->assertSame(['DH-000001', 'DH-000002'], $this->numbers(6, 2));
    }

    public function test_one_digit_means_no_leading_zeros_and_grows_naturally(): void
    {
        $n = $this->numbers(1, 11);
        $this->assertSame('DH-1', $n[0]);
        $this->assertSame('DH-9', $n[8]);
        $this->assertSame('DH-11', $n[10]);
    }

    public function test_four_digits(): void
    {
        $this->assertSame(['DH-0001', 'DH-0002'], $this->numbers(4, 2));
    }
}
