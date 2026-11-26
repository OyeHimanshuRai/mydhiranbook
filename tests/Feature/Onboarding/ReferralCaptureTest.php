<?php
namespace Tests\Feature\Onboarding;

use App\Http\Controllers\DhiranOnboardingController;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OnboardingFixtures;
use Tests\TestCase;

/** /register?ref=CODE is remembered until the business form; the form turns it (or typed text) into the shop's referrer. */
class ReferralCaptureTest extends TestCase
{
    use RefreshDatabase, OnboardingFixtures;

    private function send(array $over = []): Shop
    {
        $owner = $this->newOwner();
        $this->actingAs($owner)->post(route('dhiran.onboarding.store'), $over + ['name' => 'Referred Co '.$owner->id, 'phone' => '9876543210', 'address' => 'Main Road'])->assertRedirect(route('dhiran.pending'));

        return Shop::where('name', 'Referred Co '.$owner->id)->sole();
    }

    public function test_every_shop_gets_a_unique_code_when_created(): void
    {
        $a = Shop::create(['name' => 'A']); $b = Shop::create(['name' => 'B']);
        $this->assertMatchesRegularExpression('/^MDB[0-9A-Z]{5}$/', $a->fresh()->referral_code);
        $this->assertNotSame($a->fresh()->referral_code, $b->fresh()->referral_code);
    }

    public function test_a_clean_ref_is_kept_in_the_session_upper_cased(): void
    {
        $this->get('/register?ref=mdb0001a')->assertOk()->assertSessionHas('referral_ref', 'MDB0001A');
    }

    public function test_junk_ref_is_ignored(): void
    {
        foreach (['ab', '<script>x</script>', 'MDB 0001', str_repeat('A', 13), 'MDB-001'] as $junk) {
            $this->get('/register?'.http_build_query(['ref' => $junk]))->assertOk()->assertSessionMissing('referral_ref');
        }
        $this->get('/register?ref[]=MDB0001A')->assertOk()->assertSessionMissing('referral_ref');
    }

    public function test_the_business_form_is_prefilled_from_the_session_and_has_no_required_marker(): void
    {
        $this->actingAs($this->newOwner())->withSession(['referral_ref' => 'MDB0001A'])->get(route('dhiran.onboarding'))->assertOk()
            ->assertSee('Who told you about MyDhiranBook?')->assertSee('name="referred_by"', false)->assertSee('value="MDB0001A"', false);
    }

    public function test_a_shop_code_matches_case_insensitively_and_links_the_referrer(): void
    {
        $referrer = Shop::create(['name' => 'Referrer']);
        $shop = $this->send(['referred_by' => strtolower($referrer->fresh()->referral_code)]);
        $this->assertSame($referrer->id, $shop->referred_by_shop_id);
        $this->assertNull($shop->referred_by_text);
    }

    public function test_the_session_ref_is_cleared_after_sending(): void
    {
        $referrer = Shop::create(['name' => 'Referrer']);
        $this->withSession(['referral_ref' => $referrer->fresh()->referral_code]);
        $this->send(['referred_by' => $referrer->fresh()->referral_code]);
        $this->assertNull(session('referral_ref'));
    }

    public function test_a_shops_own_code_is_ignored(): void
    {
        $shop = Shop::create(['name' => 'Self']);
        $m = new \ReflectionMethod(DhiranOnboardingController::class, 'rememberReferrer');
        $m->invoke(app(DhiranOnboardingController::class), $shop, strtolower($shop->fresh()->referral_code));
        $shop->refresh();
        $this->assertNull($shop->referred_by_shop_id);
        $this->assertNull($shop->referred_by_text);
    }

    public function test_anything_else_is_stored_as_trimmed_text_and_shown_escaped(): void
    {
        $shop = $this->send(['referred_by' => '  <b>Ramesh</b> 98765  ']);
        $this->assertNull($shop->referred_by_shop_id);
        $this->assertSame('<b>Ramesh</b> 98765', $shop->referred_by_text);
        $this->assertStringContainsString('&lt;b&gt;Ramesh&lt;/b&gt;', e($shop->referred_by_text));
    }

    public function test_an_unknown_code_shaped_value_is_kept_as_text(): void
    {
        $shop = $this->send(['referred_by' => 'ZZZ99999']);
        $this->assertNull($shop->referred_by_shop_id);
        $this->assertSame('ZZZ99999', $shop->referred_by_text);
    }

    public function test_too_long_text_is_refused_and_empty_is_fine(): void
    {
        $this->actingAs($this->newOwner())->post(route('dhiran.onboarding.store'), ['name' => 'Long Co', 'phone' => '9876543210', 'address' => 'Main Road', 'referred_by' => str_repeat('x', 81)])->assertSessionHasErrors('referred_by');
        $this->assertDatabaseCount('shops', 0);
        $shop = $this->send(['referred_by' => '']);
        $this->assertNull($shop->referred_by_shop_id);
        $this->assertNull($shop->referred_by_text);
    }

    public function test_sending_the_details_again_never_changes_the_referral(): void
    {
        $referrer = Shop::create(['name' => 'Referrer']);
        $shop = $this->send(['referred_by' => $referrer->fresh()->referral_code]);
        $shop->update(['status' => 'rejected', 'review_note' => 'Fix phone', 'reviewed_at' => now()]);
        $owner = \App\Models\User::where('shop_id', $shop->id)->sole();
        $this->actingAs($owner)->post(route('dhiran.resubmit'), ['name' => 'Fixed Co', 'phone' => '9876500099', 'address' => 'New Road', 'referred_by' => 'Someone else'])->assertRedirect(route('dhiran.pending'));
        $shop->refresh();
        $this->assertSame('Fixed Co', $shop->name);
        $this->assertSame($referrer->id, $shop->referred_by_shop_id);
        $this->assertNull($shop->referred_by_text);
        $this->actingAs($owner->fresh())->get(route('dhiran.pending'))->assertOk()->assertDontSee('name="referred_by"', false);
    }
}
