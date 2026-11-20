<?php
namespace Database\Seeders;
use App\Models\Customer;
use App\Models\Shop;
use App\Models\User;
use App\Models\Platform\PlatformAdmin;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Dhiran\DhiranAttachment;
use App\Services\DhiranService;
use App\Services\TotpService;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment('local')) { throw new \RuntimeException('Demo seeding is local only.'); }
        $access=base_path('.runtime/demo-access.json');
        if (file_exists($access)) { $this->command?->info('Existing demo preserved. Credentials: .runtime/demo-access.json'); return; }
        $credentials=DB::transaction(function() {
            $password=bin2hex(random_bytes(8)).'D!';
            $shop=Shop::create(['name'=>'Dhiran Demo · Pune','phone'=>'9000000001','address'=>'Synthetic Market Road, Pune · Demonstration only']);
            $owner=User::create(['name'=>'Demo Owner','email'=>'owner@dhiran.test','password'=>$password,'shop_id'=>$shop->id,'role'=>'owner']);
            (new PlanSeeder)->run(); $plan=\App\Models\Platform\Plan::where('code','dhiran_yearly')->firstOrFail();
            \App\Models\Platform\ShopSubscription::create(['shop_id'=>$shop->id,'plan_id'=>$plan->id,'status'=>'active','billing_cycle'=>'yearly','starts_at'=>today(),'ends_at'=>today()->addYear(),'grace_ends_at'=>today()->addYear()->addDays($plan->grace_days),'price_paid'=>$plan->price_yearly,'actor_type'=>'system','notes'=>'Synthetic demo subscription']);
            $loan=TenantContext::runFor($shop->id,function() use($shop,$owner) {
                DhiranSettings::getForShop($shop->id)->update(['is_enabled'=>true]);
                $borrower=Customer::create(['first_name'=>'Synthetic','last_name'=>'Borrower','mobile'=>'9000000001','address'=>'Demonstration address only']);
                $engine=app(DhiranService::class);
                $loan=$engine->createLoan($shop,$borrower,[['description'=>'Synthetic gold chain','metal_type'=>'gold','purity'=>24,'gross_weight'=>20,'rate_per_gram_at_pledge'=>6000]],['principal_amount'=>50000,'gold_rate_on_date'=>6000,'interest_rate_monthly'=>2,'tenure_months'=>12,'status'=>'pending_evidence','created_by'=>$owner->id]);
                foreach (['item_photo','id_proof_front'] as $type) {
                    $img=imagecreatetruecolor(700,400); $bg=imagecolorallocate($img,245,247,250); imagefill($img,0,0,$bg); $ink=imagecolorallocate($img,15,27,45);
                    imagestring($img,5,40,150,'DHIRAN - SYNTHETIC DEMONSTRATION ONLY',$ink); imagestring($img,5,40,190,strtoupper(str_replace('_',' ',$type)),$ink);
                    ob_start(); imagepng($img); $bytes=ob_get_clean(); imagedestroy($img);
                    $path='shops/'.$shop->id.'/loans/'.$loan->id.'/'.$type.'.png'; Storage::disk('local')->put($path,$bytes);
                    DhiranAttachment::create(['owner_type'=>DhiranAttachment::OWNER_LOAN,'owner_id'=>$loan->id,'document_type'=>$type,'file_disk'=>'local','file_path'=>$path,'original_name'=>'synthetic-'.$type.'.png','mime_type'=>'image/png','size_bytes'=>strlen($bytes),'uploaded_by'=>$owner->id]);
                }
                $engine->activateLoan($loan); $engine->recordPayment($loan->fresh(),5000);
                return $loan;
            });
            $second=Shop::create(['name'=>'Dhiran Demo · Nashik']); $secondPass=bin2hex(random_bytes(8)).'D!';
            User::create(['name'=>'Second Demo Owner','email'=>'second@dhiran.test','password'=>$secondPass,'shop_id'=>$second->id,'role'=>'owner']);
            TenantContext::runFor($second->id,fn()=>DhiranSettings::getForShop($second->id)->update(['is_enabled'=>true]));
            $adminPass=bin2hex(random_bytes(8)).'A!'; $secret=app(TotpService::class)->generateSecret();
            $admin=PlatformAdmin::create(['name'=>'Synthetic Dhiran Admin','email'=>'admin@dhiran.test','password'=>$adminPass,'role'=>'super_admin','is_active'=>true,'two_factor_secret'=>$secret,'two_factor_enabled'=>true]);
            $recovery=$admin->generateRecoveryCodes();
            return ['owner'=>['email'=>$owner->email,'password'=>$password],'second_owner'=>['email'=>'second@dhiran.test','password'=>$secondPass],'admin'=>['email'=>$admin->email,'password'=>$adminPass,'totp_secret'=>$secret,'recovery_codes'=>$recovery],'demo_loan_id'=>$loan->id];
        });
        file_put_contents($access,json_encode($credentials,JSON_PRETTY_PRINT).PHP_EOL); chmod($access,0600);
        $this->command?->info('Synthetic demo shops, borrower, evidenced loan, repayment and independent admin created. Credentials: .runtime/demo-access.json');
    }
}
