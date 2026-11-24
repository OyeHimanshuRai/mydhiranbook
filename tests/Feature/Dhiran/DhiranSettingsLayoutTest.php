<?php

namespace Tests\Feature\Dhiran;

use App\Http\Controllers\DhiranController;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Loan settings: five cards from one spacing scale, each with a heading and a one-line description; fields one column on phones,
 * two from 900px. Every control is labelled, wired to its hint/error, and posted to the same endpoint under the same names.
 */
class DhiranSettingsLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_are_six_described_cards_with_labelled_fields(): void
    {
        $shop = self::createDhiranShop([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390012000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390012000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $owner = User::create([
            'mobile_number' => '9390012000', 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id,
        ]);

        $html = TenantContext::runFor($shop->id, function () use ($owner) {
            $this->actingAs($owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);

            return app(DhiranController::class)->settings()->render();
        });

        $board = substr($html, strpos($html, 'data-settings-board'));
        preg_match_all('/<h2 class="st-title"[^>]*>([^<]+)</', $board, $m);
        $this->assertSame(
            // 2026-11-12: the Reminders card is gone; the receipt texts moved to the Receipt page until something actually sends reminders.
            ['Interest', 'Loan value (LTV)', 'Loan limits and fee', 'Tenure and forfeiture', 'Advance interest', 'Loan numbers'],
            array_map('trim', $m[1]),
            'six cards, one heading each'
        );
        $this->assertSame(6, substr_count($board, 'class="st-grid"'), 'each card lays its fields out in one grid (two columns only from 900px, in CSS)');
        $this->assertSame(6, substr_count($board, 'class="st-desc"'), 'each card says in one line what it is for');
        $this->assertStringNotContainsString('max-w-3xl', $html, 'no narrow single column');
        foreach (['default_interest_rate_monthly', 'default_ltv_ratio', 'high_value_threshold', 'processing_fee_value', 'grace_period_days', 'loan_number_prefix'] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $board, "still saves {$field}");
        }

        // Labels, hints and errors are wired: every control has a label[for], numbers carry a keypad hint and a unit, and there is one save button.
        preg_match_all('/<(?:input|select)\b[^>]*\bname="(?!_)[^"]+"[^>]*>/', $board, $controls);
        $this->assertCount(18, $controls[0], 'all sixteen settings plus the two advance-interest controls are posted');
        foreach ($controls[0] as $tag) {
            $this->assertMatchesRegularExpression('/\bid="([^"]+)"/', $tag);
            preg_match('/\bid="([^"]+)"/', $tag, $id);
            $this->assertStringContainsString('for="' . $id[1] . '"', $board, "label for {$id[1]}");
            if (str_contains($tag, 'type="number"')) {
                $this->assertMatchesRegularExpression('/inputmode="(decimal|numeric)"/', $tag, "{$id[1]} opens a number keypad");
            }
        }
        $this->assertStringContainsString('class="st-unit st-unit--pre" aria-hidden="true">₹', $board);
        $this->assertStringContainsString('% per month', $board);
        $this->assertSame(1, substr_count($html, 'type="submit" class="btn btn-dark btn-sm"'), 'one save button');
        $this->assertStringContainsString('Unsaved changes', $html);
        $this->assertStringContainsString('aria-current="page"', $html, 'the settings nav marks the open page');
    }
}
