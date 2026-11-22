<x-dhiran-layout title="Profitability Report">
    @php
        // $totals covers every loan given in the period, on every page.
        $totalDisbursed = $totals['principal'];
        $totalInterestEarned = $totals['interest'];
        $totalPenalties = $totals['penalty'];
        $totalProcessingFees = $totals['fees'];
        $netProfit = $totalInterestEarned + $totalPenalties + $totalProcessingFees;
    @endphp

    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Profitability Report</h1>
            <p class="text-sm text-gray-500 mt-1">Revenue and profitability analysis across all loans</p>
        </div>
        <div class="page-actions">
            <x-dhiran.export-button />
            <x-dhiran.btn-collapse icon="printer" label="Print" onclick="window.print()" />
        </div>
    </x-dhiran.page-header>

    <div class="content-inner" data-list-workspace>
        {{-- Loans given in this period (the controller always took a range; the page had no way to set it). --}}
        <x-dhiran.filter-bar :filters="$filters ?? \App\Services\Dhiran\DhiranListFilters::fromRequest(request())" :action="route('dhiran.reports.profitability')" default-period="this_month"
            period-label="Loans given in" :fields="['search', 'period', 'status', 'metal', 'amount']" />
        <turbo-frame id="dh-results" target="_top">

        {{-- Summary Cards --}}
        <div class="dh-stats">
            <x-dhiran.stat money label="Total Disbursed" :value="\App\Support\IndianNumber::format($totalDisbursed, 2)" total="principal" />
            <x-dhiran.stat money label="Interest Earned" :value="\App\Support\IndianNumber::format($totalInterestEarned, 2)" tone="success" />
            <x-dhiran.stat money label="Penalties" :value="\App\Support\IndianNumber::format($totalPenalties, 2)" />
            <x-dhiran.stat money label="Processing Fees" :value="\App\Support\IndianNumber::format($totalProcessingFees, 2)" />
            <x-dhiran.stat money label="Net Profit" :value="\App\Support\IndianNumber::format($netProfit, 2)" :tone="$netProfit >= 0 ? 'success' : 'danger'" />
        </div>

        {{-- Table --}}
        <div class="dh-table-card" data-list-frame>
            <div class="dh-table-wrap" data-list-scroll tabindex="0" role="region" aria-label="Loan profitability records">
                <table class="dh-table jf-table dh-stack">
                    <thead>
                        <tr>
                            <th scope="col" class="dh-sno-th" data-low="1">#</th>
                            <th scope="col">Loan #</th>
                            <th scope="col" data-low="2">Customer</th>
                            <th scope="col" data-low="1">Mobile</th>
                            <th scope="col" class="text-right">Principal</th>
                            <th scope="col" class="text-right">Interest Collected</th>
                            <th scope="col" class="text-right">Penalty Collected</th>
                            <th scope="col" class="text-right">Processing Fee</th>
                            <th scope="col" class="text-center" data-low="2">Status</th>
                            <th scope="col" class="text-right">Profit/Loss</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($loans as $loan)
                            @php
                                $loanProfit = (float) $loan->total_interest_collected
                                    + (float) $loan->total_penalty_collected
                                    + (float) $loan->processing_fee;
                            @endphp
                            <tr>
                                <x-dhiran.sno :rows="$loans" :n="$loop->iteration" />
                                <td data-label="Loan #" data-cell="title" class="whitespace-nowrap">
                                    <a href="{{ route('dhiran.show', $loan) }}" class="font-mono font-medium text-slate-700 hover:text-amber-700">{{ $loan->loan_number }}</a>
                                </td>
                                <td data-label="Customer" data-low="2" class="font-medium text-slate-700">{{ $loan->customer?->name ?? '-' }}</td>
                                <x-dhiran.mobile-cell :number="$loan->customer?->mobile" low="1" />
                                <td data-label="Principal" class="whitespace-nowrap text-right">₹{{ \App\Support\IndianNumber::format((float) $loan->principal_amount, 2) }}</td>
                                <td data-label="Interest Collected" class="whitespace-nowrap text-right text-green-600">₹{{ \App\Support\IndianNumber::format((float) $loan->total_interest_collected, 2) }}</td>
                                <td data-label="Penalty Collected" class="whitespace-nowrap text-right">₹{{ \App\Support\IndianNumber::format((float) $loan->total_penalty_collected, 2) }}</td>
                                <td data-label="Processing Fee" class="whitespace-nowrap text-right">₹{{ \App\Support\IndianNumber::format((float) $loan->processing_fee, 2) }}</td>
                                <td data-label="Status" data-cell="status" data-low="2" class="text-center">
                                    <x-dhiran.status :value="$loan->status" />
                                </td>
                                <td data-label="Profit/Loss" class="whitespace-nowrap text-right font-semibold {{ $loanProfit >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                    ₹{{ \App\Support\IndianNumber::format($loanProfit, 2) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="jf-table-empty">
                                    <x-dhiran.empty icon="inbox" title="No loans found" text="No loans were given in this period. Widen the date range or clear the filters.">
                                        <a class="btn btn-secondary btn-sm" href="{{ url()->current() }}">Clear filters</a>
                                    </x-dhiran.empty>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if($loans->count())
                    <tfoot>
                        <tr>
                            <td data-low="1"></td>
                            <td>Totals ({{ $totals['count'] }} loans)</td>
                            <td data-low="2"></td>
                            <td data-low="1"></td>
                            <td class="text-right">₹{{ \App\Support\IndianNumber::format($totalDisbursed, 2) }}</td>
                            <td class="text-right">₹{{ \App\Support\IndianNumber::format($totalInterestEarned, 2) }}</td>
                            <td class="text-right">₹{{ \App\Support\IndianNumber::format($totalPenalties, 2) }}</td>
                            <td class="text-right">₹{{ \App\Support\IndianNumber::format($totalProcessingFees, 2) }}</td>
                            <td data-low="2"></td>
                            <td class="text-right">₹{{ \App\Support\IndianNumber::format($netProfit, 2) }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
            <x-dhiran.pager :paginator="$loans" noun="loans" />
        </div>
        </turbo-frame>
    </div>
</x-dhiran-layout>
