<?php
namespace Tests\Feature;
use App\Models\Shop;
use App\Models\User;
use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class RestoredParityTest extends TestCase
{
    use RefreshDatabase;
    public function test_original_new_loan_submission_and_pledge_receipt_keep_fields_and_math(): void
    {
        $shop=self::createDhiranShop(['name'=>'Synthetic Parity Shop']);
        $owner=User::create(['name'=>'Parity Owner','email'=>'parity@example.test','password'=>'Parity-pass-12345','shop_id'=>$shop->id]);
        TenantContext::runFor($shop->id,fn()=>\App\Models\Dhiran\DhiranSettings::getForShop($shop->id)->update(['is_enabled'=>true]));
        $this->actingAs($owner);
        $borrower=TenantContext::runFor($shop->id,fn()=>Customer::create(['first_name'=>'Synthetic','last_name'=>'Parity','mobile'=>'9000000090']));
        $this->get('/dhiran/create?customer_id='.$borrower->id)->assertOk()->assertSee('New Pledge Loan')->assertSee('Add New Borrower')->assertSee('Add Item')->assertSee('Interest Type')->assertSee('Loan Summary');
        $this->post('/dhiran',['idempotency_key'=>'parity-loan-0001','customer_id'=>$borrower->id,'principal_amount'=>50000,'gold_rate_on_date'=>6000,'interest_rate_monthly'=>1.5,'interest_type'=>'flat','tenure_months'=>6,'penalty_rate_monthly'=>2,'notes'=>'Synthetic parity check','items'=>[['description'=>'Synthetic gold chain','metal_type'=>'gold','value_mode'=>'metal','gross_weight'=>20,'stone_weight'=>0,'purity'=>22,'rate_per_gram_at_pledge'=>6000,'huid'=>'SYNTH1']]])->assertRedirect();
        $loan=DhiranLoan::withoutTenant()->where('shop_id',$shop->id)->firstOrFail();
        $this->assertSame(110000.0,(float)$loan->total_collateral_value);
        $this->assertSame(50000.0,(float)$loan->principal_amount);
        $this->assertSame('pending_evidence',$loan->status);
        $this->assertSame(1.5,(float)$loan->interest_rate_monthly);
        $this->get('/dhiran/loans/'.$loan->id.'/receipt')->assertOk()->assertSee('Pledge Receipt / Girvi Parchi')->assertSee('Synthetic Parity')->assertSee('110,000.00')->assertSee('50,000.00')->assertSee('22K')->assertSee('75.0%');
    }
}
