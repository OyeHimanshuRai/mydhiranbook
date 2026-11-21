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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
class StandaloneWorkflowTest extends TestCase
{
    use RefreshDatabase;
    private function owner(string $suffix='one', string $role='owner'): User
    {
        $shop=self::createDhiranShop(['name'=>'Synthetic shop '.$suffix]);
        TenantContext::runFor($shop->id,fn()=>\App\Models\Dhiran\DhiranSettings::getForShop($shop->id)->update(['is_enabled'=>true]));
        $roles=app(\App\Services\TenantRoleService::class)->ensureDefaultsForShop($shop->id);
        return User::create(['name'=>'Synthetic Owner','email'=>$suffix.'@example.test','password'=>'Local-pass-12345','shop_id'=>$shop->id,'role_id'=>$roles[$role]->id]);
    }
    private function loan(User $owner): DhiranLoan
    {
        return TenantContext::runFor($owner->shop_id, function() use($owner) {
            $c=Customer::create(['first_name'=>'Synthetic','last_name'=>'Borrower','mobile'=>'9000000001']);
            return app(DhiranService::class)->createLoan($owner->shop,$c,[['description'=>'Synthetic gold chain','metal_type'=>'gold','purity'=>24,'gross_weight'=>20,'rate_per_gram_at_pledge'=>6000]],['principal_amount'=>50000,'gold_rate_on_date'=>6000,'interest_rate_monthly'=>2,'tenure_months'=>12,'min_interest_months'=>0,'created_by'=>$owner->id]);
        });
    }
    private function evidence(DhiranLoan $loan,string $type,string $name='e.jpg'): array
    {
        return ['owner_type'=>'dhiran_loan','owner_id'=>$loan->id,'document_type'=>$type,'file'=>UploadedFile::fake()->image($name)];
    }
    public function test_register_borrower_loan_evidence_repayment_and_receipt(): void
    {
        Storage::fake('local');
        $owner=$this->owner('regflow'); $this->actingAs($owner);
        $customer=TenantContext::runFor($owner->shop_id,fn()=>Customer::create(['first_name'=>'Synthetic','last_name'=>'Borrower','mobile'=>'9000000001']));
        $this->post('/dhiran',['idempotency_key'=>'standalone-loan-0001','customer_id'=>$customer->id,'principal_amount'=>50000,'gold_rate_on_date'=>6000,'interest_rate_monthly'=>2,'interest_type'=>'flat','tenure_months'=>12,'items'=>[['description'=>'Synthetic gold chain','metal_type'=>'gold','value_mode'=>'metal','gross_weight'=>20,'stone_weight'=>0,'purity'=>24,'rate_per_gram_at_pledge'=>6000]]])->assertRedirect();
        $loan=DhiranLoan::withoutTenant()->firstOrFail();
        $this->assertSame('pending_evidence',$loan->status);
        $this->post('/dhiran/loans/'.$loan->id.'/activate')->assertSessionHas('error');
        $this->assertSame('pending_evidence',$loan->fresh()->status);
        $this->post('/dhiran/attachments',$this->evidence($loan,'item_photo'))->assertSessionHasNoErrors();
        $this->post('/dhiran/attachments',$this->evidence($loan,'id_proof_front'))->assertSessionHasNoErrors();
        $this->post('/dhiran/loans/'.$loan->id.'/activate')->assertSessionHas('success');
        $this->assertSame('active',$loan->fresh()->status);
        $this->post('/dhiran/loans/'.$loan->id.'/repay',$pay=['idempotency_key'=>'standalone-pay-0001','amount'=>'5000.00','payment_method'=>'cash'])->assertSessionHas('success');
        $this->post('/dhiran/loans/'.$loan->id.'/repay',$pay); // double submit records nothing more
        $payment=DhiranPayment::withoutTenant()->where('direction','in')->firstOrFail();
        $this->assertSame(1,DhiranPayment::withoutTenant()->where('direction','in')->count());
        // the 5,000 splits fully into components; principal drops by exactly the principal part
        $this->assertSame('5000.00',$payment->amount);
        $this->assertEqualsWithDelta(5000.0,(float)$payment->interest_component+(float)$payment->penalty_component+(float)$payment->principal_component+(float)$payment->processing_fee_component,0.001);
        $after=50000.0-(float)$payment->principal_component;
        $this->assertGreaterThan(0.0,(float)$payment->principal_component);
        $this->assertSame($after,(float)$loan->fresh()->outstanding_principal);
        $this->assertSame($after,(float)$payment->outstanding_principal_after);
        $this->get('/dhiran/loans/'.$loan->id.'/payments/'.$payment->id.'/receipt')->assertOk()->assertSee('Synthetic Borrower')->assertSee('5,000.00')->assertSee(number_format($after,2));
        $this->assertDatabaseHas('dhiran_cash_entries',['dhiran_payment_id'=>$payment->id,'amount'=>'5000.00']);
        $this->assertDatabaseHas('dhiran_ledger_entries',['dhiran_payment_id'=>$payment->id,'amount'=>'5000.00']);
        $this->assertNull(TenantContext::get());
    }
    public function test_second_shop_cannot_list_view_repay_or_print_first_shops_loan(): void
    {
        Storage::fake('local');
        $a=$this->owner(); $loan=$this->loan($a);
        $payment=TenantContext::runFor($a->shop_id,fn()=>app(DhiranService::class)->recordPayment($loan,5000));
        $att=TenantContext::runFor($a->shop_id,fn()=>app(\App\Services\Dhiran\DhiranAttachmentService::class)->store(UploadedFile::fake()->image('id.jpg'),$a->shop_id,'dhiran_loan',$loan->id,'id_proof_front',$a->id));
        $before=(float)$loan->fresh()->outstanding_principal;
        $b=$this->owner('two'); $this->actingAs($b);
        $this->get('/dhiran/loans')->assertOk()->assertDontSee($loan->loan_number);
        $this->get('/dhiran/loans/'.$loan->id)->assertNotFound();
        $this->get('/dhiran/loans/'.$loan->id.'/payments/'.$payment->id.'/receipt')->assertNotFound();
        $this->get('/dhiran/attachments/'.$att->id)->assertNotFound();
        $this->post('/dhiran/loans/'.$loan->id.'/repay',['idempotency_key'=>'foreign-pay-0001','amount'=>1000,'payment_method'=>'cash'])->assertNotFound();
        $this->post('/dhiran/attachments',$this->evidence($loan,'loan_document'))->assertNotFound();
        $this->post('/dhiran',['idempotency_key'=>'forged-loan-0001','customer_id'=>$loan->customer_id,'principal_amount'=>50000,'gold_rate_on_date'=>6000,'interest_rate_monthly'=>2,'interest_type'=>'flat','tenure_months'=>12,'shop_id'=>$a->shop_id,'items'=>[['description'=>'Forged','metal_type'=>'gold','value_mode'=>'metal','gross_weight'=>20,'stone_weight'=>0,'purity'=>24,'rate_per_gram_at_pledge'=>6000]]])->assertSessionHasErrors('customer_id');
        $this->assertSame(0,DhiranLoan::withoutTenant()->where('shop_id',$b->shop_id)->count());
        $this->assertSame($before,(float)$loan->fresh()->outstanding_principal);
    }
    public function test_viewer_cannot_write_and_owner_cannot_assign_staff_to_another_shop(): void
    {
        $viewer=$this->owner('view','viewer'); $loan=$this->loan($viewer); $this->actingAs($viewer);
        $this->get('/dhiran/loans/'.$loan->id)->assertOk();
        $this->post('/dhiran/loans/'.$loan->id.'/repay',['idempotency_key'=>'viewer-pay-0001','amount'=>100,'payment_method'=>'cash'])->assertForbidden();
        $this->post('/dhiran/loans/'.$loan->id.'/activate')->assertForbidden();
        $this->post('/dhiran/customers',['first_name'=>'Blocked','mobile'=>'9000000002'])->assertForbidden();
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
        $this->get('/dhiran/loans/'.$loan->id)->assertOk();
        $this->assertSame($before,$loan->fresh()->getRawOriginal());
        foreach (['0','-10','abc','0.001'] as $i=>$amount) {
            $this->post('/dhiran/loans/'.$loan->id.'/repay',['idempotency_key'=>'invalid-pay-000'.$i,'amount'=>$amount,'payment_method'=>'cash'])->assertSessionHasErrors('amount');
        }
        $this->post('/dhiran/loans/'.$loan->id.'/repay',['idempotency_key'=>'invalid-pay-9999','amount'=>'999999','payment_method'=>'cash'])->assertSessionHas('error');
        $this->assertSame(0,DhiranPayment::withoutTenant()->where('direction','in')->count());
        $this->assertSame(50000.0,(float)$loan->fresh()->outstanding_principal);
    }
}
