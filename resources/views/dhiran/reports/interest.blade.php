<x-dhiran-layout title="Interest Report">
    {{-- $totalInterest / $totalPenalty / $footer come from the controller and cover the whole filtered period. --}}

    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Interest Income Report</h1>
            <p class="text-sm text-gray-500 mt-1">Interest and penalty collections breakdown</p>
        </div>
        <div class="page-actions">
            <x-dhiran.export-button />
            <x-dhiran.btn-collapse icon="printer" label="Print" onclick="window.print()" />
        </div>
    </x-dhiran.page-header>

    <div class="content-inner" data-list-workspace>
        <x-dhiran.filter-bar :filters="$filters ?? \App\Services\Dhiran\DhiranListFilters::fromRequest(request())" :action="route('dhiran.reports.interest')" default-period="this_month"
            period-label="Paid in" :fields="['search', 'period']" />
        <turbo-frame id="dh-results" target="_top">

        {{-- Summary Cards --}}
        <div class="dh-stats">
            <x-dhiran.stat money label="Interest Collected" :value="\App\Support\IndianNumber::format($totalInterest, 2)" icon="dollar-sign" tone="success" />
            <x-dhiran.stat money label="Penalty Collected" :value="\App\Support\IndianNumber::format($totalPenalty, 2)" icon="triangle-alert" />
        </div>

        {{-- Table --}}
        <div class="dh-table-card" data-list-frame>
            <div class="dh-table-wrap" data-list-scroll tabindex="0" role="region" aria-label="Interest collection records">
                <table class="dh-table jf-table dh-stack">
                    <thead>
                        <tr>
                            <th scope="col" class="dh-sno-th" data-low="1">#</th>
                            <th scope="col">Date</th>
                            <th scope="col">Loan #</th>
                            <th scope="col">Customer</th>
                            <th scope="col" data-low="2">Mobile</th>
                            <th scope="col" class="text-right">Amount</th>
                            <th scope="col" class="text-right">Interest</th>
                            <th scope="col" class="text-right">Penalty</th>
                            <th scope="col" class="text-right">Principal</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($payments as $payment)
                            <tr>
                                <x-dhiran.sno :rows="$payments" :n="$loop->iteration" />
                                <td data-label="Date" class="whitespace-nowrap">{{ $payment->payment_date?->format('d M Y') ?? '-' }}</td>
                                <td data-label="Loan #" data-cell="title" class="whitespace-nowrap">
                                    <a href="{{ route('dhiran.show', $payment->loan) }}" class="font-mono font-medium text-slate-700 hover:text-amber-700">{{ $payment->loan?->loan_number ?? '-' }}</a>
                                </td>
                                <td data-label="Customer" class="font-medium text-slate-700">{{ $payment->loan?->customer?->name ?? '-' }}</td>
                                <x-dhiran.mobile-cell :number="$payment->loan?->customer?->mobile" />
                                <td data-label="Amount" class="whitespace-nowrap text-right font-semibold">₹{{ \App\Support\IndianNumber::format((float) $payment->amount, 2) }}</td>
                                <td data-label="Interest" class="whitespace-nowrap text-right text-green-600">₹{{ \App\Support\IndianNumber::format((float) $payment->interest_component, 2) }}</td>
                                <td data-label="Penalty" class="whitespace-nowrap text-right">₹{{ \App\Support\IndianNumber::format((float) $payment->penalty_component, 2) }}</td>
                                <td data-label="Principal" class="whitespace-nowrap text-right">₹{{ \App\Support\IndianNumber::format((float) $payment->principal_component, 2) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="jf-table-empty">
                                    <x-dhiran.empty icon="inbox" title="No payments found" text="Nothing was paid in the selected period. Widen the date range or clear the filters.">
                                        <a class="btn btn-secondary btn-sm" href="{{ url()->current() }}">Clear filters</a>
                                    </x-dhiran.empty>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if($payments->count())
                    <tfoot>
                        <tr>
                            <td data-low="1"></td>
                            <td colspan="3">Totals ({{ $payments->total() }} payments)</td>
                            <td data-low="2"></td>
                            <td class="text-right">₹{{ \App\Support\IndianNumber::format($footer['amount'], 2) }}</td>
                            <td class="text-right">₹{{ \App\Support\IndianNumber::format($footer['interest'], 2) }}</td>
                            <td class="text-right">₹{{ \App\Support\IndianNumber::format($footer['penalty'], 2) }}</td>
                            <td class="text-right">₹{{ \App\Support\IndianNumber::format($footer['principal'], 2) }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
            <x-dhiran.pager :paginator="$payments" noun="payments" />
        </div>
        </turbo-frame>
    </div>
</x-dhiran-layout>
