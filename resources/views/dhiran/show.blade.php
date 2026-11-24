<x-dhiran-layout title="Loan Detail">
    @vite('resources/css/dhiran-detail.css')
    @php
        // Money is always shown with lakh grouping and both decimals; nothing is rounded away.
        $rupee = fn ($n) => '₹' . \App\Support\IndianNumber::format($n, 2);
        $user = auth()->user();
        $service = app(\App\Services\DhiranService::class);
        $isActive = $loan->status === 'active';
        $isPending = $loan->status === 'pending_evidence';
        $canCreate = $user->can('dhiran.create');
        $canPay = $isActive && $user->can('dhiran.pay');
        $canRelease = $isActive && $user->can('dhiran.release');
        $canRenew = $isActive && $user->can('dhiran.renew');
        $canForfeit = $isActive && $user->can('dhiran.forfeit');
        $noticeDue = $canForfeit && $loan->forfeitureNoticeDue();
        $canExecute = $canForfeit && $loan->canExecuteForfeit();
        $removableOld = $loan->brought_forward && $isActive && $loan->payments->isEmpty();
        $canRemove = $removableOld && $canCreate;
        $hasMore = $canRelease || $canRenew || $canPay || $noticeDue || $canExecute || $canRemove;
        $canView = $user->can('dhiran.view');
        $docs = $canView && ($loan->forfeitureNoticeSent() || $loan->status === 'closed');
        $hasRail = $canPay || $hasMore || ($isPending && $canCreate) || $docs;
        $through = \Illuminate\Support\Carbon::parse($loan->interest_accrued_through ?? $loan->loan_date)->format('d M Y');
        $owing = fn ($v) => (float) $v > 0 ? 'is-owed' : '';
    @endphp

    <x-dhiran.page-header>
        <div class="min-w-0">
            <h1 class="page-title">Loan {{ $loan->loan_number }}</h1>
        </div>
        <div class="page-actions">
            <x-dhiran.btn-collapse icon="arrow-left" label="All Loans" :href="route('dhiran.loans')" />
            <x-dhiran.btn-collapse icon="printer" label="Pledge Receipt" :href="route('dhiran.receipt', $loan)" target="_blank" data-dh-mobile-pin="true" />
            @if($loan->items->where('status', 'pledged')->isNotEmpty())
                <x-dhiran.btn-collapse icon="package" label="Item Tag" :href="route('dhiran.tag', $loan)" target="_blank" />
            @endif
        </div>
    </x-dhiran.page-header>

    <div class="content-inner dhiran-show-root dd {{ $isPending ? 'dd--pending' : '' }}">
        <div class="dd-top {{ $hasRail ? 'dd-top--rail' : '' }}">

            <div class="dd-a">
            {{-- Who and what state --}}
            <div class="dd-id">
                <x-dhiran.status :value="$loan->status" />
                @if($loan->customer)
                    <a href="{{ route('dhiran.customer-loans', $loan->customer) }}" class="dd-who"><x-icon name="user" size="16" />{{ $loan->customer->name }}</a>
                @endif
            </div>
            @if($loan->brought_forward || $loan->renewedFrom || ($loan->renewals ?? collect())->isNotEmpty())
                <div class="dd-chain">
                    {{-- Renewal chain: the old loan keeps its figures as a record; its balance moved to the new loan. --}}
                    @if($loan->brought_forward)
                        <p data-brought-forward-note>Brought forward from the paper book; lent on {{ $loan->loan_date->format('d M Y') }}.</p>
                    @endif
                    @if($loan->renewedFrom)
                        <p>Renewed from <a href="{{ route('dhiran.show', $loan->renewedFrom) }}">{{ $loan->renewedFrom->loan_number }}</a></p>
                    @endif
                    @foreach($loan->renewals ?? [] as $renewal)
                        <p>Renewed into <a href="{{ route('dhiran.show', $renewal) }}">{{ $renewal->loan_number }}</a>; its balance moved there.</p>
                    @endforeach
                </div>
            @endif

            {{-- Balance: total first, then what it is made of --}}
            <section class="dd-card" aria-labelledby="dd-bal-h">
                <h2 id="dd-bal-h" class="dd-sr">Balance</h2>
                <div class="dd-total">
                    <span class="dd-fig-label">Total Outstanding</span>
                    <span class="dd-total-value">{{ $rupee($loan->total_outstanding ?? 0) }}</span>
                </div>
                <dl class="dd-breakdown">
                    <div><dt class="dd-fig-label">Outstanding Principal</dt><dd>{{ $rupee($loan->outstanding_principal ?? $loan->principal_amount) }}</dd></div>
                    <div><dt class="dd-fig-label">Outstanding Interest</dt><dd>{{ $rupee($loan->outstanding_interest ?? 0) }}</dd></div>
                    <div><dt class="dd-fig-label">Outstanding Penalty</dt><dd class="{{ $owing($loan->outstanding_penalty) }}">{{ $rupee($loan->outstanding_penalty ?? 0) }}</dd></div>
                    <div><dt class="dd-fig-label">Principal</dt><dd>{{ $rupee($loan->principal_amount) }}</dd></div>
                </dl>
            </section>

            {{-- Paid in advance, month by month: when the next installment falls due and what it is --}}
            @if($installment ?? null)
                @php $late = $installment['days'] < 0; @endphp
                <section class="dd-card" aria-labelledby="dd-inst-h">
                    <h2 id="dd-inst-h" class="dd-h">Interest installment</h2>
                    <dl class="dd-breakdown">
                        <div><dt class="dd-fig-label">Next due</dt><dd>{{ $installment['due']->format('d M Y') }}</dd></div>
                        <div><dt class="dd-fig-label">Amount to collect</dt><dd>{{ $rupee($installment['amount']) }}</dd></div>
                        <div><dt class="dd-fig-label">Status</dt><dd class="{{ $late ? 'is-owed' : '' }}">
                            @if($late) Late by {{ abs($installment['days']) }} day{{ abs($installment['days']) === 1 ? '' : 's' }}
                            @elseif($installment['days'] === 0) Due today
                            @else Due in {{ $installment['days'] }} day{{ $installment['days'] === 1 ? '' : 's' }} @endif
                        </dd></div>
                    </dl>
                    <p class="dd-reason">Paid in advance: each installment pays the month that starts on its due date{{ $late ? '. The late days are included in the amount' : '' }}.</p>
                    @if($canPay ?? false)
                        <button type="button" class="btn btn-primary btn-sm" @disabled($installment['days'] > 7) onclick="document.getElementById('installmentModal').showModal()"><x-icon name="dollar-sign" size="16" />Collect installment</button>
                    @endif
                </section>
            @endif

            {{-- Evidence gate: a pending-evidence loan must have a pledged-item photo AND a borrower ID proof before it can be activated. --}}
            @if($isPending)
                <section class="dd-gate" aria-labelledby="dd-gate-h">
                    <h2 id="dd-gate-h" class="dd-h">Evidence required before activation</h2>
                    <p>This loan is not active yet. Upload the required evidence below, then activate it.</p>
                    <ul class="dd-checks">
                        @foreach(['item_photo' => 'Pledged item photo', 'id_proof' => 'Borrower ID proof'] as $key => $label)
                            <li class="{{ ($evidence[$key] ?? false) ? 'is-done' : '' }}">
                                <x-icon :name="($evidence[$key] ?? false) ? 'check' : 'circle'" size="20" stroke-width="2.5" />
                                {{ $label }}<span class="dd-sr">{{ ($evidence[$key] ?? false) ? ' (uploaded)' : ' (missing)' }}</span>
                            </li>
                        @endforeach
                    </ul>
                    @if(! ($evidence['ok'] ?? false))
                        <a href="#evidence">Go to Evidence &amp; Documents</a>
                    @endif
                </section>
            @elseif($isActive && ! ($evidence['ok'] ?? true))
                {{-- An active loan without its photo or ID proof (an old loan brought in active): flagged, not blocking. --}}
                <section class="dd-gate" data-evidence-missing>
                    <p>Evidence missing: upload {{ collect(['the pledged-item photo' => ! ($evidence['item_photo'] ?? false), 'the borrower ID proof' => ! ($evidence['id_proof'] ?? false)])->filter()->keys()->implode(' and ') }} in Evidence &amp; Documents below.</p>
                    <a href="#evidence">Go to Evidence &amp; Documents</a>
                </section>
            @endif

            </div>

            {{-- Actions: the next step for this loan's state stays in view; the rest sit under "More actions" (always open in the side rail). --}}
            @if($hasRail)
            <section class="dd-card dd-actions dd-rail" aria-labelledby="dd-act-h">
                <h2 id="dd-act-h" class="dd-h">Actions</h2>
                @if($isPending && $canCreate)
                    <div class="dd-act-main">
                        @if($evidence['ok'] ?? false)
                            <form method="POST" action="{{ route('dhiran.activate-loan', $loan) }}" data-turbo-frame="_top"
                                  onsubmit="return confirm('Activate this loan? Interest will start accruing.');">
                                @csrf
                                <button type="submit" class="btn btn-success btn-sm" data-turbo-submits-with="Activating…">Activate Loan</button>
                            </form>
                        @else
                            <button type="button" class="btn btn-sm" disabled>Activate Loan</button>
                        @endif
                    </div>
                    @unless($evidence['ok'] ?? false)
                        <p class="dd-reason">Upload both required documents in the Evidence &amp; Documents section below to enable activation.</p>
                    @endunless
                    <button type="button" class="btn btn-danger btn-sm" onclick="document.getElementById('cancelLoanModal').showModal()">Cancel loan</button>
                @endif

                @if($canPay)
                    <div class="dd-act-main">
                        <button type="button" class="btn btn-primary btn-sm" onclick="document.getElementById('payInterestModal').showModal()"><x-icon name="dollar-sign" size="16" />Pay Interest</button>
                        <button type="button" class="btn btn-dark btn-sm" onclick="document.getElementById('repayModal').showModal()"><x-icon name="square-check-big" size="16" />Repay</button>
                    </div>
                @endif

                @if($hasMore)
                <div class="dd-more" x-data="{ open: false }" @keydown.escape="if (open) { open = false; $refs.toggle.focus() }" @click.outside="open = false">
                    <button type="button" class="btn btn-secondary btn-sm dd-more-toggle" x-ref="toggle" :aria-expanded="open.toString()" aria-controls="dd-more-list" aria-expanded="false"
                            @click="open = !open; if (open) $nextTick(() => $refs.list.querySelector('a, button')?.focus())">
                        More actions <x-icon name="chevron-down" size="16" />
                    </button>
                    <div id="dd-more-list" class="dd-more-list" x-ref="list" :class="{ 'is-open': open }" @click="if ($event.target.closest('button:not([type=submit])')) open = false">
                        @if($canRelease || $canRenew)
                            <p class="dd-group">Release and renew</p>
                            @if($canRelease)
                                <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('releaseItemModal').showModal()"><x-icon name="package-open" size="16" />Release Item</button>
                            @endif
                            @if($canRenew)
                                <button type="button" class="btn btn-secondary btn-sm" onclick="document.getElementById('renewModal').showModal()"><x-icon name="rotate-cw" size="16" />Renew</button>
                            @endif
                        @endif
                        @if($canPay || $noticeDue || $canExecute || $canRemove)
                            <p class="dd-group">Close out</p>
                            @if($canPay)
                                <button type="button" class="btn btn-danger btn-sm" onclick="document.getElementById('precloseModal').showModal()"><x-icon name="circle-x" size="16" />Pre-Close</button>
                            @endif
                            @if($noticeDue)
                                <form method="POST" action="{{ route('dhiran.send-notice', $loan) }}" data-turbo-frame="_top">
                                    @csrf <x-dhiran.once />
                                    <button type="submit" class="btn btn-secondary btn-sm"><x-icon name="mail" size="16" />Send Notice</button>
                                </form>
                            @endif
                            {{-- Execute Forfeit: only after the notice is sent AND the notice period has elapsed (mirrors the service guard). Serious terminal action: explicit confirm. --}}
                            @if($canExecute)
                                <form method="POST" action="{{ route('dhiran.forfeit', $loan) }}" data-turbo-frame="_top"
                                      onsubmit="return confirm('Execute forfeiture for {{ $loan->loan_number }}? This permanently forfeits all pledged items and writes off the outstanding balance. This cannot be undone.');">
                                    @csrf <x-dhiran.once />
                                    <button type="submit" class="btn btn-danger btn-sm"><x-icon name="trash-2" size="16" />Execute Forfeit</button>
                                </form>
                            @endif
                            @if($canRemove)
                                <button type="button" class="btn btn-danger btn-sm" onclick="document.getElementById('cancelLoanModal').showModal()">Remove old loan (entered by mistake)</button>
                            @endif
                        @endif
                    </div>
                </div>
                @endif

                @if($docs)
                    <div class="dd-docs">
                        {{-- Print Forfeiture Notice: visible once the notice is sent (and stays after forfeiture, where it is legally useful). --}}
                        @if($loan->forfeitureNoticeSent())
                            <a href="{{ route('dhiran.forfeiture-notice', $loan) }}" target="_blank" class="btn btn-secondary btn-sm"><x-icon name="file" size="16" />Print Forfeiture Notice</a>
                        @endif
                        {{-- Print Closure Certificate: only for closed loans. --}}
                        @if($loan->status === 'closed')
                            <a href="{{ route('dhiran.closure-certificate', $loan) }}" target="_blank" class="btn btn-secondary btn-sm"><x-icon name="circle-check" size="16" />Print Closure Certificate</a>
                        @endif
                    </div>
                @endif
            </section>
            @endif

            {{-- Loan terms --}}
            <section class="dd-card dd-b" aria-labelledby="dd-terms-h">
                <div class="dd-head"><h2 id="dd-terms-h" class="dd-h">Loan terms</h2></div>
                <dl class="dd-pairs">
                    <div><dt>Loan Date</dt><dd>{{ $loan->loan_date->format('d M Y') }}</dd></div>
                    <div><dt>Maturity Date</dt><dd>{{ $loan->maturity_date ? $loan->maturity_date->format('d M Y') : '---' }}</dd></div>
                    <div><dt>Tenure</dt><dd>{{ $loan->tenure_months }} months</dd></div>
                    <div><dt>Interest Rate</dt><dd>{{ $loan->interest_rate_monthly }}% / month</dd></div>
                    <div><dt>Interest Type</dt><dd>{{ ucfirst($loan->interest_type) }}</dd></div>
                    <div><dt>Interest counted to</dt><dd>{{ $through }}</dd></div>
                    <div><dt>LTV %</dt><dd>{{ number_format($loan->ltv_percent ?? 0, 1) }}%</dd></div>
                    @if((int) $loan->min_lock_months > 0)
                        <div><dt>Lock period</dt><dd>{{ $loan->min_lock_months }} {{ \Illuminate\Support\Str::plural('month', $loan->min_lock_months) }}<small>{{ $loan->isInLockPeriod() ? 'In the lock period now' : 'Lock period is over' }}</small></dd></div>
                    @endif
                    @if((int) $loan->min_interest_months > 0)
                        <div><dt>Minimum interest</dt><dd>{{ $loan->min_interest_months }} {{ \Illuminate\Support\Str::plural('month', $loan->min_interest_months) }}<small>{{ $rupee($loan->minimumInterestAmount()) }}</small></dd></div>
                    @endif
                    @if((float) $loan->gold_rate_on_date > 0)
                        <div><dt>Gold rate (at pledge)</dt><dd>{{ $rupee($loan->gold_rate_on_date) }}</dd></div>
                    @endif
                    @if((float) ($loan->silver_rate_on_date ?? 0) > 0)
                        <div><dt>Silver rate (at pledge)</dt><dd>{{ $rupee($loan->silver_rate_on_date) }}</dd></div>
                    @endif
                    @if($loan->kyc_aadhaar)
                        <div><dt>Aadhaar Number</dt><dd>{{ $loan->kyc_aadhaar }}</dd></div>
                    @endif
                    @if($loan->kyc_pan)
                        <div><dt>PAN</dt><dd>{{ $loan->kyc_pan }}</dd></div>
                    @endif
                </dl>
            </section>
        </div>

        {{-- Pledged Items --}}
        <section class="dd-card dd-tablecard" aria-labelledby="dd-items-h">
            <div class="dd-head"><h2 id="dd-items-h" class="dd-h">Pledged Items</h2></div>
            <table class="dd-table dd-t-rec">
                <thead>
                    <tr>
                        <th scope="col" class="dh-sno-th">#</th>
                        <th scope="col">Description</th>
                        <th scope="col">Metal</th>
                        <th scope="col" class="num">Gross Wt</th>
                        <th scope="col" class="num">Net Wt</th>
                        <th scope="col">Purity</th>
                        <th scope="col" class="num">Fine Wt</th>
                        <th scope="col" class="num">Market Val</th>
                        <th scope="col" class="num">Loan Val</th>
                        <th scope="col">Status</th>
                        <th scope="col">HUID</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($loan->items ?? [] as $item)
                        <tr>
                            <x-dhiran.sno :rows="null" :n="$loop->iteration" />
                            <td data-cell="title" data-label="Description" class="strong"><a href="{{ route('dhiran.items.show', $item) }}">{{ $item->description }}</a></td>
                            <td data-label="Metal" class="capitalize">{{ $item->metal_type ?? 'gold' }}</td>
                            <td data-label="Gross Wt" class="num">{{ number_format($item->gross_weight, 3) }}g</td>
                            <td data-label="Net Wt" class="num">{{ number_format($item->net_metal_weight, 3) }}g</td>
                            <td data-label="Purity">@if(($item->metal_type ?? "gold") === "other")—@else{{ rtrim(rtrim((string) $item->purity, "0"), ".") }}{{ ($item->metal_type ?? "gold") === "silver" ? "" : "K" }}@endif</td>
                            <td data-label="Fine Wt" class="num">{{ number_format($item->fine_weight, 3) }}g</td>
                            <td data-label="Market Val" class="num">{{ $rupee($item->market_value) }}</td>
                            <td data-label="Loan Val" class="num strong">{{ $rupee($item->loan_value) }}</td>
                            <td data-label="Status">
                                <x-dhiran.status :value="$item->status ?? 'pledged'" />
                                @if(($item->status ?? '') === 'released' && $item->released_at)
                                    {{-- the log of the release: when, by whom and the condition note typed at the counter --}}
                                    <span class="dd-sub">{{ $item->released_at->format('d M Y') }}{{ $item->releasedBy?->name ? ' · ' . $item->releasedBy->name : '' }}</span>
                                    @if($item->release_condition_note)<span class="dd-sub">{{ $item->release_condition_note }}</span>@endif
                                @endif
                            </td>
                            <td data-label="HUID">{{ $item->huid ?? '---' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="is-empty">
                                @if($loan->status === 'renewed' && $loan->renewals->isNotEmpty())
                                    Items moved to {{ $loan->renewals->first()->loan_number }} with the renewal.
                                @else
                                    No items recorded
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        {{-- Evidence & Documents (private, shop-scoped attachments) --}}
        <section id="evidence" class="dd-card dd-card-pad dd-evidence" aria-labelledby="dd-evid-h">
            <h2 id="dd-evid-h" class="dd-h">Evidence &amp; Documents</h2>
            <p class="dd-sub">Pledged-item photos, borrower ID proof, and loan documents. Files are private to your shop.</p>

            <x-dhiran.evidence-gallery :attachments="$attachments ?? collect()" />

            @can('dhiran.create')
            @if($errors->has('file'))
                <p class="dd-error" role="alert">{{ $errors->first('file') }}</p>
            @endif
            <form method="POST" action="{{ route('dhiran.attachments.store') }}" enctype="multipart/form-data" data-turbo-frame="_top" class="dh-upload-form dd-upload">
                @csrf
                <input type="hidden" name="owner_type" value="dhiran_loan">
                <input type="hidden" name="owner_id" value="{{ $loan->id }}">
                <div>
                    <label for="ev-type">Document type</label>
                    <select id="ev-type" name="document_type" required class="dh-form-control dh-native-select">
                        <option value="item_photo">Pledged item photo</option>
                        <option value="id_proof_front">ID proof (front)</option>
                        <option value="id_proof_back">ID proof (back)</option>
                        <option value="address_proof">Address proof</option>
                        <option value="borrower_photo">Borrower photo</option>
                        <option value="pan_card">PAN card</option>
                        <option value="pledge_agreement">Pledge agreement</option>
                        <option value="signed_terms">Signed terms</option>
                        <option value="valuation_proof">Valuation proof</option>
                        <option value="loan_document">Other loan document</option>
                    </select>
                </div>
                <div>
                    <label for="ev-file">File <small>(JPG/PNG/PDF, max 8 MB)</small></label>
                    <input id="ev-file" type="file" name="file" required accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" class="dh-file-input">
                </div>
                <button type="submit" class="btn btn-dark btn-sm" data-turbo-submits-with="Uploading…">Upload</button>
            </form>
            @endcan
        </section>

        {{-- Payment History --}}
        <section class="dd-card dd-tablecard" aria-labelledby="dd-pay-h">
            <div class="dd-head"><h2 id="dd-pay-h" class="dd-h">Payment History</h2></div>
            <table class="dd-table dd-t-pay">
                <thead>
                    <tr>
                        <th scope="col" class="dh-sno-th">#</th>
                        <th scope="col">Date</th>
                        <th scope="col">Type</th>
                        <th scope="col" class="num">Amount</th>
                        <th scope="col" class="num">Principal</th>
                        <th scope="col" class="num">Interest</th>
                        <th scope="col" class="num">Penalty</th>
                        <th scope="col">Method</th>
                        <th scope="col"><span class="dd-sr">Receipt</span></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($loan->payments ?? [] as $payment)
                        <tr>
                            <x-dhiran.sno :rows="null" :n="$loop->iteration" />
                            <td data-label="Date">{{ $payment->payment_date ? $payment->payment_date->format('d M Y') : '---' }}</td>
                            <td data-cell="title" data-label="Type" class="strong">
                                @if(str_starts_with((string) $payment->notes, 'Item release payment:'))
                                    Item Release<span class="dd-sub">{{ trim(substr((string) $payment->notes, strlen('Item release payment:'))) }}</span>
                                @else
                                    {{ $payment->type ? ucwords(str_replace('_', ' ', $payment->type)) : '---' }}
                                @endif
                            </td>
                            <td data-cell="amount" data-label="Amount" class="num strong">{{ $rupee($payment->amount) }}</td>
                            <td data-label="Principal" class="num">{{ $rupee($payment->principal_component ?? 0) }}</td>
                            <td data-label="Interest" class="num">{{ $rupee($payment->interest_component ?? 0) }}</td>
                            <td data-label="Penalty" class="num">{{ $rupee($payment->penalty_component ?? 0) }}</td>
                            <td data-label="Method"><span class="dd-method">{{ \App\Models\Dhiran\DhiranPayment::methodLabel($payment->payment_method) }}</span></td>
                            <td data-cell="act" data-label="Receipt"><a href="{{ route('dhiran.payment-receipt', [$loan, $payment]) }}" target="_blank" class="dd-tlink">Payment Receipt</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="is-empty">No payments recorded yet</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        {{-- Activity log: the loan's own private record of everything that happened to it (only for people the owner allows) --}}
        @if(($activity ?? null) !== null)
        <section class="dd-card dd-card-pad" aria-labelledby="dd-act-log-h">
            <div class="dd-head">
                <h2 id="dd-act-log-h" class="dd-h">Activity log</h2>
                <a href="{{ route('dhiran.activity', $loan) }}" class="dd-tlink">Open full log</a>
            </div>
            <x-dhiran.loan-log :events="$activity" />
        </section>
        @endif

        {{-- Notes --}}
        @if($loan->notes)
        <section class="dd-card dd-card-pad" aria-labelledby="dd-notes-h">
            <h2 id="dd-notes-h" class="dd-h">Notes</h2>
            <p class="dd-lead dd-notes">{{ $loan->notes }}</p>
        </section>
        @endif
    </div>

    {{-- Cancel a loan that is waiting for evidence, or remove an old loan entered by mistake --}}
    @if(($isPending || $removableOld) && $canCreate)
        <x-dhiran.sheet id="cancelLoanModal" :title="($removableOld ? 'Remove' : 'Cancel') . ' ' . $loan->loan_number . '?'" :sub="$loan->customer->name ?? null"
                        :action="route('dhiran.cancel', $loan)" :submit="$removableOld ? 'Remove old loan' : 'Cancel loan'" cancel="Keep loan" variant="danger" busy="Working…">
            <p>
                @if($removableOld)
                    Use this only if this old loan was entered by mistake. It is marked cancelled and its opening balance withdrawn; nothing goes into the cash book. Enter it again with the right figures. This cannot be undone.
                @else
                    Use this when the loan will not go ahead. The cash book gets the {{ $rupee($loan->outstanding_principal) }} paid out back in{{ (float) $loan->processing_fee > 0 ? ' and the ' . $rupee($loan->processing_fee) . ' fee back out' : '' }}, and the items are marked returned to the borrower. This cannot be undone.
                @endif
            </p>
            <div>
                <label for="cancel-reason">Reason</label>
                <input id="cancel-reason" type="text" name="reason" maxlength="255" autofocus class="dh-form-control dd-input" placeholder="e.g. Borrower changed mind">
            </div>
        </x-dhiran.sheet>
    @endif

    {{-- The balance as the page shows it, repeated where money is taken --}}
    @php $readback = [['Outstanding Principal', $loan->outstanding_principal ?? $loan->principal_amount], ['Outstanding Interest', $loan->outstanding_interest ?? 0], ['Outstanding Penalty', $loan->outstanding_penalty ?? 0]]; @endphp

    <x-dhiran.sheet id="payInterestModal" title="Pay Interest" :sub="$loan->loan_number . ' · ' . ($loan->customer->name ?? '')" :action="route('dhiran.pay-interest', $loan)" submit="Pay Interest" busy="Recording…">
        <dl class="dd-readback">
            @foreach($readback as [$k, $v])<div><dt>{{ $k }}</dt><dd>{{ $rupee($v) }}</dd></div>@endforeach
            <div class="is-total"><dt>Total Outstanding</dt><dd>{{ $rupee($loan->total_outstanding ?? 0) }}</dd></div>
        </dl>
        <x-dhiran.amount id="pi-amount" name="amount" label="Amount" autofocus />
        <x-dhiran.pay-method id="pi-method" />
    </x-dhiran.sheet>

    @if($installment ?? null)
    <x-dhiran.sheet id="installmentModal" title="Collect Installment" :sub="$loan->loan_number . ' · ' . ($loan->customer->name ?? '')" :action="route('dhiran.pay-installment', $loan)" submit="Collect {{ $rupee($installment['amount']) }}" busy="Recording…">
        <dl class="dd-readback">
            <div><dt>Due date</dt><dd>{{ $installment['due']->format('d M Y') }}</dd></div>
            <div class="is-total"><dt>Amount</dt><dd>{{ $rupee($installment['amount']) }}</dd></div>
        </dl>
        <p class="dd-reason">The amount is worked out from the days in the month and the principal still owed. It cannot be changed here.</p>
        <x-dhiran.pay-method id="inst-method" />
    </x-dhiran.sheet>
    @endif

    <x-dhiran.sheet id="repayModal" title="Repay Loan" :sub="$loan->loan_number . ' · ' . ($loan->customer->name ?? '')" :action="route('dhiran.repay', $loan)" submit="Submit Payment" busy="Recording…">
        <dl class="dd-readback">
            @foreach($readback as [$k, $v])<div><dt>{{ $k }}</dt><dd>{{ $rupee($v) }}</dd></div>@endforeach
            <div class="is-total"><dt>Total Outstanding</dt><dd>{{ $rupee($loan->total_outstanding ?? 0) }}</dd></div>
        </dl>
        <x-dhiran.amount id="rp-amount" name="amount" label="Amount" autofocus />
        <x-dhiran.pay-method id="rp-method" />
    </x-dhiran.sheet>

    <x-dhiran.sheet id="releaseItemModal" title="Release Pledged Item" :sub="$loan->loan_number" :action="route('dhiran.release-item', $loan)" submit="Release Item" busy="Releasing…">
        <div>
            <label for="rel-item">Select Item <x-req /></label>
            <select id="rel-item" name="item_id" required aria-required="true" autofocus class="dh-form-control dh-native-select dd-input">
                <option value="">Choose item...</option>
                @foreach($loan->items ?? [] as $item)
                    @if(($item->status ?? 'pledged') === 'pledged')
                        @php $releaseMin = rescue(fn () => $service->releaseMinimum($loan, $item), null, false); @endphp
                        <option value="{{ $item->id }}">{{ $item->description }} ({{ number_format($item->gross_weight, 3) }}g){{ $releaseMin !== null ? ' — pay at least ' . $rupee($releaseMin) : '' }}</option>
                    @endif
                @endforeach
            </select>
            <p class="dd-hint">The amount covers interest and penalty due up to {{ $through }}, plus the item's share of the principal. Interest for any days since is added when you release.</p>
        </div>
        {{-- DhiranService::releaseMinimum: interest and penalty due plus the item's share of the principal among the items still pledged. --}}
        <x-dhiran.amount id="rel-amount" name="payment_amount" label="Amount" min="0" />
        <x-dhiran.pay-method id="rel-method" />
        <div>
            <label for="rel-note">Condition Note</label>
            <input id="rel-note" type="text" name="condition_note" maxlength="500" class="dh-form-control dd-input">
        </div>
    </x-dhiran.sheet>

    {{-- Closing takes the principal plus interest and penalty due, at least the minimum interest (as preCloseLoan). --}}
    @php $closeTakes = $isActive ? (float) $loan->outstanding_principal + $service->renewalDue($loan) : 0; @endphp
    <x-dhiran.sheet id="precloseModal" title="Pre-Close Loan" :sub="$loan->loan_number . ' · ' . ($loan->customer->name ?? '')" :action="route('dhiran.pre-close', $loan)" submit="Pre-Close Loan" variant="danger" busy="Closing…">
        <p class="dd-takes is-danger">This will close the loan and release all pledged items. To close, collect <strong>{{ $rupee($closeTakes) }}</strong>: the principal, plus interest and penalty due (at least the minimum interest), up to {{ $through }}. Interest for any days since is added.</p>
        <x-dhiran.pay-method id="pc-method" autofocus />
    </x-dhiran.sheet>

    @php
        $renewDue = $isActive ? $service->renewalDue($loan) : 0;
        // Paid in advance: the renewed loan takes its first months' interest on the renewal day too, so the clerk sees how much (it follows the rate typed below).
        $renewAdv = null;
        if ($isActive && (int) $loan->advance_interest_months > 0 && \App\Models\Dhiran\DhiranSettings::getForShop($loan->shop_id)->advance_interest_enabled) {
            $n = min((int) $loan->advance_interest_months, 3);
            $renewAdv = ['months' => $n, 'days' => (int) today()->diffInDays(today()->addMonthsNoOverflow($n)), 'principal' => (float) $loan->outstanding_principal];
        }
    @endphp
    <x-dhiran.sheet id="renewModal" title="Renew Loan" :sub="$loan->loan_number . ' · ' . ($loan->customer->name ?? '')" :action="route('dhiran.renew', $loan)" submit="Renew Loan" busy="Renewing…">
        @if($renewDue > 0)
            <p class="dd-takes">To renew, collect {{ $rupee($renewDue) }} now: interest and penalty due (at least the minimum interest), up to {{ $through }}. Interest for any days since is added.</p>
            <x-dhiran.pay-method id="rn-method" label="Paid by" autofocus />
        @endif
        <div x-data="{ rate: {{ (float) $loan->interest_rate_monthly }}, adv: @js($renewAdv) }" class="dd-fields">
            @if($renewAdv)
                <p class="dd-takes">This loan is paid in advance, so the renewed loan is too: collect <strong x-text="'₹' + (Math.round(adv.principal * (Number(rate) || 0) * adv.days / 30) / 100).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></strong> more now, the first {{ $renewAdv['months'] }} month{{ $renewAdv['months'] > 1 ? 's' : '' }} of the new loan's interest at the rate below. The advance not yet used on this loan stays with the shop.</p>
            @endif
            <div>
                <label for="rn-tenure">New Tenure (Months)</label>
                <input id="rn-tenure" type="number" inputmode="numeric" name="tenure_months" min="1" @if($renewDue <= 0) autofocus @endif value="{{ $loan->tenure_months }}" class="dh-form-control dd-input">
            </div>
            <div>
                <label for="rn-rate">New Interest Rate (%/month)</label>
                <input id="rn-rate" type="number" step="0.01" inputmode="decimal" name="interest_rate_monthly" x-model.number="rate" value="{{ $loan->interest_rate_monthly }}" class="dh-form-control dd-input">
            </div>
        </div>
    </x-dhiran.sheet>
</x-dhiran-layout>
