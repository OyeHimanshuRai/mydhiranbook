<x-dhiran-layout title="Overdue Report">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Overdue Loans Report</h1>
            <p class="text-sm text-gray-500 mt-1">Loans past maturity date requiring attention</p>
        </div>
        <div class="page-actions">
            <x-dhiran.export-button />
            <span class="header-badge">{{ $totals['count'] }} Overdue</span>
            <x-dhiran.btn-collapse icon="printer" label="Print" onclick="window.print()" />
        </div>
    </x-dhiran.page-header>

    <div class="content-inner" data-list-workspace>
        <x-dhiran.filter-bar :filters="$filters ?? \App\Services\Dhiran\DhiranListFilters::fromRequest(request())" :action="route('dhiran.reports.overdue')"
            :fields="['search', 'metal', 'amount', 'overdue']" />
        <turbo-frame id="dh-results" target="_top">
        {{-- Summary Cards --}}
        <div class="dh-stats">
            <x-dhiran.stat label="Total Overdue" :value="$totals['count']" icon="triangle-alert" total="count" />
            <x-dhiran.stat money label="Total Overdue Amount" :value="\App\Support\IndianNumber::format($totals['outstanding'], 2)" icon="dollar-sign" total="outstanding" tone="danger" />
        </div>

        {{-- Table --}}
        <div class="dh-table-card" data-list-frame>
            <div class="dh-table-wrap" data-list-scroll tabindex="0" role="region" aria-label="Overdue loan records">
                <table class="dh-table jf-table dh-stack">
                    <thead>
                        <tr>
                            <th scope="col" class="dh-sno-th" data-low="1">#</th>
                            <th scope="col">Loan #</th>
                            <th scope="col">Customer</th>
                            <th scope="col" data-low="2">Mobile</th>
                            <th scope="col" class="text-right">Principal</th>
                            <th scope="col" class="text-right">Outstanding</th>
                            <th scope="col" class="text-center">Days Overdue</th>
                            <th scope="col">Maturity Date</th>
                            <th scope="col" data-low="1">Grace End</th>
                            <th scope="col" class="text-center">Notice Sent?</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($loans as $loan)
                            @php
                                $daysOverdue = $loan->daysOverdue();
                                $graceEnd = ($loan->maturity_date && $loan->grace_period_days)
                                    ? $loan->maturity_date->copy()->addDays($loan->grace_period_days)
                                    : $loan->maturity_date;
                            @endphp
                            <tr>
                                <x-dhiran.sno :rows="$loans" :n="$loop->iteration" />
                                <td data-label="Loan #" data-cell="title" class="whitespace-nowrap">
                                    <a href="{{ route('dhiran.show', $loan) }}" class="font-mono font-medium text-slate-700 hover:text-amber-700">{{ $loan->loan_number }}</a>
                                </td>
                                <td data-label="Customer" class="font-medium text-slate-700">{{ $loan->customer?->name ?? '-' }}</td>
                                <x-dhiran.mobile-cell :number="$loan->customer?->mobile" />
                                <td data-label="Principal" class="whitespace-nowrap text-right">₹{{ \App\Support\IndianNumber::format((float) $loan->principal_amount, 2) }}</td>
                                <td data-label="Outstanding" class="whitespace-nowrap text-right font-semibold text-red-600">₹{{ \App\Support\IndianNumber::format($loan->totalOutstanding(), 2) }}</td>
                                <td data-label="Days Overdue" data-cell="status" class="whitespace-nowrap text-center">
                                    @if($daysOverdue > 90)
                                        <span class="dh-status dh-status--danger">{{ $daysOverdue }}d</span>
                                    @else
                                        <span class="dh-status dh-status--warning">{{ $daysOverdue }}d</span>
                                    @endif
                                </td>
                                <td data-label="Maturity Date" class="whitespace-nowrap">{{ $loan->maturity_date?->format('d M Y') ?? '-' }}</td>
                                <td data-label="Grace End" data-low="1" class="whitespace-nowrap">{{ $graceEnd?->format('d M Y') ?? '-' }}</td>
                                <td data-label="Notice Sent?" class="whitespace-nowrap text-center">
                                    @if($loan->forfeiture_notice_sent_at)
                                        <span class="dh-status dh-status--success">{{ $loan->forfeiture_notice_sent_at->format('d M Y') }}</span>
                                    @else
                                        <span class="text-slate-400">No</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="jf-table-empty">
                                    <x-dhiran.empty icon="search" title="No overdue loans found" text="Nothing fits the search or filters you chose. Widen the filters to see more loans.">
                                        <a class="btn btn-secondary btn-sm" href="{{ url()->current() }}">Clear filters</a>
                                    </x-dhiran.empty>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-dhiran.pager :paginator="$loans" noun="loans" />
        </div>
        </turbo-frame>
    </div>
</x-dhiran-layout>
