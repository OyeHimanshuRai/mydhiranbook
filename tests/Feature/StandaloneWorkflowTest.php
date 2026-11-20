<?php
namespace Tests\Feature;
use App\Models\Customer;
use App\Models\Shop;
use App\Models\User;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranPayment;
use App\Services\DhiranService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
class StandaloneWorkflowTest extends TestCase
{
    use RefreshDatabase;
    private function owner(string $suffix='one', string $role='owner'): User
    {
        $shop=Shop::create(['name'=>'Synthetic shop '.$suffix]);
        return User::create(['name'=>'Synthetic Owner','email'=>$suffix.'@example.test','password'=>'Local-pass-12345','shop_id'=>$shop->id,'role'=>$role]);
    }
    private function loan(User $owner): DhiranLoan
    {
        return TenantContext::runFor($owner->shop_id, function() use($owner) {
            $c=Customer::create(['first_name'=>'Synthetic','last_name'=>'Borrower','mobile'=>'9000000001']);
            return app(DhiranService::class)->createLoan($owner->shop,$c,[['description'=>'Synthetic gold chain','metal_type'=>'gold','purity'=>24,'gross_weight'=>20,'rate_per_gram_at_pledge'=>6000]],['principal_amount'=>50000,'gold_rate_on_date'=>6000,'interest_rate_monthly'=>2,'tenure_months'=>12,'min_interest_months'=>0,'created_by'=>$owner->id]);
        });
    }
    public function test_register_borrower_loan_evidence_repayment_and_receipt(): void
    {
        $owner=$this->owner('regflow'); $this->actingAs($owner);
        self::activeSubscription($owner->shop);
        $this->post('/borrowers',['first_name'=>'Synthetic','last_name'=>'Borrower','mobile'=>'9000000001','address'=>'Synthetic address'])->assertRedirect('/borrowers');
        $customer=Customer::withoutTenant()->firstOrFail();
        $this->post('/loans',['customer_id'=>$customer->id,'description'=>'Synthetic gold chain','metal_type'=>'gold','purity'=>24,'gross_weight'=>20,'stone_weight'=>0,'rate_per_gram'=>6000,'principal_amount'=>50000,'interest_rate_monthly'=>2,'tenure_months'=>12,'loan_date'=>today()->toDateString()])->assertRedirect();
        $loan=DhiranLoan::withoutTenant()->firstOrFail();
        $this->assertSame('pending_evidence',$loan->status);
        $this->post('/loans/'.$loan->id.'/activate')->assertSessionHasErrors();
        $this->post('/loans/'.$loan->id.'/evidence',['item_photo'=>UploadedFile::fake()->image('synthetic-chain.jpg'),'id_proof'=>UploadedFile::fake()->image('synthetic-id.jpg')])->assertRedirect();
        $this->post('/loans/'.$loan->id.'/activate')->assertRedirect();
        $this->assertSame('active',$loan->fresh()->status);
        $key=(string)Str::uuid();
        $payload=['amount'=>'5000.00','method'=>'cash','submission_key'=>$key];
        $response=$this->post('/loans/'.$loan->id.'/payments',$payload)->assertRedirect();
        $payment=DhiranPayment::withoutTenant()->where('direction','in')->firstOrFail();
        $this->assertSame(45000.0,(float)$loan->fresh()->outstanding_principal);
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Payment receipt')->assertSee('5,000.00')->assertSee('45,000.00')->assertSee('Synthetic Borrower');
        $this->post('/loans/'.$loan->id.'/payments',$payload)->assertRedirect();
        $this->assertSame(1,DhiranPayment::withoutTenant()->where('direction','in')->count());
        $this->post('/loans/'.$loan->id.'/payments',['amount'=>1000,'method'=>'cash','submission_key'=>(string)Str::uuid()])->assertRedirect();
        $this->get('/payments/'.$payment->id.'/receipt')->assertOk()->assertSee('45,000.00');
        $this->assertDatabaseHas('dhiran_cash_entries',['dhiran_payment_id'=>$payment->id,'amount'=>'5000.00']);
        $this->assertDatabaseHas('dhiran_ledger_entries',['dhiran_payment_id'=>$payment->id,'amount'=>'5000.00']);
        $this->assertNull(TenantContext::get());
    }
    public function test_second_shop_cannot_list_view_repay_or_print_first_shops_loan(): void
    {
        $a=$this->owner(); $loan=$this->loan($a);
        $payment=TenantContext::runFor($a->shop_id,fn()=>app(DhiranService::class)->recordPayment($loan,5000));
        $b=$this->owner('two'); $this->actingAs($b);
        $this->get('/dhiran')->assertOk()->assertDontSee('Synthetic gold chain');
        $this->get('/loans/'.$loan->id)->assertNotFound();
        $this->get('/payments/'.$payment->id.'/receipt')->assertNotFound();
        $this->post('/loans/'.$loan->id.'/payments',['amount'=>1000,'method'=>'cash','submission_key'=>(string)Str::uuid()])->assertNotFound();
        $this->post('/loans',['customer_id'=>$loan->customer_id,'description'=>'Forged','metal_type'=>'gold','purity'=>24,'gross_weight'=>20,'rate_per_gram'=>6000,'principal_amount'=>50000,'interest_rate_monthly'=>2,'tenure_months'=>12,'loan_date'=>today()->toDateString(),'shop_id'=>$a->shop_id])->assertSessionHasErrors('customer_id');
        $this->assertSame(45000.0,(float)$loan->fresh()->outstanding_principal);
    }
    public function test_viewer_cannot_write_and_owner_cannot_assign_staff_to_another_shop(): void
    {
        $viewer=$this->owner('view','viewer'); $loan=$this->loan($viewer); $this->actingAs($viewer);
        $this->post('/borrowers',['first_name'=>'Blocked','mobile'=>'9000000002'])->assertForbidden();
        $this->post('/loans/'.$loan->id.'/payments',['amount'=>100,'method'=>'cash','submission_key'=>(string)Str::uuid()])->assertForbidden();
        $this->post('/staff',['name'=>'Blocked'])->assertForbidden();
        $owner=$this->owner('staff'); $this->actingAs($owner);
        $roles=app(\App\Services\TenantRoleService::class)->ensureDefaultsForShop($owner->shop_id);
        $this->post('/staff',['name'=>'Clerk','mobile_number'=>'9000000081','email'=>'clerk@example.test','password'=>'Local-pass-12345','password_confirmation'=>'Local-pass-12345','role_id'=>$roles['operator']->id,'shop_id'=>$viewer->shop_id])->assertRedirect(route('settings.edit',['tab'=>'staff']));
        $this->assertSame($owner->shop_id,User::where('email','clerk@example.test')->firstOrFail()->shop_id);
    }
    public function test_invalid_payment_does_not_write_and_get_does_not_accrue(): void
    {
        $owner=$this->owner(); $loan=$this->loan($owner); $this->actingAs($owner);
        $before=$loan->getRawOriginal();
        $this->get('/loans/'.$loan->id)->assertOk();
        $this->assertSame($before,$loan->fresh()->getRawOriginal());
        foreach (['0','-10','999999','0.001'] as $amount) {
            $this->post('/loans/'.$loan->id.'/payments',['amount'=>$amount,'method'=>'cash','submission_key'=>(string)Str::uuid()])->assertSessionHasErrors();
        }
        $this->assertSame(1,DhiranPayment::withoutTenant()->count());
        $this->assertSame(50000.0,(float)$loan->fresh()->outstanding_principal);
    }
}
