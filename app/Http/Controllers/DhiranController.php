<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranLoanItem;
use App\Models\Dhiran\DhiranPayment;
use App\Models\Dhiran\DhiranSettings;
use App\Rules\IndianMobileRule;
use Illuminate\Support\Facades\DB;
use App\Services\DhiranService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DhiranController extends Controller implements \Illuminate\Routing\Controllers\HasMiddleware
{
    /** Every form that moves money or changes a loan takes effect once (see DhiranSubmitOnce). */
    public static function middleware(): array
    {
        return [new \Illuminate\Routing\Controllers\Middleware(\App\Http\Middleware\DhiranSubmitOnce::class,
            only: ['store', 'payInterest', 'payInstallment', 'repay', 'releaseItem', 'preClose', 'renew', 'close', 'cancel', 'sendNotice', 'forfeit'])];
    }

    public function __construct(
        protected DhiranService $dhiranService
    ) {}

    /* ================================================================
     *  DASHBOARD & ACTIVATION
     * ================================================================ */

    public function dashboard()
    {
        $user = auth()->user();

        $shopId   = $user->shop_id;
        $settings = DhiranSettings::getForShop($shopId);

        if (! $settings->is_enabled) {
            return view('dhiran.activate', compact('settings'));
        }

        // Owner 2026-11-21: no dashboard page; the shop's home is the loans list.
        // Keep any notice sent here (e.g. "plan ended" from a blocked New Loan) for the next page.
        session()->reflash();
        return redirect()->route('dhiran.loans');
    }

    public function activate(Request $request)
    {
        $shopId   = auth()->user()->shop_id;
        $settings = DhiranSettings::getForShop($shopId);

        $settings->update(['is_enabled' => true]);

        return redirect()->route('dhiran.dashboard')
            ->with('success', 'Dhiran module activated successfully.');
    }

    /**
     * Email-verification gate page. A Dhiran account registers with mobile only,
     * so it must capture + verify an email here (via the email-OTP flow) before
     * using the app — otherwise a forgotten password is unrecoverable. Already-
     * verified users skip straight to the dashboard.
     */
    public function verifyEmail()
    {
        if (auth()->user()->email_verified_at !== null) {
            return redirect()->route('dhiran.dashboard');
        }

        return view('dhiran.verify-email', ['user' => auth()->user()]);
    }

    /* ================================================================
     *  LOAN CRUD
     * ================================================================ */

    public function create(Request $request)
    {
        $shopId   = auth()->user()->shop_id;
        $settings = DhiranSettings::getForShop($shopId);

        abort_unless($settings->is_enabled, 403, 'Dhiran module is not enabled.');

        // Preselect a borrower when arriving from the borrower profile / list (?customer_id=). Only this shop's own,
        // active borrower is honoured. The page never carries the borrower book: the picker searches on typing (searchBorrowers).
        $wanted = $request->query('customer_id', $request->old('customer_id')); // old(): the form came back after a refusal
        $preselectedCustomer = filled($wanted) ? Customer::where('shop_id', $shopId)->active()->with('profilePhoto')->find((int) $wanted) : null;
        $preselectedCustomerId = $preselectedCustomer?->id;
        $preselected = $preselectedCustomer ? $this->borrowerOption($preselectedCustomer) : null;

        // The shop's Dhiran settings seed the form (the view read $defaults, which was never passed, so it always
        // showed built-in values whatever the shop had set).
        $defaults = [
            'interest_rate_monthly'  => (float) $settings->default_interest_rate_monthly,
            'interest_type'          => $settings->default_interest_type ?: 'flat',
            'tenure_months'          => (int) $settings->default_tenure_months,
            'penalty_rate_monthly'   => (float) $settings->default_penalty_rate_monthly,
            'processing_fee_percent' => $settings->processing_fee_type === 'percent' ? (float) $settings->processing_fee_value : 0,
            'processing_fee_flat'    => $settings->processing_fee_type === 'percent' ? 0 : (float) $settings->processing_fee_value,
            'ltv_percent'            => (float) $settings->default_ltv_ratio,
            // The limits DhiranService::createLoan checks, so the form can say "Up to ₹X" before saving.
            'high_value_ltv'         => (float) $settings->high_value_ltv_ratio,
            'high_value_threshold'   => (float) $settings->high_value_threshold,
            'min_loan'               => (float) $settings->min_loan_amount,
            'max_loan'               => (float) $settings->max_loan_amount,
        ];

        return view('dhiran.create', compact('settings', 'preselected', 'preselectedCustomerId', 'defaults'));
    }

    /** What the New Loan picker needs about a borrower: no PAN / ID (kyc() serves those for the one picked). */
    private function borrowerOption(Customer $c): array
    {
        return [
            'id' => $c->id, 'name' => trim($c->first_name . ' ' . ($c->last_name ?? '')),
            'mobile' => $c->mobile ?? '', 'address' => $c->address ?? '', 'state_code' => $c->state_code ?? '',
            'photo_url' => $c->profilePhoto?->url(), // the borrower's photo, shown in the Customer card
        ];
    }

    /** New Loan borrower picker: this shop's active borrowers matching name / mobile / address, 8 at most. */
    public function searchBorrowers(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $rows = [];
        if (mb_strlen($q) >= 2) {
            $like = '%' . addcslashes(mb_substr($q, 0, 60), '\\%_') . '%';
            $rows = Customer::where('shop_id', auth()->user()->shop_id)->active()->with('profilePhoto')
                ->where(fn ($w) => $w->whereRaw("(first_name || ' ' || COALESCE(last_name, '')) ILIKE ?", [$like])
                    ->orWhere('mobile', 'ilike', $like)->orWhere('address', 'ilike', $like))
                ->orderBy('first_name')->orderBy('last_name')->limit(8)
                ->get(['id', 'first_name', 'last_name', 'mobile', 'address', 'state_code'])
                ->map(fn ($c) => $this->borrowerOption($c))->all();
        }

        return response()->json($rows)->header('Cache-Control', 'no-store, private');
    }

    /** One borrower's PAN / ID number for the New Loan form, fetched when that borrower is picked (never the whole list). */
    public function kyc(Customer $customer)
    {
        abort_unless((int) $customer->shop_id === (int) auth()->user()->shop_id, 404);

        // has_id_proof: lets New Loan say the borrower's ID proof is already on file (no second upload needed)
        $hasIdProof = \App\Models\Dhiran\DhiranAttachment::where('owner_type', \App\Models\Dhiran\DhiranAttachment::OWNER_CUSTOMER)
            ->where('owner_id', $customer->id)->whereIn('document_type', ['id_proof_front', 'id_proof_back'])->exists();

        return response()->json($customer->only('pan', 'id_number') + ['has_id_proof' => $hasIdProof])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Create a borrower inline from the Dhiran New Loan page. Strictly scoped to
     * the current Dhiran shop: shop_id is NEVER taken from the request — it is set
     * by the Customer model's BelongsToShop hook from the tenant context (and we
     * pass the authenticated shop_id explicitly as belt-and-suspenders). A mobile
     * already used in THIS shop returns that existing customer instead of creating
     * a duplicate (the (shop_id, mobile) unique index also enforces this at the DB).
     */
    public function storeCustomer(Request $request)
    {
        $shopId   = auth()->user()->shop_id;
        $settings = DhiranSettings::getForShop($shopId);
        abort_unless($settings->is_enabled, 403, 'Dhiran module is not enabled.');

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name'  => ['nullable', 'string', 'max:255'],
            'mobile'     => ['required', 'string', new IndianMobileRule()],
            'address'    => ['nullable', 'string', 'max:500'],
            'state_code' => ['nullable', 'string', 'max:10'],
            'pan'        => ['nullable', new \App\Rules\PanFormatRule],
            'id_number'  => ['nullable', 'string', 'max:50'],
            'notes'      => ['nullable', 'string', 'max:1000'],
            'customer_photo' => ['nullable', 'file', 'max:5120'], // optional photo, saved right after the borrower (same encoder as the profile)
        ], ['customer_photo.max' => 'Choose an image no larger than 5 MB.']);

        // Dedupe within THIS shop (BelongsToShop scope keeps the lookup tenant-local).
        // resolveByMobile, not an exact match: a borrower added before mobiles
        // were canonicalised is stored as typed, and an exact lookup would miss
        // them and hand this shop a second loan account for the same person.
        $existing = Customer::resolveByMobile($data['mobile']);
        // The Add customer page (a plain form) lands on the customer's page; the New Loan dialog gets JSON.
        if ($existing && ! $request->expectsJson()) {
            return redirect()->route('dhiran.borrowers.show', $existing)
                ->with('success', 'A customer with this mobile already exists — here are their details.');
        }
        if ($existing) {
            return response()->json([
                'ok'        => true,
                'duplicate' => true,
                'message'   => 'A borrower with this mobile already exists in your shop — selected it.',
                'customer'  => [
                    'id'         => $existing->id,
                    'name'       => trim($existing->first_name . ' ' . ($existing->last_name ?? '')),
                    'mobile'     => $existing->mobile,
                    'address'    => $existing->address,
                    'pan'        => $existing->pan,
                    'id_number'  => $existing->id_number,
                    'state_code' => $existing->state_code,
                ],
            ]);
        }

        // shop_id and customer_code are set by the Customer model hooks (tenant
        // context + per-shop counter); state_code is not mass-assignable, so set it
        // via forceFill after create. We never read shop_id from the request.
        $customer = Customer::create([
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'] ?? null,
            'mobile'     => $data['mobile'],
            'address'    => $data['address'] ?? null,
            'pan'        => isset($data['pan']) ? strtoupper(trim($data['pan'])) : null,
            'id_number'  => $data['id_number'] ?? null,
            'notes'      => $data['notes'] ?? null,
        ]);

        if (! empty($data['state_code'])) {
            $customer->forceFill(['state_code' => $data['state_code']])->save();
        }

        // The borrower is saved either way; a photo that cannot be stored is reported, never lost silently.
        $photoError = null;
        if ($request->hasFile('customer_photo')) {
            try {
                app(\App\Services\CustomerProfilePhotoService::class)->save($customer, $request->file('customer_photo'), '');
            } catch (\Illuminate\Validation\ValidationException $e) {
                $photoError = $e->validator->errors()->first('customer_photo') ?: 'The photo could not be saved.';
            }
        }

        if (! $request->expectsJson()) {
            $back = redirect()->route('dhiran.borrowers.show', $customer)->with('success', 'Customer saved.');

            return $photoError ? $back->with('warning', "Customer saved, but the photo was not: {$photoError} Add it from this page.") : $back;
        }

        return response()->json([
            'ok'       => true,
            'customer' => [
                'id'         => $customer->id,
                'name'       => trim($customer->first_name . ' ' . ($customer->last_name ?? '')),
                'mobile'     => $customer->mobile,
                'address'    => $customer->address,
                'pan'        => $customer->pan,
                'id_number'  => $customer->id_number,
                'state_code' => $customer->state_code,
                'photo_url'  => $customer->load('profilePhoto')->profilePhoto?->url(),
            ],
            'photo_error' => $photoError,
        ], 201);
    }

    public function store(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $settings = DhiranSettings::getForShop($shopId);
        abort_unless($settings->is_enabled, 403, 'Dhiran module is not enabled.');

        // A metal rate is only needed for metal-valued items of that metal. A
        // pure-silver or pure-Other (appraised) loan must not be forced to enter a
        // gold rate, and vice-versa.
        $rawItems    = (array) $request->input('items', []);
        $needsGold   = \App\Services\Dhiran\DhiranCollateral::needsRate($rawItems, 'gold');
        $needsSilver = \App\Services\Dhiran\DhiranCollateral::needsRate($rawItems, 'silver');

        $data = $request->validate(\App\Services\Dhiran\DhiranCollateral::rules() + [
            'customer_id'              => ['required', 'integer', Customer::activeExistsRule((int) $shopId)],
            'principal_amount'         => 'required|numeric|min:1|max:999999999999.99', // decimal(14,2)
            'gold_rate_on_date'        => ($needsGold ? 'required' : 'nullable') . '|numeric|min:0',
            'silver_rate_on_date'      => ($needsSilver ? 'required' : 'nullable') . '|numeric|min:0',
            'interest_rate_monthly'    => 'nullable|numeric|min:0|max:10',
            'interest_type'            => 'nullable|in:flat,daily,compound',
            // No future dates; back-dating (entering existing paper loans) stays allowed, but the service refuses any
            // date inside the shop's locked accounting period (owner 2026-11-12).
            'loan_date'                => 'nullable|date|before_or_equal:today',
            'tenure_months'            => 'nullable|integer|min:1|max:60',
            'min_lock_months'          => 'nullable|integer|min:0|max:60',
            'grace_period_days'        => 'nullable|integer|min:0|max:90',
            'penalty_rate_monthly'     => 'nullable|numeric|min:0|max:10',
            'processing_fee'           => 'nullable|numeric|min:0|max:999999999999.99',
            'processing_fee_type'      => 'nullable|in:flat,percent',
            'advance_interest_months'  => 'nullable|integer|min:0|max:3', // the service also needs the shop's switch on
            'items.*.photo'            => ['nullable', 'file', 'max:8192', 'mimes:jpg,jpeg,png'], // a photo of each item, saved with the loan so activation needs no second trip
            'id_proof_front'           => ['nullable', 'file', 'max:8192', 'mimes:jpg,jpeg,png,pdf'], // the borrower's ID proof, saved to the borrower
            'id_proof_back'            => ['nullable', 'file', 'max:8192', 'mimes:jpg,jpeg,png,pdf'],
            'pan_card'                 => ['nullable', 'file', 'max:8192', 'mimes:jpg,jpeg,png,pdf'], // a photo of the PAN card
            // Aadhaar: accept a full 12-digit number OR an already-masked value, but
            // NEVER persist the full number (masked to last-4 before save below).
            'aadhaar'                  => ['nullable', 'string', 'max:20', 'regex:/^[0-9Xx\- ]{4,20}$/'],
            'pan'                      => ['nullable', new \App\Rules\PanFormatRule],
            'notes'                    => 'nullable|string|max:1000',
        ]);

        // Photos travel beside the items, not inside the collateral rules: take them out, keep them by position.
        $itemPhotos = [];
        foreach ($data['items'] as $i => $row) {
            $itemPhotos[$i] = $row['photo'] ?? null;
            unset($data['items'][$i]['photo']);
        }

        // Authoritative collateral gate (shared with Old Loans); the backend is the source of truth, not the form.
        if ($itemErrors = \App\Services\Dhiran\DhiranCollateral::errors($data['items'])) {
            return back()->withErrors($itemErrors)->withInput();
        }

        $customer = Customer::findOrFail($data['customer_id']);
        $items = \App\Services\Dhiran\DhiranCollateral::normalise($data['items']);

        $params = [
            'principal_amount'      => $data['principal_amount'],
            'gold_rate_on_date'     => $data['gold_rate_on_date'] ?? 0,
            'silver_rate_on_date'   => $data['silver_rate_on_date'] ?? null,
            'interest_rate_monthly' => $data['interest_rate_monthly'] ?? null,
            'interest_type'         => $data['interest_type'] ?? null,
            'loan_date'             => $data['loan_date'] ?? null,
            'tenure_months'         => $data['tenure_months'] ?? null,
            'min_lock_months'       => $data['min_lock_months'] ?? null,
            'grace_period_days'     => $data['grace_period_days'] ?? null,
            'penalty_rate_monthly'  => $data['penalty_rate_monthly'] ?? null,
            'processing_fee'        => $data['processing_fee'] ?? null,
            'processing_fee_type'   => $data['processing_fee_type'] ?? null,
            'advance_interest_months' => $data['advance_interest_months'] ?? 0,
            // Privacy: store only a masked Aadhaar (last 4). The full number is
            // never persisted, logged, or shown. PAN is kept as-is for now.
            'kyc_aadhaar'           => \App\Support\AadhaarMask::mask($data['aadhaar'] ?? null),
            'kyc_pan'               => isset($data['pan']) ? strtoupper(trim($data['pan'])) : null,
            'notes'                 => $data['notes'] ?? null,
            'created_by'            => auth()->id(),
            // Evidence gate: a loan created from the UI starts inactive until the
            // required photo + ID proof are uploaded and the owner activates it.
            'status'                => 'pending_evidence',
        ];

        try {
            $loan = $this->dhiranService->createLoan(auth()->user()->shop, $customer, $items, $params);
        } catch (\LogicException $e) {
            return back()->withErrors(['loan' => $e->getMessage()])->withInput();
        }

        // Item photos chosen on the form become item_photo evidence on the new loan's items (in the order they were listed).
        $saved = 0;
        $failed = [];
        $created = $loan->items()->orderBy('id')->get()->values();
        foreach (array_values($itemPhotos) as $n => $file) {
            if (! $file || ! ($item = $created->get($n))) {
                continue;
            }
            try {
                app(\App\Services\Dhiran\DhiranAttachmentService::class)->store($file, (int) $loan->shop_id,
                    \App\Models\Dhiran\DhiranAttachment::OWNER_ITEM, (int) $item->id, 'item_photo', auth()->id());
                $saved++;
            } catch (\LogicException $e) {
                $failed[] = 'item ' . ($n + 1) . ': ' . $e->getMessage();
            }
        }

        // The borrower's ID proof (front, back) is kept on the borrower, like one added from the profile, so later loans reuse it.
        $idSaved = 0;
        foreach (['id_proof_front' => 'ID proof front', 'id_proof_back' => 'ID proof back', 'pan_card' => 'PAN card'] as $field => $label) {
            if (! $request->hasFile($field)) {
                continue;
            }
            try {
                app(\App\Services\Dhiran\DhiranAttachmentService::class)->store($request->file($field), (int) $loan->shop_id,
                    \App\Models\Dhiran\DhiranAttachment::OWNER_CUSTOMER, (int) $loan->customer_id, $field, auth()->id());
                $idSaved++;
            } catch (\LogicException $e) {
                $failed[] = $label . ': ' . $e->getMessage();
            }
        }

        if ($saved + $idSaved === 0) {
            $message = 'Loan created. Upload the pledged-item photo and borrower ID proof, then activate the loan.';
        } else {
            $evidence = $this->dhiranService->evidenceStatus($loan->fresh());
            $missing = array_keys(array_filter(['the pledged-item photo' => ! $evidence['item_photo'], 'the borrower ID proof' => ! $evidence['id_proof']]));
            $message = $missing
                ? 'Loan created. Still needed before activation: ' . implode(' and ', $missing) . '.'
                : 'Loan created. The photo and ID proof are in: activate the loan.';
        }
        $redirect = redirect()->route('dhiran.show', $loan)->with('success', $message);

        return $failed ? $redirect->with('warning', 'A photo was not saved (' . implode('; ', $failed) . '). Add it under Evidence & Documents.') : $redirect;
    }

    /**
     * Activate a pending-evidence loan once the required evidence is uploaded.
     * The service re-checks the evidence (authoritative guard); this is not a
     * UI-only gate.
     */
    public function activateLoan(Request $request, DhiranLoan $loan)
    {
        abort_unless((int) $loan->shop_id === (int) auth()->user()->shop_id, 404);
        abort_unless($loan->status === 'pending_evidence', 422, 'This loan is not awaiting evidence.');

        try {
            $this->dhiranService->activateLoan($loan);
        } catch (\LogicException $e) {
            return redirect()->route('dhiran.show', $loan)->with('error', $e->getMessage());
        }

        return redirect()->route('dhiran.show', $loan)->with('success', 'Loan activated.');
    }

    /* ================================================================
     *  LOAN LISTING & DETAIL
     * ================================================================ */

    public function loans(Request $request)
    {
        $filters = \App\Services\Dhiran\DhiranListFilters::fromRequest($request);
        $shopId = auth()->user()->shop_id;

        $query = $filters->applyToLoans(DhiranLoan::where('shop_id', $shopId));
        if ($request->query('export') === 'csv') {
            return app(\App\Services\Dhiran\DhiranExports::class)->loans($query->latest()->orderByDesc('id'), 'loans-' . now()->toDateString());
        }
        // id breaks ties (loans saved in the same second), so rows never shift between pages or exports.
        $loans = $query->with('customer')->latest()->orderByDesc('id')->paginate(\App\Services\Dhiran\DhiranListFilters::perPage($request))->withQueryString();

        return view('dhiran.loans', compact('loans', 'filters'));
    }

    public function show(DhiranLoan $loan)
    {
        $loan->load([
            'customer',
            'items',
            'payments'  => fn ($q) => $q->latest('payment_date'),
            'renewedFrom',
            'renewals',
            'creator',
        ]);

        // Evidence attachments for this loan, its pledged items, and its borrower
        // (all tenant-scoped via BelongsToShop). Grouped for the view.
        $itemIds = $loan->items->pluck('id')->all();
        $attachments = \App\Models\Dhiran\DhiranAttachment::query()
            ->where(function ($q) use ($loan, $itemIds) {
                $q->where(fn ($w) => $w->where('owner_type', \App\Models\Dhiran\DhiranAttachment::OWNER_LOAN)->where('owner_id', $loan->id))
                  ->orWhere(fn ($w) => $w->where('owner_type', \App\Models\Dhiran\DhiranAttachment::OWNER_ITEM)->whereIn('owner_id', $itemIds ?: [0]));
                if ($loan->customer_id) {
                    $q->orWhere(fn ($w) => $w->where('owner_type', \App\Models\Dhiran\DhiranAttachment::OWNER_CUSTOMER)->where('owner_id', $loan->customer_id));
                }
            })
            ->latest()
            ->get();

        // Evidence gate status for the checklist + activation button.
        $evidence = $this->dhiranService->evidenceStatus($loan);

        // Paid in advance, month by month: the next installment's date and amount (the server works the amount out).
        $installment = null;
        if ($loan->status === 'active' && $loan->nextInstallmentDue()) {
            $due = $loan->nextInstallmentDue();
            $installment = ['due' => $due, 'amount' => $this->dhiranService->installmentAmount($loan), 'days' => (int) today()->diffInDays($due, false)];
        }

        return view('dhiran.show', compact('loan', 'attachments', 'evidence', 'installment'));
    }

    /* ================================================================
     *  PAYMENTS & ACTIONS
     * ================================================================ */

    public function payInterest(Request $request, DhiranLoan $loan)
    {
        abort_unless((int) $loan->shop_id === (int) auth()->user()->shop_id, 404);

        $data = $request->validate([
            'amount'         => 'required|numeric|min:1',
            'payment_method' => 'nullable|in:cash,upi,card,bank_transfer',
        ]);

        try {
            $this->dhiranService->recordInterestPayment($loan, (float) $data['amount'], $data['payment_method'] ?? 'cash');
        } catch (\LogicException $e) {
            return redirect()->route('dhiran.show', $loan)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('dhiran.show', $loan)
            ->with('success', 'Interest payment recorded.');
    }

    public function payInstallment(Request $request, DhiranLoan $loan)
    {
        abort_unless((int) $loan->shop_id === (int) auth()->user()->shop_id, 404);

        $data = $request->validate(['payment_method' => 'nullable|in:cash,upi,card,bank_transfer']);

        try {
            $payment = $this->dhiranService->payInstallment($loan, $data['payment_method'] ?? 'cash');
        } catch (\LogicException $e) {
            return redirect()->route('dhiran.show', $loan)->with('error', $e->getMessage());
        }

        return redirect()->route('dhiran.show', $loan)
            ->with('success', $payment ? 'Installment recorded.' : 'Nothing to collect: this month is already covered.');
    }

    public function repay(Request $request, DhiranLoan $loan)
    {
        abort_unless((int) $loan->shop_id === (int) auth()->user()->shop_id, 404);

        $data = $request->validate([
            'amount'         => 'required|numeric|min:1',
            'payment_method' => 'nullable|in:cash,upi,card,bank_transfer',
        ]);

        try {
            $this->dhiranService->recordPayment($loan, (float) $data['amount'], $data['payment_method'] ?? 'cash');
        } catch (\LogicException $e) {
            return redirect()->route('dhiran.show', $loan)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('dhiran.show', $loan)
            ->with('success', 'Repayment recorded.');
    }

    public function releaseItem(Request $request, DhiranLoan $loan)
    {
        abort_unless((int) $loan->shop_id === (int) auth()->user()->shop_id, 404);

        $data = $request->validate([
            'item_id'         => ['required', 'integer', Rule::exists('dhiran_loan_items', 'id')->where('dhiran_loan_id', $loan->id)],
            'payment_amount'  => 'required|numeric|min:0',
            'payment_method'  => 'nullable|in:cash,upi,card,bank_transfer',
            'condition_note'  => 'nullable|string|max:500',
        ]);

        $item = DhiranLoanItem::where('dhiran_loan_id', $loan->id)->findOrFail($data['item_id']);

        try {
            $this->dhiranService->releaseItem(
                $loan,
                $item,
                (float) $data['payment_amount'],
                $data['payment_method'] ?? 'cash',
                $data['condition_note'] ?? ''
            );
        } catch (\LogicException $e) {
            return redirect()->route('dhiran.show', $loan)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('dhiran.show', $loan)
            ->with('success', 'Item released successfully.');
    }

    public function preClose(Request $request, DhiranLoan $loan)
    {
        abort_unless((int) $loan->shop_id === (int) auth()->user()->shop_id, 404);

        $data = $request->validate([
            'payment_method' => 'nullable|in:cash,upi,card,bank_transfer',
        ]);

        try {
            $this->dhiranService->preCloseLoan($loan, $data['payment_method'] ?? 'cash');
        } catch (\LogicException $e) {
            return redirect()->route('dhiran.show', $loan)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('dhiran.show', $loan)
            ->with('success', 'Loan pre-closed successfully.');
    }

    public function renew(Request $request, DhiranLoan $loan)
    {
        abort_unless((int) $loan->shop_id === (int) auth()->user()->shop_id, 404);

        $data = $request->validate([
            'tenure_months'         => 'nullable|integer|min:1|max:60',
            'interest_rate_monthly' => 'nullable|numeric|min:0|max:10',
            'payment_method'        => 'nullable|in:cash,upi,card,bank_transfer',
        ]);

        try {
            $newLoan = $this->dhiranService->renewLoan(
                $loan,
                isset($data['tenure_months']) ? (int) $data['tenure_months'] : null,
                isset($data['interest_rate_monthly']) ? (float) $data['interest_rate_monthly'] : null,
                $data['payment_method'] ?? 'cash'
            );
        } catch (\LogicException $e) {
            return redirect()->route('dhiran.show', $loan)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('dhiran.show', $newLoan)
            ->with('success', 'Loan renewed successfully.');
    }

    public function close(Request $request, DhiranLoan $loan)
    {
        abort_unless((int) $loan->shop_id === (int) auth()->user()->shop_id, 404);

        try {
            $this->dhiranService->closeLoan($loan);
        } catch (\LogicException $e) {
            return redirect()->route('dhiran.show', $loan)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('dhiran.show', $loan)
            ->with('success', 'Loan closed successfully.');
    }

    /** Cancel a loan still awaiting evidence; its payout and fee are reversed (DhiranService::cancelPendingLoan). */
    public function cancel(Request $request, DhiranLoan $loan)
    {
        abort_unless((int) $loan->shop_id === (int) auth()->user()->shop_id, 404);
        $data = $request->validate(['reason' => 'nullable|string|max:255']);

        try {
            $this->dhiranService->cancelPendingLoan($loan, trim((string) ($data['reason'] ?? '')));
        } catch (\LogicException $e) {
            return redirect()->route('dhiran.show', $loan)->with('error', $e->getMessage());
        }

        return redirect()->route('dhiran.show', $loan)
            ->with('success', 'Loan cancelled. The payout and any fee were reversed in the cash book; the items are marked returned.');
    }

    /* ================================================================
     *  FORFEITURE
     * ================================================================ */

    public function sendNotice(Request $request, DhiranLoan $loan)
    {
        abort_unless((int) $loan->shop_id === (int) auth()->user()->shop_id, 404);

        try {
            $this->dhiranService->sendForfeitureNotice($loan);
        } catch (\LogicException $e) {
            return redirect()->route('dhiran.show', $loan)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('dhiran.show', $loan)
            ->with('success', 'Forfeiture notice sent.');
    }

    /* ================================================================
     *  EVIDENCE ATTACHMENTS (private, shop-scoped) — Phase E2/E3
     * ================================================================ */

    /**
     * Upload a Dhiran evidence file (pledged-item photo, borrower ID proof, or
     * loan document). The owner (loan/item/customer) is verified to belong to the
     * current shop; shop_id is taken from the auth shop, never the request. Files
     * land on the private disk via DhiranAttachmentService.
     */
    public function storeAttachment(Request $request)
    {
        $shopId   = auth()->user()->shop_id;
        $settings = DhiranSettings::getForShop($shopId);
        abort_unless($settings->is_enabled, 403, 'Dhiran module is not enabled.');

        $data = $request->validate([
            'owner_type'    => ['required', 'in:dhiran_loan,dhiran_loan_item,customer'],
            'owner_id'      => ['required', 'integer'],
            'document_type' => ['required', 'in:' . implode(',', \App\Models\Dhiran\DhiranAttachment::DOCUMENT_TYPES)],
            'file'          => ['required', 'file', 'max:8192', 'mimes:jpg,jpeg,png,pdf'],
        ]);

        // Verify the owner exists AND belongs to THIS shop (no raw-id trust).
        $this->assertOwnerInShop($data['owner_type'], (int) $data['owner_id'], $shopId);

        try {
            $attachment = app(\App\Services\Dhiran\DhiranAttachmentService::class)->store(
                $request->file('file'),
                $shopId,
                $data['owner_type'],
                (int) $data['owner_id'],
                $data['document_type'],
                auth()->id(),
            );
        } catch (\LogicException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()->with('success', 'File uploaded.');
    }

    /**
     * Stream a Dhiran attachment after a shop + permission check. Never a public
     * URL: the file is read from the private disk and streamed inline. Route
     * binding is BelongsToShop-scoped; the explicit shop check is belt-and-suspenders.
     */
    public function showAttachment(\App\Models\Dhiran\DhiranAttachment $attachment)
    {
        abort_unless((int) $attachment->shop_id === (int) auth()->user()->shop_id, 404);

        $disk = \Illuminate\Support\Facades\Storage::disk($attachment->file_disk);
        abort_unless($disk->exists($attachment->file_path), 404);

        // Gallery tiles ask for ?thumb=1: a small copy instead of an up-to-8 MB original.
        $path = $attachment->file_path;
        $mime = $attachment->mime_type ?: 'application/octet-stream';
        if (request()->boolean('thumb') && ($thumb = app(\App\Services\Dhiran\DhiranAttachmentService::class)->thumbnail($attachment))) {
            [$path, $mime] = [$thumb, 'image/jpeg'];
        }

        return $disk->response($path, $attachment->original_name, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff'] + ($mime === 'application/pdf' ? [] : ['Content-Security-Policy' => 'sandbox']) /* Chromium's PDF viewer refuses sandboxed documents */, 'inline');
    }

    /** Resolve+verify an attachment owner is in the current shop, or 404. */
    private function assertOwnerInShop(string $ownerType, int $ownerId, int $shopId): void
    {
        $model = match ($ownerType) {
            'dhiran_loan'      => DhiranLoan::find($ownerId),
            'dhiran_loan_item' => DhiranLoanItem::find($ownerId),
            'customer'         => Customer::find($ownerId),
            default            => null,
        };

        abort_if($model === null || (int) $model->shop_id !== $shopId, 404, 'Not found.');
    }

    public function forfeit(Request $request, DhiranLoan $loan)
    {
        abort_unless((int) $loan->shop_id === (int) auth()->user()->shop_id, 404);

        try {
            $this->dhiranService->executeForfeit($loan);
        } catch (\LogicException $e) {
            return redirect()->route('dhiran.show', $loan)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('dhiran.show', $loan)
            ->with('success', 'Loan forfeited. Pledged items marked as forfeited.');
    }

    /* ================================================================
     *  PRINTABLE DOCUMENTS
     * ================================================================ */

    public function receipt(DhiranLoan $loan)
    {
        abort_unless((int) $loan->shop_id === (int) auth()->user()->shop_id, 404);
        $loan->load(['customer', 'items', 'creator']);
        $shop = $loan->shop; // the lender named on the receipt (the view fell back to "Dhiran" without it)

        $show = DhiranSettings::receiptVisibility((int) $loan->shop_id, 'pledge');

        return view('dhiran.receipt', compact('loan', 'shop', 'show'));
    }

    /** The slip stuck on the bag or packet of pledged items kept in the locker. */
    public function tag(DhiranLoan $loan)
    {
        abort_unless((int) $loan->shop_id === (int) auth()->user()->shop_id, 404);
        $loan->load(['customer', 'items']);
        $shop = $loan->shop;

        return view('dhiran.tag', compact('loan', 'shop'));
    }

    public function closureCertificate(DhiranLoan $loan)
    {
        abort_unless((int) $loan->shop_id === (int) auth()->user()->shop_id, 404);
        abort_unless(in_array($loan->status, ['closed', 'forfeited']), 422, 'Loan is still active.');

        $loan->load(['customer', 'items', 'payments']);

        return view('dhiran.closure-certificate', compact('loan'));
    }

    public function forfeitureNotice(DhiranLoan $loan)
    {
        abort_unless((int) $loan->shop_id === (int) auth()->user()->shop_id, 404);
        // The letter says the loan matured and the grace period ended; never print it before that is true.
        if (! $loan->forfeitureNoticeSent() && ! $loan->forfeitureNoticeDue()) {
            return redirect()->route('dhiran.show', $loan)
                ->with('error', 'A forfeiture notice can be issued only after the loan matures and its grace period ends.');
        }
        $loan->load(['customer', 'items']);

        return view('dhiran.forfeiture-notice', compact('loan'));
    }

    public function paymentReceipt(DhiranLoan $loan, DhiranPayment $payment)
    {
        abort_unless((int) $loan->shop_id === (int) auth()->user()->shop_id, 404);
        abort_unless((int) $payment->dhiran_loan_id === (int) $loan->id, 404);

        $loan->load('customer');
        $show = DhiranSettings::receiptVisibility((int) $loan->shop_id, 'payment');

        return view('dhiran.payment-receipt', compact('loan', 'payment', 'show'));
    }

    /* ================================================================
     *  BORROWERS (customer profiles, Dhiran-scoped)
     * ================================================================ */

    /**
     * Borrowers index — only customers of the current shop who have at least one
     * Dhiran loan here. Shop isolation comes from BelongsToShop on Customer +
     * DhiranLoan (both auto-filtered by tenant context); the whereHas keeps the
     * list to actual borrowers (customers with a pledge loan in this shop).
     */
    public function borrowers(Request $request)
    {
        $request->validate(['search' => 'nullable|string|max:100', 'show' => 'nullable|in:active,paid,none']);
        $shopId = (int) auth()->user()->shop_id;

        // Everyone this shop has saved (owner decision 2026-11-08), filtered by where their loans stand.
        $open = DhiranLoan::OPEN;
        $tabs = [
            'active' => fn ($q) => $q->whereHas('dhiranLoans', fn ($l) => $l->whereIn('status', $open)),
            'paid'   => fn ($q) => $q->whereHas('dhiranLoans', fn ($l) => $l->where('status', 'closed'))
                ->whereDoesntHave('dhiranLoans', fn ($l) => $l->whereIn('status', $open)),
            'none'   => fn ($q) => $q->whereDoesntHave('dhiranLoans'),
        ];
        $base = fn () => Customer::query()->where('shop_id', $shopId);
        $tabCounts = ['all' => $base()->count()] + array_map(fn ($scope) => $scope($base())->count(), $tabs);
        $show = $request->input('show');

        $query = ($show ? $tabs[$show]($base()) : $base())
            ->with('profilePhoto')
            ->withCount([
                'dhiranLoans as loans_count',
                'dhiranLoans as active_loans_count'        => fn ($q) => $q->where('status', 'active'),
                'dhiranLoans as pending_evidence_count'    => fn ($q) => $q->where('status', 'pending_evidence'),
                'dhiranLoans as closed_loans_count'        => fn ($q) => $q->where('status', 'closed'),
                'dhiranLoans as forfeited_loans_count'     => fn ($q) => $q->where('status', 'forfeited'),
            ]);

        if ($request->filled('search')) {
            $s = addcslashes((string) $request->search, '%_\\'); // literal match: % _ \ are not wildcards
            $query->where(function ($q) use ($s) {
                $q->where('first_name', 'ilike', "%{$s}%")
                  ->orWhere('last_name', 'ilike', "%{$s}%")
                  ->orWhere('mobile', 'ilike', "%{$s}%")
                  ->orWhere('customer_code', 'ilike', "%{$s}%");
            });
        }

        if ($request->query('export') === 'csv') {
            return app(\App\Services\Dhiran\DhiranExports::class)->customers($query->orderBy('first_name')->orderBy('id'));
        }
        $borrowers = $query->orderBy('first_name')->orderBy('id')->paginate(\App\Services\Dhiran\DhiranListFilters::perPage($request))->withQueryString();

        // Outstanding per borrower (active loans only), scoped to this shop.
        $outstanding = [];
        foreach ($borrowers as $b) {
            $outstanding[$b->id] = (float) DhiranLoan::where('customer_id', $b->id)
                ->whereIn('status', DhiranLoan::OPEN)
                ->sum(DB::raw('outstanding_principal + outstanding_interest + outstanding_penalty'));
        }

        return view('dhiran.borrowers.index', compact('borrowers', 'outstanding', 'tabCounts', 'show'));
    }

    /** Save a customer's details without starting a loan. */
    public function createCustomer()
    {
        abort_unless(DhiranSettings::getForShop(auth()->user()->shop_id)->is_enabled, 403, 'Dhiran module is not enabled.');

        return view('dhiran.borrowers.create');
    }

    /**
     * Borrower profile — full Dhiran-scoped view of one borrower: details, KYC
     * documents, loan summary + table, recent payments, pledged-item history.
     */
    /** Payment type chips on the customer profile: key => [label, stored types]. */
    private const PROFILE_PAYMENT_TYPES = [
        'disbursement' => ['Payout', ['disbursement']],
        'interest'     => ['Interest', ['interest_payment', 'renewal_interest', 'advance_interest']],
        'repayment'    => ['Repayment', ['principal_repayment']],
        'closure'      => ['Pre-closure', ['pre_closure']],
        'fee'          => ['Processing fee', ['processing_fee']],
        'cancellation' => ['Cancellation', ['cancellation', 'fee_refund', 'advance_refund']],
    ];

    public function borrowerProfile(Customer $customer)
    {
        // Route binding is tenant-scoped via BelongsToShop; this is the explicit
        // belt-and-suspenders shop guard (rejects other-shop customers).
        abort_unless((int) $customer->shop_id === (int) auth()->user()->shop_id, 404);
        $shopId = (int) auth()->user()->shop_id;

        $loans = DhiranLoan::where('customer_id', $customer->id)
            ->with('items')
            ->latest()
            ->get();

        $loanIds = $loans->pluck('id')->all();
        $itemIds = $loans->flatMap(fn ($l) => $l->items->pluck('id'))->all();

        // Counts + totals (read straight off the loans; no new financial formulas).
        $summary = [
            'active'          => $loans->where('status', 'active')->count(),
            'pending_evidence' => $loans->where('status', 'pending_evidence')->count(),
            'closed'          => $loans->where('status', 'closed')->count(),
            'renewed'         => $loans->where('status', 'renewed')->count(),
            'forfeited'       => $loans->where('status', 'forfeited')->count(),
            // Only open loans owe anything; a loan renewed before 2026-11-12 still carries its moved principal.
            'principal_outstanding' => (float) $loans->whereIn('status', DhiranLoan::OPEN)->sum(fn ($l) => (float) $l->outstanding_principal),
            'interest_outstanding'  => (float) $loans->whereIn('status', DhiranLoan::OPEN)->sum(fn ($l) => (float) $l->outstanding_interest),
            'total_collected'       => (float) $loans->sum(fn ($l) => (float) $l->total_principal_collected + (float) $l->total_interest_collected),
        ];

        // Owner 2026-11-11: loans filter by status and page; payments are the full history (not the last 25), in
        // pages, filtered by type and by loan. Each list has its own page parameter and lands back on its section.
        $paymentTypes = self::PROFILE_PAYMENT_TYPES;
        $request = request();
        $request->validate([
            'loan_status'  => ['nullable', Rule::in(array_keys(\App\Services\Dhiran\DhiranListFilters::STATUSES))],
            'payment_type' => ['nullable', Rule::in(array_keys($paymentTypes))],
            'payment_loan' => ['nullable', 'integer', Rule::in($loanIds)],
        ]);
        $loanStatus = $request->query('loan_status');
        $loanList = DhiranLoan::where('customer_id', $customer->id)
            ->when($loanStatus, fn ($q, $s) => $q->where('status', $s))
            ->latest()->orderByDesc('id')
            ->paginate(10, ['*'], 'loans_page')->withQueryString()->fragment('loans');

        $paymentLoan = $request->query('payment_loan');
        $paymentType = $request->query('payment_type');
        $paymentsOf = fn () => DhiranPayment::whereIn('dhiran_loan_id', $loanIds ?: [0])
            ->when($paymentLoan, fn ($q, $id) => $q->where('dhiran_loan_id', $id));
        $filteredPayments = fn () => $paymentsOf()->when($paymentType, fn ($q, $t) => $q->whereIn('type', $paymentTypes[$t][1]));

        if ($request->query('export') === 'payments') {
            return app(\App\Services\Dhiran\DhiranExports::class)->payments(
                $filteredPayments()->latest('payment_date')->orderByDesc('id'),
                'payments-' . ($customer->customer_code ?: $customer->id) . '-' . now()->toDateString()
            );
        }

        $typeCounts = $paymentsOf()->toBase()->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type');
        $paymentTypeCounts = array_map(fn ($def) => (int) collect($def[1])->sum(fn ($t) => $typeCounts[$t] ?? 0), $paymentTypes);
        $paymentSummary = (array) $filteredPayments()->toBase()->selectRaw(
            "coalesce(sum(case when type = 'disbursement' then 0 else amount end), 0) as received,"
            . ' coalesce(sum(interest_component), 0) as interest, coalesce(sum(penalty_component), 0) as penalty'
        )->first();
        $hasPayments = array_sum($paymentTypeCounts) > 0;
        $payments = $filteredPayments()
            ->with('loan:id,loan_number')
            ->latest('payment_date')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'payments_page')->withQueryString()->fragment('payments');

        // KYC / evidence documents: borrower-owned + this borrower's loans + items.
        $attachments = \App\Models\Dhiran\DhiranAttachment::query()
            ->where(function ($q) use ($customer, $loanIds, $itemIds) {
                $q->where(fn ($w) => $w->where('owner_type', \App\Models\Dhiran\DhiranAttachment::OWNER_CUSTOMER)->where('owner_id', $customer->id))
                  ->orWhere(fn ($w) => $w->where('owner_type', \App\Models\Dhiran\DhiranAttachment::OWNER_LOAN)->whereIn('owner_id', $loanIds ?: [0]))
                  ->orWhere(fn ($w) => $w->where('owner_type', \App\Models\Dhiran\DhiranAttachment::OWNER_ITEM)->whereIn('owner_id', $itemIds ?: [0]));
            })
            ->latest('id')
            ->get();

        // Item ids that have a photo (for the "photo" indicator on the items list).
        $itemsWithPhoto = $attachments
            ->where('owner_type', \App\Models\Dhiran\DhiranAttachment::OWNER_ITEM)
            ->where('document_type', 'item_photo')
            ->pluck('owner_id')->unique()->all();

        // Masked Aadhaar / PAN, if any loan captured KYC. Aadhaar is already stored
        // masked; never show a full number.
        $kycAadhaar = $loans->pluck('kyc_aadhaar')->filter()->first();
        $kycPan     = $loans->pluck('kyc_pan')->filter()->first();

        return view('dhiran.borrowers.show', compact(
            'customer', 'loans', 'summary', 'payments', 'attachments', 'itemsWithPhoto', 'kycAadhaar', 'kycPan',
            'loanList', 'loanStatus', 'paymentTypes', 'paymentTypeCounts', 'paymentType', 'paymentLoan', 'paymentSummary', 'hasPayments'
        ));
    }

    /* ================================================================
     *  PLEDGED ITEM DETAIL
     * ================================================================ */

    /**
     * One pledged item: details, valuation (stored values only), evidence,
     * linked borrower + loan, and a simple status history. Shop-scoped.
     */
    public function itemDetail(DhiranLoanItem $item)
    {
        // Route binding is tenant-scoped via BelongsToShop; explicit backstop.
        abort_unless((int) $item->shop_id === (int) auth()->user()->shop_id, 404);

        $item->load(['loan.customer']);
        $loan = $item->loan;

        // Evidence: attachments owned by this item, plus loan-level item photos /
        // valuation proofs / loan documents that belong to the same loan (we never
        // fake item ownership — loan-owned rows are shown as loan documents).
        $attachments = \App\Models\Dhiran\DhiranAttachment::query()
            ->where(function ($q) use ($item, $loan) {
                $q->where(fn ($w) => $w->where('owner_type', \App\Models\Dhiran\DhiranAttachment::OWNER_ITEM)->where('owner_id', $item->id));
                if ($loan) {
                    $q->orWhere(fn ($w) => $w->where('owner_type', \App\Models\Dhiran\DhiranAttachment::OWNER_LOAN)
                        ->where('owner_id', $loan->id)
                        ->whereIn('document_type', ['item_photo', 'valuation_proof', 'loan_document']));
                }
            })
            ->latest('id')
            ->get();

        return view('dhiran.items.show', compact('item', 'loan', 'attachments'));
    }

    /* ================================================================
     *  SETTINGS
     * ================================================================ */

    public function settings()
    {
        $shopId   = auth()->user()->shop_id;
        $settings = DhiranSettings::getForShop($shopId);

        return view('dhiran.settings', compact('settings'));
    }

    public function updateSettings(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        // Exactly the fields the settings page sends; each is a setting that new loans read.
        $data = $request->validate([
            'default_interest_rate_monthly' => 'required|numeric|min:0|max:10',
            'default_interest_type'         => 'required|in:flat,daily,compound',
            'default_penalty_rate_monthly'  => 'required|numeric|min:0|max:10',
            'default_min_interest_months'   => 'required|integer|min:0|max:12',
            'default_ltv_ratio'             => 'required|numeric|min:1|max:100',
            'high_value_threshold'          => 'required|numeric|min:0|max:999999999999.99', // decimal(14,2)
            'high_value_ltv_ratio'          => 'required|numeric|min:1|max:100',
            'min_loan_amount'               => 'required|numeric|min:0|max:999999999999.99',
            'max_loan_amount'               => 'required|numeric|gte:min_loan_amount|max:999999999999.99',
            'processing_fee_type'           => 'required|in:flat,percent',
            'processing_fee_value'          => ['required', 'numeric', 'min:0', 'max:' . ($request->input('processing_fee_type') === 'percent' ? 100 : 99999999.99)], // decimal(10,2); a percentage is at most 100
            'default_tenure_months'         => 'required|integer|min:1|max:60',
            'default_min_lock_months'       => 'required|integer|min:0|max:60',
            'grace_period_days'             => 'required|integer|min:0|max:90',
            'forfeiture_notice_days'        => 'required|integer|min:0|max:365',
            'loan_number_prefix'            => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9\-_]{1,10}$/'],
            'sms_reminders_enabled'         => 'nullable|boolean',
            'reminder_days_before_due'      => 'nullable|integer|min:1|max:30',
            'advance_interest_enabled'      => 'nullable|boolean',
            'advance_interest_default_months' => 'nullable|integer|min:1|max:3',
        ]);

        $data['sms_reminders_enabled'] = $request->boolean('sms_reminders_enabled');
        $data['advance_interest_enabled'] = $request->boolean('advance_interest_enabled');
        $data['advance_interest_default_months'] = $data['advance_interest_default_months'] ?? 1;

        $settings = DhiranSettings::getForShop($shopId);
        $settings->update($data);

        return redirect()->route('dhiran.settings')
            ->with('success', 'Settings updated.');
    }

    /* ================================================================
     *  REPORTS
     * ================================================================ */

    public function reports(Request $request)
    {
        $type = $request->input('type');

        return match ($type) {
            'active'        => $this->reportActive($request),
            'overdue'       => $this->reportOverdue($request),
            'interest'      => $this->reportInterest($request),
            'forfeiture'    => $this->reportForfeiture($request),
            'cashbook'      => $this->reportCashbook($request),
            'profitability' => $this->reportProfitability($request),
            default         => view('dhiran.reports.index'),
        };
    }

    /**
     * A report's card figures over EVERY loan the query matches, not just the page on screen (the cards used to add
     * up only the first 20 rows).
     *
     * @return array{count: int, principal: float, outstanding: float, interest: float, penalty: float, fees: float}
     */
    private function loanTotals(\Illuminate\Database\Eloquent\Builder $query): array
    {
        $row = (clone $query)->reorder()->toBase()->selectRaw(
            'count(*) as count, coalesce(sum(principal_amount), 0) as principal,'
            // Only open loans owe anything (DhiranLoan::OPEN; a pre-fix renewed loan still carries moved principal).
            . " coalesce(sum(case when status in ('pending_evidence', 'active') then coalesce(outstanding_principal, 0) + coalesce(outstanding_interest, 0) + coalesce(outstanding_penalty, 0) end), 0) as outstanding,"
            . ' coalesce(sum(total_interest_collected), 0) as interest, coalesce(sum(total_penalty_collected), 0) as penalty,'
            . ' coalesce(sum(processing_fee), 0) as fees'
        )->first();

        return ['count' => (int) $row->count] + array_map('floatval', array_diff_key((array) $row, ['count' => 0]));
    }

    public function reportActive(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $filters = \App\Services\Dhiran\DhiranListFilters::fromRequest($request);
        $query = $filters->applyToLoans(DhiranLoan::where('shop_id', $shopId)->active());
        if ($request->query('export') === 'csv') {
            return app(\App\Services\Dhiran\DhiranExports::class)->loans($query->latest('loan_date')->orderByDesc('id'), 'active-loans-' . now()->toDateString());
        }
        $totals = $this->loanTotals($query);
        $loans = $query->with('customer')->latest('loan_date')->orderByDesc('id')->paginate($filters::perPage($request))->withQueryString();

        return view('dhiran.reports.active', compact('loans', 'totals', 'filters'));
    }

    public function reportOverdue(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $filters = \App\Services\Dhiran\DhiranListFilters::fromRequest($request);
        $query = $filters->applyToLoans(DhiranLoan::where('shop_id', $shopId)->overdue(), null);
        if ($request->query('export') === 'csv') {
            return app(\App\Services\Dhiran\DhiranExports::class)->loans($query->orderBy('maturity_date')->orderBy('id'), 'overdue-loans-' . now()->toDateString());
        }
        $totals = $this->loanTotals($query);
        $loans = $query->with('customer')->orderBy('maturity_date')->orderBy('id')->paginate($filters::perPage($request))->withQueryString();

        return view('dhiran.reports.overdue', compact('loans', 'totals', 'filters'));
    }

    public function reportInterest(Request $request)
    {
        $filters = \App\Services\Dhiran\DhiranListFilters::fromRequest($request, 'this_month');
        $shopId = auth()->user()->shop_id;
        [$from, $to] = [$filters->from(), $filters->to()];

        // Filter by payment_date (the accounting date for the transaction),
        // NOT created_at. A back-dated payment recorded today belongs in the
        // fiscal period of payment_date.
        // Payments that carried interest or penalty (the report's subject); the cards cover the whole period, not
        // only the rows on this page. Search narrows to matching loans or customers.
        $period = fn () => $filters->applyPeriod(DhiranPayment::where('shop_id', $shopId), 'payment_date')
            ->when($filters->get('search'), fn ($q) => $q->whereHas('loan', fn ($l) => $filters->applySearch($l)));
        $rows = fn () => $period()->where(fn ($q) => $q->where('interest_component', '>', 0)->orWhere('penalty_component', '>', 0));
        if ($request->query('export') === 'csv') {
            return app(\App\Services\Dhiran\DhiranExports::class)->payments($rows()->latest('payment_date')->orderByDesc('id'), "interest-{$from}-to-{$to}");
        }
        $payments = $rows()
            ->with(['loan', 'loan.customer'])
            ->latest('payment_date')->orderByDesc('id')
            ->paginate($filters::perPage($request))
            ->withQueryString();

        // Money paid back out (a cancelled loan returning its advance interest) counts negative, so it nets off the interest it reverses.
        $sgn = "(case when direction = 'out' then -1 else 1 end)";
        $totalInterest = (float) $period()->toBase()->selectRaw("coalesce(sum(interest_component * {$sgn}), 0) as v")->value('v');
        $totalPenalty  = (float) $period()->toBase()->selectRaw("coalesce(sum(penalty_component * {$sgn}), 0) as v")->value('v');
        // The table footer covers every listed payment, not only this page.
        $footer = (array) $rows()->toBase()->selectRaw("coalesce(sum(amount * {$sgn}), 0) as amount, coalesce(sum(interest_component * {$sgn}), 0) as interest,"
            . " coalesce(sum(penalty_component * {$sgn}), 0) as penalty, coalesce(sum(principal_component * {$sgn}), 0) as principal")->first();

        return view('dhiran.reports.interest', compact('payments', 'totalInterest', 'totalPenalty', 'footer', 'from', 'to', 'filters'));
    }

    public function reportForfeiture(Request $request)
    {
        $shopId = auth()->user()->shop_id;
        $filters = \App\Services\Dhiran\DhiranListFilters::fromRequest($request);

        if ($request->query('export') === 'csv') {
            return app(\App\Services\Dhiran\DhiranExports::class)->loans(
                $filters->applyToLoans(DhiranLoan::where('shop_id', $shopId)->where('status', 'forfeited'), 'forfeited_at')->latest('forfeited_at')->orderByDesc('id'),
                'forfeited-loans-' . now()->toDateString()
            );
        }
        $query = $filters->applyToLoans(DhiranLoan::where('shop_id', $shopId)->where('status', 'forfeited'), 'forfeited_at');
        $totals = $this->loanTotals($query) + [
            'items' => DhiranLoanItem::whereIn('dhiran_loan_id', (clone $query)->reorder()->select('id'))->count(),
        ];
        $loans = $query->with('customer')->withCount('items')
            ->latest('forfeited_at')->orderByDesc('id')
            ->paginate($filters::perPage($request))
            ->withQueryString();

        return view('dhiran.reports.forfeiture', compact('loans', 'filters', 'totals'));
    }

    public function reportCashbook(Request $request)
    {
        $filters = \App\Services\Dhiran\DhiranListFilters::fromRequest($request, 'this_month');
        $shopId = auth()->user()->shop_id;
        [$from, $to] = [$filters->from(), $filters->to()];

        // Totals cover the whole period; the opening balance is every entry before it; each page's running balance
        // starts where the previous page ended (it used to restart at 0 and total only the 30 rows shown).
        // The in/out filter narrows the rows only; the balances stay those of the whole cash book.
        $signed = DB::raw("CASE WHEN type = 'in' THEN amount ELSE -amount END");
        $all = fn () => \App\Models\Dhiran\DhiranCashEntry::where('shop_id', $shopId);
        $period = fn () => $filters->applyPeriod($all(), 'entry_date');

        $opening = (float) $all()->where('entry_date', '<', $from)->sum($signed);
        $totals = [
            'opening' => $opening,
            'in'      => (float) $period()->where('type', 'in')->sum('amount'),
            'out'     => (float) $period()->where('type', 'out')->sum('amount'),
        ];
        $totals['net'] = $totals['in'] - $totals['out'];
        $totals['closing'] = $opening + $totals['net'];

        if ($request->query('export') === 'csv') {
            return app(\App\Services\Dhiran\DhiranExports::class)->cashbook(
                $period()->when($filters->get('cash'), fn ($q, $t) => $q->where('type', $t))->orderBy('entry_date')->orderBy('id'),
                $totals, ! $filters->get('cash'), $from, $to
            );
        }
        $entries = $period()->when($filters->get('cash'), fn ($q, $t) => $q->where('type', $t))
            ->with('loan')->orderBy('entry_date')->orderBy('id')->paginate($filters::perPage($request, 50))->withQueryString();

        $first = $entries->first();
        $pageStartBalance = $opening + ($first ? (float) $period()
            ->where(fn ($q) => $q->where('entry_date', '<', $first->entry_date)
                ->orWhere(fn ($w) => $w->where('entry_date', $first->entry_date)->where('id', '<', $first->id)))
            ->sum($signed) : 0);

        return view('dhiran.reports.cashbook', compact('entries', 'from', 'to', 'totals', 'pageStartBalance', 'filters'));
    }

    public function reportProfitability(Request $request)
    {
        $filters = \App\Services\Dhiran\DhiranListFilters::fromRequest($request, 'this_month');
        $shopId = auth()->user()->shop_id;
        [$from, $to] = [$filters->from(), $filters->to()];

        $query = $filters->applyToLoans(DhiranLoan::where('shop_id', $shopId));
        if ($request->query('export') === 'csv') {
            return app(\App\Services\Dhiran\DhiranExports::class)->loans($query->latest('loan_date')->orderByDesc('id'), "profitability-{$from}-to-{$to}");
        }
        $totals = $this->loanTotals($query);
        $loans = $query->with('customer')->latest('loan_date')->orderByDesc('id')->paginate($filters::perPage($request))->withQueryString();

        return view('dhiran.reports.profitability', compact('loans', 'from', 'to', 'totals', 'filters'));
    }
}
