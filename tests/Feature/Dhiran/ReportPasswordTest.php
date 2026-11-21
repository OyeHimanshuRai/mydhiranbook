<?php
namespace Tests\Feature\Dhiran;

use App\Models\Dhiran\DhiranSettings;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ReportPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected bool $reportsUnlocked = false;

    private function owner(): \App\Models\User
    {
        $shop = self::createDhiranShop(['name' => 'Report shop']);
        TenantContext::runFor($shop->id, fn () => DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]));
        return self::userWithRole($shop, ['name' => 'O', 'email' => 'reports@example.test', 'password' => 'Report-pass-12345']);
    }

    /** Owner rule: every report (and its filtered views and CSV exports) asks for the user's own password, valid 1 hour. */
    public function test_every_report_route_is_password_locked(): void
    {
        $reports = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'dhiran.reports.'));
        $this->assertGreaterThanOrEqual(7, $reports->count());
        foreach ($reports as $r) {
            $this->assertContains('report.password', $r->gatherMiddleware(), $r->getName().' is not password-locked');
        }
    }

    public function test_report_shows_a_password_popup_in_place_and_returns_to_the_same_filtered_view(): void
    {
        $owner = $this->owner();
        $url = '/dhiran/reports/cashbook?from=2026-01-01&to=2026-01-31';

        // locked: same URL, a pop-up asking for the password, and none of the report itself
        $this->actingAs($owner)->get($url)->assertOk()->assertSee('Enter your password to open reports')->assertDontSee('Cash Book');
        $this->get('/dhiran/reports/cashbook?export=csv')->assertOk()->assertSee('Enter your password to open reports'); // no CSV either

        $this->from($url)->post(route('password.confirm.store'), ['password' => 'wrong-password-000'])->assertRedirect($url)->assertSessionHasErrors('password');
        $this->get($url)->assertSee('That password is not correct.');

        $this->get($url);
        $this->post(route('password.confirm.store'), ['password' => 'Report-pass-12345'])->assertRedirect(url($url));
        $this->get($url)->assertOk()->assertDontSee('Enter your password to open reports');
        $this->get('/dhiran/reports/overdue')->assertOk()->assertDontSee('Enter your password to open reports'); // one confirmation unlocks all reports

        $this->travel(59)->minutes();
        $this->get($url)->assertDontSee('Enter your password to open reports'); // still open just under the hour
        $this->travel(2)->minutes();
        $this->get($url)->assertSee('Enter your password to open reports');
    }

    public function test_guest_cannot_confirm(): void
    {
        $this->post(route('password.confirm.store'), ['password' => 'x'])->assertRedirect('/login');
    }

    /** A confirmation belongs to the user who gave it: another user in the same session must confirm their own password. */
    public function test_confirmation_does_not_carry_over_to_another_user_in_the_same_session(): void
    {
        $a = $this->owner();
        $b = self::userWithRole($a->shop, ['name' => 'B', 'email' => 'reports-b@example.test', 'password' => 'Other-pass-12345']);
        $this->actingAs($a)->get('/dhiran/reports/overdue');
        $this->post(route('password.confirm.store'), ['password' => 'Report-pass-12345'])->assertSessionHasNoErrors();
        $this->get('/dhiran/reports/overdue')->assertDontSee('Enter your password to open reports');

        $this->actingAs($b)->get('/dhiran/reports/overdue')->assertSee('Enter your password to open reports');
        $this->post(route('password.confirm.store'), ['password' => 'Report-pass-12345'])->assertSessionHasErrors('password'); // A's password doesn't unlock B
    }

    /** List pages stay open, but their CSV exports (whole portfolio / customer book) need the password AND the reports permission. */
    public function test_list_csv_exports_need_the_password_and_the_reports_permission(): void
    {
        $owner = $this->owner();
        foreach (['/dhiran/loans', '/dhiran/borrowers'] as $url) {
            $this->actingAs($owner)->get($url)->assertOk()->assertDontSee('Enter your password to open reports'); // plain list unaffected
        }
        $this->reportsUnlocked = false;
        foreach (['/dhiran/loans?export=csv', '/dhiran/borrowers?export=csv'] as $url) {
            $this->actingAs($owner)->get($url)->assertOk()->assertSee('Enter your password to open reports'); // locked: no CSV
        }
        // a role without dhiran.reports is refused even with a valid confirmation
        $staff = self::userWithRole($owner->shop, ['name' => 'V', 'email' => 'viewer-csv@example.test', 'password' => 'Viewer-pass-12345'], 'viewer');
        $this->actingAs($staff);
        $this->app['session']->put(['auth.password_confirmed_at' => time(), 'auth.password_confirmed_for' => $staff->id]);
        $this->get('/dhiran/loans?export=csv')->assertForbidden();
        $this->get('/dhiran/loans')->assertOk();
    }

    public function test_route_ids_longer_than_bigint_are_not_found_not_a_500(): void
    {
        $owner = $this->owner();
        $this->app['session']->put(['auth.password_confirmed_at' => time(), 'auth.password_confirmed_for' => $owner->id]);
        $this->actingAs($owner)->get('/dhiran/loans/99999999999999999999')->assertNotFound();
    }
}
