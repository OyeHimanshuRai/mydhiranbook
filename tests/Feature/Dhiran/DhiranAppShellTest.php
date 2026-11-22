<?php

namespace Tests\Feature\Dhiran;

use App\Http\Controllers\DhiranController;
use App\Http\Controllers\DhiranOldLoanController;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Owner 2026-11-22 (phase 2 UI): every signed-in list page has the one app shell (nav drawer/rail/sidebar, skip link) and exactly
 * one footer; collapsible toolbar buttons keep their text in the page (visually hidden) so screen readers still read it.
 */
class DhiranAppShellTest extends TestCase
{
    use RefreshDatabase;

    private function pages(): array
    {
        $shop = self::createDhiranShop([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390007000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390007000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $owner = User::create([
            'mobile_number' => '9390007000', 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id,
        ]);

        return TenantContext::runFor($shop->id, function () use ($owner) {
            $this->actingAs($owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            $out = [];
            foreach ([
                'loans' => [DhiranController::class, 'loans'], 'customers' => [DhiranController::class, 'borrowers'],
                'old loans' => [DhiranOldLoanController::class, 'index'], 'reports hub' => [DhiranController::class, 'reports'],
                'active' => [DhiranController::class, 'reportActive'], 'overdue' => [DhiranController::class, 'reportOverdue'],
                'interest' => [DhiranController::class, 'reportInterest'], 'forfeiture' => [DhiranController::class, 'reportForfeiture'],
                'cashbook' => [DhiranController::class, 'reportCashbook'], 'profitability' => [DhiranController::class, 'reportProfitability'],
            ] as $name => [$class, $method]) {
                $req = Request::create('/dhiran/x', 'GET');
                $this->app->instance('request', $req);
                $out[$name] = app($class)->{$method}($req)->render();
            }

            return $out;
        });
    }

    public function test_every_list_page_has_one_shell_and_exactly_one_footer(): void
    {
        foreach ($this->pages() as $name => $html) {
            $this->assertSame(1, substr_count($html, '<aside class="dh-sidebar"'), "{$name}: one sidebar/drawer");
            $this->assertSame(1, substr_count($html, 'class="dh-skip"'), "{$name}: one skip link");
            $this->assertSame(1, substr_count($html, '<footer class="dh-footer"'), "{$name}: exactly one footer");
            $this->assertSame(1, substr_count($html, 'id="dh-main"'), "{$name}: one main landmark target");
        }
    }

    public function test_collapsible_buttons_keep_their_text_and_a_tooltip(): void
    {
        $found = 0;
        foreach ($this->pages() as $name => $html) {
            preg_match_all('/<(a|button)\b([^>]*\bbtn-collapse\b[^>]*)>(.*?)<\/\1>/s', $html, $m, PREG_SET_ORDER);
            foreach ($m as [, , $attrs, $inner]) {
                $found++;
                $this->assertMatchesRegularExpression('/<span class="btn-text">[^<]+<\/span>/', $inner, "{$name}: the label stays in the DOM");
                $this->assertMatchesRegularExpression('/title="[^"]+"/', $attrs, "{$name}: tooltip");
            }
        }
        $this->assertGreaterThan(10, $found, 'toolbar buttons use the shared collapse pattern');
    }
}
