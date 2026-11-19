<?php
namespace App\Http\Controllers;
use App\Models\Customer;
use App\Models\User;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranPayment;
use App\Models\Dhiran\DhiranAttachment;
use App\Services\DhiranService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use LogicException;
class CheckpointDhiranController
{
    public function __construct(private DhiranService $engine) {}
    private function permit(Request $r,string $permission): void { abort_unless($r->user('web')->may($permission),403); }
    public function index(Request $r)
    {
        $query=DhiranLoan::with('customer')->latest('id');
        if ($search=trim((string)$r->query('q'))) { $query->where(fn($q)=>$q->where('loan_number','ilike','%'.$search.'%')->orWhereHas('customer',fn($c)=>$c->where('first_name','ilike','%'.$search.'%')->orWhere('mobile','ilike','%'.$search.'%'))); }
        return view('dashboard',['loans'=>$query->paginate(15)->withQueryString(),'open'=>DhiranLoan::whereIn('status',['active','pending_evidence'])->count(),'principal'=>DhiranLoan::whereIn('status',['active','pending_evidence'])->sum('outstanding_principal'),'collected'=>DhiranPayment::where('direction','in')->sum('amount'),'borrowers'=>Customer::count()]);
    }
    public function borrowers() { return view('borrowers.index',['borrowers'=>Customer::latest('id')->paginate(25)]); }
    public function storeBorrower(Request $r)
    {
        $this->permit($r,'borrowers.write');
        $data=$r->validate(['first_name'=>'required|string|max:100','last_name'=>'nullable|string|max:100','mobile'=>['required','regex:/^[6-9][0-9]{9}$/',Rule::unique('customers','mobile')->where('shop_id',$r->user('web')->shop_id)],'address'=>'nullable|string|max:1000']);
        Customer::create($data); return redirect('/borrowers')->with('success','Borrower added.');
    }
    public function create(Request $r)
    {
        $this->permit($r,'loans.write');
        return view('loans.create',['borrowers'=>Customer::active()->orderBy('first_name')->get()]);
    }
    public function store(Request $r)
    {
        $this->permit($r,'loans.write');
        $data=$r->validate([
            'customer_id'=>['required','integer',Rule::exists('customers','id')->where('shop_id',$r->user('web')->shop_id)->where('is_active',true)],
            'description'=>'required|string|max:500','metal_type'=>'required|in:gold,silver','purity'=>'required|numeric|gt:0','gross_weight'=>'required|numeric|gt:0|max:9999','stone_weight'=>'nullable|numeric|min:0|lt:gross_weight',
            'rate_per_gram'=>'required|numeric|gt:0|max:1000000','principal_amount'=>'required|numeric|decimal:0,2|gt:0|max:5000000','interest_rate_monthly'=>'required|numeric|min:0|max:20','tenure_months'=>'required|integer|min:1|max:60','loan_date'=>'required|date_format:Y-m-d|before_or_equal:today'
        ]);
        if ($data['purity'] > ($data['metal_type']==='gold'?24:1000)) { throw ValidationException::withMessages(['purity'=>'Purity exceeds the scale for this metal.']); }
        $customer=Customer::findOrFail($data['customer_id']);
        try {
            $loan=$this->engine->createLoan($r->user('web')->shop,$customer,[[...$data,'rate_per_gram_at_pledge'=>$data['rate_per_gram']]], [...$data,'gold_rate_on_date'=>$data['metal_type']==='gold'?$data['rate_per_gram']:0,'silver_rate_on_date'=>$data['metal_type']==='silver'?$data['rate_per_gram']:null,'status'=>'pending_evidence','created_by'=>$r->user('web')->id]);
        } catch (LogicException $e) { throw ValidationException::withMessages(['principal_amount'=>$e->getMessage()]); }
        return redirect('/loans/'.$loan->id)->with('success','Loan created. Add evidence to activate it.');
    }
    public function show(int $loan)
    {
        $loan=DhiranLoan::with(['customer','items','payments'])->findOrFail($loan);
        return view('loans.show',['loan'=>$loan,'summary'=>$this->engine->loanSummary($loan),'evidence'=>$this->engine->evidenceStatus($loan),'attachments'=>DhiranAttachment::where('owner_type',DhiranAttachment::OWNER_LOAN)->where('owner_id',$loan->id)->get()]);
    }
    public function evidence(Request $r,int $loan)
    {
        $this->permit($r,'loans.write'); $loan=DhiranLoan::findOrFail($loan);
        $data=$r->validate(['item_photo'=>'required|file|mimes:jpg,jpeg,png|max:5120','id_proof'=>'required|file|mimes:jpg,jpeg,png,pdf|max:5120']);
        $paths=[];
        try {
            DB::transaction(function() use($r,$loan,$data,&$paths) {
                foreach (['item_photo'=>'item_photo','id_proof'=>'id_proof_front'] as $field=>$type) {
                    $file=$data[$field]; $path=$file->store('shops/'.$loan->shop_id.'/loans/'.$loan->id,'local'); $paths[]=$path;
                    DhiranAttachment::create(['owner_type'=>DhiranAttachment::OWNER_LOAN,'owner_id'=>$loan->id,'document_type'=>$type,'file_disk'=>'local','file_path'=>$path,'original_name'=>$file->getClientOriginalName(),'mime_type'=>$file->getMimeType(),'size_bytes'=>$file->getSize(),'uploaded_by'=>$r->user('web')->id]);
                }
            });
        } catch (\Throwable $e) { foreach ($paths as $path) { Storage::disk('local')->delete($path); } throw $e; }
        return back()->with('success','Private evidence saved.');
    }
    public function attachment(int $attachment)
    {
        $a=DhiranAttachment::findOrFail($attachment);
        abort_unless(str_starts_with($a->file_path,'shops/'.$a->shop_id.'/'),404);
        return Storage::disk('local')->response($a->file_path, $a->original_name, ['X-Content-Type-Options'=>'nosniff','Cache-Control'=>'private, no-store']);
    }
    public function activate(Request $r,int $loan)
    {
        $this->permit($r,'loans.write'); $loan=DhiranLoan::findOrFail($loan);
        try { $this->engine->activateLoan($loan); } catch (LogicException $e) { throw ValidationException::withMessages(['evidence'=>$e->getMessage()]); }
        return back()->with('success','Loan activated. Repayments are now available.');
    }
    public function pay(Request $r,int $loan)
    {
        $this->permit($r,'payments.write'); $loan=DhiranLoan::findOrFail($loan);
        $data=$r->validate(['amount'=>'required|numeric|decimal:0,2|min:0.01|max:999999999','method'=>'required|in:cash,upi,bank_transfer','submission_key'=>'required|uuid']);
        $fingerprint=hash('sha256',$loan->id.'|'.number_format((float)$data['amount'],2,'.','').'|'.$data['method']);
        try {
            $payment=DB::transaction(function() use($loan,$data,$fingerprint) {
                $loan=DhiranLoan::lockForUpdate()->findOrFail($loan->id);
                $existing=DB::table('payment_submissions')->where('shop_id',$loan->shop_id)->where('submission_key',$data['submission_key'])->first();
                if ($existing) { abort_unless(hash_equals($existing->fingerprint,$fingerprint),409,'This submission was already used with different details.'); return DhiranPayment::findOrFail($existing->payment_id); }
                $payment=$this->engine->recordPayment($loan,(float)$data['amount'],$data['method']);
                DB::table('payment_submissions')->insert(['shop_id'=>$loan->shop_id,'submission_key'=>$data['submission_key'],'loan_id'=>$loan->id,'payment_id'=>$payment->id,'fingerprint'=>$fingerprint]);
                return $payment;
            });
        } catch (LogicException $e) { throw ValidationException::withMessages(['amount'=>$e->getMessage()]); }
        return redirect('/payments/'.$payment->id.'/receipt')->with('success','Repayment recorded.');
    }
    public function receipt(int $payment)
    {
        $payment=DhiranPayment::with(['loan.customer','creator','shop'])->findOrFail($payment);
        return response()->view('loans.receipt',compact('payment'))->header('Cache-Control','private, no-store');
    }
    public function staff(Request $r)
    {
        $this->permit($r,'staff.manage'); return view('staff',['staff'=>User::where('shop_id',$r->user('web')->shop_id)->get()]);
    }
    public function storeStaff(Request $r)
    {
        $this->permit($r,'staff.manage'); $data=$r->validate(['name'=>'required|string|max:120','email'=>'required|email|lowercase|max:190|unique:users,email','password'=>['required',Password::min(12)],'role'=>'required|in:operator,viewer']);
        User::create([...$data,'shop_id'=>$r->user('web')->shop_id]); return redirect('/staff')->with('success','Staff account created.');
    }
    public function updateStaff(Request $r,int $staff)
    {
        $this->permit($r,'staff.manage'); $user=User::where('shop_id',$r->user('web')->shop_id)->whereIn('role',['operator','viewer'])->findOrFail($staff);
        $data=$r->validate(['role'=>'required|in:operator,viewer','is_active'=>'required|boolean']); $user->update($data); return back()->with('success','Staff access updated.');
    }
}
