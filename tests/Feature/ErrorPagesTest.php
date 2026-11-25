<?php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\OnboardingFixtures;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase, OnboardingFixtures;

    private const TITLES = [
        403 => 'You do not have access to this page',
        419 => 'This page expired',
        429 => 'Too many tries',
        500 => 'Something went wrong on our side',
        503 => 'We are updating MyDhiranBook',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([401, 403, 419, 429, 500, 503] as $code) {
            Route::get("/_e/$code", fn () => abort($code));
        }
        Route::get('/_e/secret-500', fn () => abort(500, 'SQLSTATE secret'));
        Route::get('/_e/secret-403', fn () => abort(403, 'SQLSTATE secret'));
        Route::get('/_e/403-ended', fn () => abort(403, 'Access ended'));
    }

    public function test_each_error_shows_a_friendly_page(): void
    {
        foreach (self::TITLES as $code => $title) {
            $this->get("/_e/$code")->assertStatus($code)->assertSee($title)
                ->assertDontSee('Whoops')->assertDontSee('HttpException')->assertDontSee('Symfony');
        }
        $this->get('/_e/401')->assertStatus(401)->assertSee('Please sign in');
    }

    public function test_unknown_url_shows_the_404_page(): void
    {
        $this->get('/no-such-page-here')->assertNotFound()->assertSee('We cannot find that page')->assertDontSee('Whoops');
    }

    public function test_custom_abort_message_is_not_shown(): void
    {
        $this->get('/_e/secret-500')->assertStatus(500)->assertDontSee('SQLSTATE')->assertSee('Something went wrong on our side');
        $this->get('/_e/secret-403')->assertForbidden()->assertDontSee('SQLSTATE');
    }

    public function test_allowlisted_403_message_is_shown_as_a_friendly_sentence(): void
    {
        $this->get('/_e/403-ended')->assertForbidden()->assertSee('Your access to MyDhiranBook has ended.')->assertDontSee('Access ended');
    }

    public function test_guest_sees_sign_in_and_signed_in_user_sees_dashboard_link(): void
    {
        $this->get('/_e/403')->assertSee('Sign in')->assertDontSee('Go to my dashboard');
        $this->actingAs($this->newOwner())->get('/_e/403')->assertSee('Go to my dashboard')->assertDontSee('Sign in');
    }
}
