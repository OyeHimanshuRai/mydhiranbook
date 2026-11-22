<?php

namespace Tests\Feature\Dhiran;

use App\Http\Controllers\DhiranController;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Owner 2026-11-22: the signed-in app is a fixed-height shell. List pages opt in with <x-dhiran-layout list> (title row and toolbar
 * stay put, only the list scrolls); the legal footer exists only on the four Settings pages (<x-dhiran-layout footer>).
 */
class AppShellScrollTest extends TestCase
{
    use RefreshDatabase;

    private const LIST_VIEWS = [
        'dhiran/loans', 'dhiran/borrowers/index', 'dhiran/old-loans/index', 'dhiran/reports/active', 'dhiran/reports/overdue',
        'dhiran/reports/interest', 'dhiran/reports/forfeiture', 'dhiran/reports/profitability', 'dhiran/reports/cashbook',
    ];

    private const SETTINGS_VIEWS = ['dhiran/settings', 'dhiran/receipt-settings', 'staff/settings', 'subscription/status'];

    public function test_list_views_opt_in_and_only_settings_views_ask_for_the_footer(): void
    {
        foreach (self::LIST_VIEWS as $view) {
            $this->assertMatchesRegularExpression('/<x-dhiran-layout [^>]*\blist>/', file_get_contents(resource_path("views/$view.blade.php")), "$view opts in to the list layout");
        }
        foreach (self::SETTINGS_VIEWS as $view) {
            $this->assertMatchesRegularExpression('/<x-(?:dhiran-layout|subscription-page) [^>]*\bfooter>/', file_get_contents(resource_path("views/$view.blade.php")), "$view shows the footer");
        }
        $layout = file_get_contents(resource_path('views/components/dhiran-layout.blade.php'));
        $this->assertStringContainsString('@if($footer)<x-dhiran.footer />@endif', $layout);
        $this->assertStringNotContainsString('<x-dhiran.footer />', str_replace('@if($footer)<x-dhiran.footer />@endif', '', $layout), 'the footer is never drawn unconditionally');
    }

    public function test_list_page_carries_the_list_class_and_no_footer_while_settings_has_exactly_one(): void
    {
        $shop = self::createDhiranShop([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9390013000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390013000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);
        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $shop->id])->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));
        $owner = User::create([
            'mobile_number' => '9390013000', 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'email_verified_at' => now(), 'shop_id' => $shop->id, 'role_id' => $role->id,
        ]);

        [$loans, $settings] = TenantContext::runFor($shop->id, function () use ($owner) {
            $this->actingAs($owner);
            view()->share('errors', new \Illuminate\Support\ViewErrorBag);
            $req = Request::create('/dhiran/loans', 'GET');
            $this->app->instance('request', $req);

            return [app(DhiranController::class)->loans($req)->render(), app(DhiranController::class)->settings()->render()];
        });

        $this->assertStringContainsString('<body class="dh-app dh-page--list">', $loans);
        $this->assertSame(0, substr_count($loans, '<footer class="dh-footer"'));
        $this->assertStringContainsString('<body class="dh-app">', $settings);
        $this->assertSame(1, substr_count($settings, '<footer class="dh-footer"'));
    }
}
