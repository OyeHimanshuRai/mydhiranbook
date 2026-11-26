<?php

namespace Tests\Feature\Dhiran;

use App\Models\Dhiran\DhiranSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Live test 2026-11-26: a 22K item (10 g, 0.5 g stone, rate 7000) could not be submitted. The hidden "Appraised value" input kept the
 * unrounded melt value (60958.333...) with step 0.01, and the browser blocked the form silently ("invalid form control is not
 * focusable"). The server only reads market_value for appraised items, so the input is disabled whenever it is hidden.
 * Also: the "Gold rate today" box now feeds every gold item's rate (and new items start with it), because the server values an
 * item only from its own rate; the summary no longer falls back to a rate the server never uses.
 */
class NewLoanFormGuardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_hidden_appraised_value_is_disabled_and_gold_rate_feeds_items(): void
    {
        $shop = self::createDhiranShop(['name' => 'Guard Co']);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);
        $owner = self::userWithRole($shop, ['name' => 'O', 'email' => 'guard@x.test', 'password' => 'Synthetic-pass-12345', 'email_verified_at' => now()]);
        $html = $this->actingAs($owner)->get('/dhiran/create')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/:name="\'items\[\'\+index\+\'\]\[market_value\]\'"[^>]*:disabled="!\(item\.metal_type === \'other\' \|\| item\.value_mode === \'appraised\'\)"/s', $html);
        $this->assertMatchesRegularExpression('/id="gold_rate_on_date"[^>]*@input="applyGoldRate\(\)"/s', $html);
        $this->assertStringContainsString('rate_per_gram_at_pledge: this.goldRateOnDate || 0', $html);
        $this->assertStringNotContainsString('item.rate_per_gram_at_pledge || this.goldRateOnDate', $html);
    }
}
