<?php
namespace Tests\Feature;

use App\Models\Platform\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OnboardingFixtures;
use Tests\TestCase;

class SiteTest extends TestCase
{
    use RefreshDatabase, OnboardingFixtures;

    private const PAGES = ['/', '/pricing', '/terms', '/privacy', '/refund', '/contact'];

    public function test_public_pages_render_without_login(): void
    {
        (new PlanSeeder)->run();
        foreach (self::PAGES as $p) { $this->get($p)->assertOk(); }
    }

    public function test_pricing_shows_only_active_plans_with_prices(): void
    {
        (new PlanSeeder)->run();
        Plan::create(['code' => 'old_plan', 'name' => 'Retired Plan', 'price_monthly' => 999, 'price_yearly' => null, 'grace_days' => 1, 'is_active' => false, 'features' => ['staff_limit' => 1, 'max_items' => 1]]);
        $this->get('/pricing')->assertOk()
            ->assertSee('Dhiran Monthly')->assertSee('₹1,852')->assertSee('/ month', false)
            ->assertSee('Dhiran Yearly')->assertSee('₹19,999')->assertSee('/ year', false)
            ->assertSee('No free trial')->assertSee('Prices in INR')
            ->assertDontSee('Retired Plan')->assertDontSee('GST');
    }

    public function test_footer_links_on_register_and_checkout(): void
    {
        (new PlanSeeder)->run();
        $owner = $this->newOwner();
        $pages = [$this->get('/register'), $this->get('/login'), $this->actingAs($owner)->get(route('dhiran.plans'))];
        foreach ($pages as $r) {
            $r->assertOk();
            foreach (['/pricing', '/terms', '/privacy', '/refund', '/contact'] as $href) { $r->assertSee('href="'.$href.'"', false); }
        }
        $this->get('/register')->assertSee('href="/terms"', false)->assertSee('href="/privacy"', false);
    }

    public function test_no_jewelflow_text_on_public_pages(): void
    {
        (new PlanSeeder)->run();
        foreach (array_merge(self::PAGES, ['/register', '/login']) as $p) {
            $this->assertDoesNotMatchRegularExpression('/jewelflow/i', $this->get($p)->getContent(), $p);
        }
    }

    public function test_guest_root_is_home_and_signed_in_root_goes_to_app(): void
    {
        $this->get('/')->assertOk()->assertSee('Get started');
        $this->actingAs($this->newOwner())->get('/')->assertRedirect(route('dhiran.dashboard'));
        // shopless owner still lands in the onboarding chain
        $this->actingAs($this->newOwner())->followingRedirects()->get('/')->assertSee('Choose your Dhiran plan');
    }

    public function test_footer_is_styled_on_shop_layout_pages(): void
    {
        $this->get('/register')->assertSee('site-', false);
        $this->get('/pricing')->assertSee('build/assets/site-', false);
        $this->get('/register')->assertSee('build/assets/site-', false);
    }
}
