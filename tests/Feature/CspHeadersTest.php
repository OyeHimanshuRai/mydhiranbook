<?php
namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** CSP: a small policy is enforced, the full one is report-only until the inline scripts and Alpine expressions are gone (see SecurityHeaders). */
class CspHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_enforce_the_small_policy_and_report_the_full_one(): void
    {
        $r = $this->get('/login')->assertOk();
        $this->assertSame(SecurityHeaders::ENFORCED, $r->headers->get('Content-Security-Policy'));
        $full = $r->headers->get('Content-Security-Policy-Report-Only');
        $this->assertSame(SecurityHeaders::FULL, $full);
        foreach (["default-src 'self'", "object-src 'none'", "frame-ancestors 'none'", 'img-src \'self\' data: blob:', "connect-src 'self'"] as $part) {
            $this->assertStringContainsString($part, $full);
        }
        $this->assertStringNotContainsString('razorpay', strtolower($full)); // the payment gateway is gone: no third-party origin is listed
        $this->assertDoesNotMatchRegularExpression('#https?://#', $full);
    }

    public function test_the_enforced_policy_does_not_touch_the_camera_or_previews(): void
    {
        $this->assertStringNotContainsString('img-src', SecurityHeaders::ENFORCED);
        $this->assertStringNotContainsString('default-src', SecurityHeaders::ENFORCED);
    }
}
