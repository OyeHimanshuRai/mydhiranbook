<?php
namespace Tests\Feature\Billing;

use App\Jobs\SendPlatformInvoiceEmail;
use App\Mail\PlatformInvoiceMail;
use App\Models\Platform\PlatformInvoice;
use App\Models\Platform\ShopSubscription;
use App\Models\User;
use App\Services\Billing\SubscriptionPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\BillingFixtures;
use Tests\TestCase;

class ReceiptTest extends TestCase
{
    use RefreshDatabase, BillingFixtures;

    protected function setUp(): void { parent::setUp(); $this->setUpBilling(); config(['queue.default' => 'sync', 'queue.connections.sync' => ['driver' => 'sync']]); }

    private function pay(User $user, string $plan = 'dhiran_yearly'): PlatformInvoice
    {
        $triple = $this->paidOrder($user, $this->plan($plan));
        $sub = app(SubscriptionPaymentService::class)->finalizeCapturedPayment($triple['razorpay_order_id'], $triple['razorpay_payment_id']);
        return PlatformInvoice::where('shop_subscription_id', $sub->id)->sole();
    }

    public function test_receipt_without_gst_has_zero_tax_and_total_equals_price(): void
    {
        Mail::fake();
        $inv = $this->pay($this->shopUser());
        $this->assertSame('0.00', $inv->gst_amount);
        $this->assertSame('0.00', $inv->gst_rate);
        $this->assertSame('19999.00', $inv->amount_before_tax);
        $this->assertSame('19999.00', $inv->total_amount);
        $this->assertSame('Payment Receipt', $inv->documentTitle());
        $this->assertSame(1, PlatformInvoice::count());
        $user = $inv->subscription->user;
        $html = $this->actingAs($user)->get(route('subscription.receipt', $inv))->assertOk()->getContent();
        $this->assertStringContainsString('Payment Receipt', $html);
        $this->assertStringNotContainsString('GST', $html);
    }

    public function test_gst_switch_on_back_calculates_18_percent(): void
    {
        Mail::fake();
        config(['billing.gst_registered' => true]);
        $inv = $this->pay($this->shopUser());
        $this->assertSame('16948.31', $inv->amount_before_tax);
        $this->assertSame('3050.69', $inv->gst_amount);
        $this->assertSame('18.00', $inv->gst_rate);
        $this->assertSame('19999.00', $inv->total_amount);
        $this->assertSame('Tax Invoice', $inv->documentTitle());
    }

    public function test_receipt_email_queued_after_commit_only(): void
    {
        Mail::fake();
        $user = $this->shopUser();
        $triple = $this->paidOrder($user);
        try {
            DB::transaction(function () use ($triple) {
                app(SubscriptionPaymentService::class)->finalizeCapturedPayment($triple['razorpay_order_id'], $triple['razorpay_payment_id']);
                Mail::assertNothingSent();
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {}
        Mail::assertNothingSent();
        $this->assertSame(0, PlatformInvoice::count());

        $this->pay($user);
        Mail::assertSent(PlatformInvoiceMail::class, fn ($m) => $m->hasTo($user->email));
        Mail::assertSent(PlatformInvoiceMail::class, 1);
        $this->assertTrue((new SendPlatformInvoiceEmail(1))->afterCommit);
    }

    public function test_payer_without_email_is_skipped_without_exception(): void
    {
        Mail::fake();
        $user = $this->shopUser();
        $user->forceFill(['email' => ''])->save();
        $this->pay($user);
        Mail::assertNothingSent();
    }

    public function test_receipt_numbers_are_sequential_and_unique(): void
    {
        Mail::fake();
        $a = $this->pay($this->shopUser()); $b = $this->pay($this->shoplessUser()); $c = $this->pay($this->shopUser(), 'dhiran_monthly');
        $y = now()->year;
        $this->assertSame(["DHR-$y-000001", "DHR-$y-000002", "DHR-$y-000003"], [$a->invoice_number, $b->invoice_number, $c->invoice_number]);
        $this->assertSame(3, PlatformInvoice::distinct('invoice_number')->count());
    }

    public function test_second_receipt_for_same_subscription_is_never_issued(): void
    {
        Mail::fake();
        $inv = $this->pay($this->shopUser());
        $again = app(\App\Services\Billing\PlatformInvoiceService::class)->issueForSubscription($inv->subscription);
        $this->assertSame($inv->id, $again->id);
        $this->assertSame(1, PlatformInvoice::count());
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('platform_invoices')->insert(array_merge(collect($inv->getAttributes())->except('id')->all(), ['invoice_number' => 'X-1']));
    }

    public function test_owner_cannot_view_other_shops_receipt(): void
    {
        Mail::fake();
        $inv = $this->pay($this->shopUser());
        $this->actingAs($this->shopUser())->get(route('subscription.receipt', $inv))->assertNotFound();
        $this->actingAs($this->shoplessUser())->get(route('subscription.receipt', $inv))->assertNotFound();
    }

    public function test_shopless_payer_sees_own_receipt_but_not_anothers(): void
    {
        Mail::fake();
        $mine = $this->shoplessUser(); $other = $this->shoplessUser();
        $inv = $this->pay($mine);
        $this->actingAs($mine)->get(route('subscription.receipt', $inv))->assertOk();
        $this->actingAs($other)->get(route('subscription.receipt', $inv))->assertNotFound();
        $this->actingAs($mine)->get(route('subscription.status'))->assertSee($inv->invoice_number);
    }

    public function test_receipt_page_carries_no_signature_data(): void
    {
        Mail::fake();
        $user = $this->shopUser(); $inv = $this->pay($user);
        $html = $this->actingAs($user)->get(route('subscription.receipt', $inv))->getContent();
        $this->assertStringContainsString($inv->razorpay_payment_id, $html);
        $this->assertStringNotContainsStringIgnoringCase('signature', $html);
    }
}
