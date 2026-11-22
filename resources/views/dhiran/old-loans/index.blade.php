<x-dhiran-layout title="Old Loans">
    <x-dhiran.page-header>
        <div>
            <h1 class="page-title">Old Loans</h1>
            <p class="text-sm text-gray-500 mt-1">Running loans brought in from your paper book</p>
        </div>
        <div class="page-actions">
            <x-dhiran.filter-panel :groups="$filters" title="Filter old loans" />
            <x-dhiran.btn-collapse icon="upload" label="Upload spreadsheet" :href="route('dhiran.old-loans.upload')" />
            <a href="{{ route('dhiran.old-loans.create') }}" class="btn btn-primary btn-sm">
                <x-icon name="plus" size="14" class="mr-1" />
                Add old loan
            </a>
        </div>
    </x-dhiran.page-header>

    <div class="content-inner" data-list-workspace>
        <x-dhiran.filter-panel :groups="$filters" mode="chips" />

        <div class="dh-table-card" data-list-frame>
            <div class="dh-table-wrap" data-list-scroll tabindex="0" role="region" aria-label="Old loans">
                <table class="dh-table jf-table dh-stack">
                    <thead>
                        <tr>
                            <th scope="col" class="dh-sno-th" data-low="1">#</th>
                            <th scope="col">Ticket #</th>
                            <th scope="col">Customer</th>
                            <th scope="col" data-low="2">Mobile</th>
                            <th scope="col">Loan date</th>
                            <th scope="col" class="text-right">Lent</th>
                            <th scope="col" class="text-right">Outstanding</th>
                            <th scope="col" class="text-center">Status</th>
                            <th scope="col" class="text-center">Evidence</th>
                            <th scope="col" data-low="1">Brought in</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($loans as $loan)
                            <tr>
                                <x-dhiran.sno :rows="$loans" :n="$loop->iteration" />
                                <td data-label="Ticket #" data-cell="title" class="whitespace-nowrap">
                                    <a href="{{ route('dhiran.show', $loan) }}" class="font-mono font-medium text-slate-700 hover:text-[var(--kh-ink-2)]">{{ $loan->loan_number }}</a>
                                </td>
                                <td data-label="Customer" class="font-medium text-slate-700">{{ $loan->customer->name ?? '---' }}</td>
                                <x-dhiran.mobile-cell :number="$loan->customer?->mobile" />
                                <td data-label="Loan date" class="whitespace-nowrap">{{ $loan->loan_date?->format('d M Y') }}</td>
                                <td data-label="Lent" class="whitespace-nowrap text-right">₹{{ \App\Support\IndianNumber::format($loan->principal_amount, 2) }}</td>
                                <td data-label="Outstanding" class="whitespace-nowrap text-right font-semibold text-slate-800">₹{{ \App\Support\IndianNumber::format($loan->total_outstanding, 2) }}</td>
                                <td data-label="Status" data-cell="status" class="text-center"><x-dhiran.status :value="$loan->status" /></td>
                                <td data-label="Evidence" class="text-center" data-evidence="{{ $loan->evidence_ok ? 'done' : 'missing' }}">
                                    @if($loan->evidence_ok)
                                        <span class="dh-status dh-status--success">Complete</span>
                                    @else
                                        <a href="{{ route('dhiran.show', $loan) }}#evidence" class="dh-status dh-status--warning">Photo / ID missing</a>
                                    @endif
                                </td>
                                <td data-label="Brought in" data-low="1" class="whitespace-nowrap">{{ $loan->created_at?->format('d M Y') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="jf-table-empty">
                                    @if(collect($filters)->contains(fn ($g, $n) => request()->filled($n)))
                                        <x-dhiran.empty icon="search" title="No old loans match" text="Nothing fits the filters you chose.">
                                            <a href="{{ route('dhiran.old-loans.index') }}" class="btn btn-secondary btn-sm">Clear filters</a>
                                        </x-dhiran.empty>
                                    @else
                                        <x-dhiran.empty icon="book-open" title="No old loans yet" text="Bring in running loans from your paper book, one at a time or from a spreadsheet.">
                                            <a href="{{ route('dhiran.old-loans.create') }}" class="btn btn-primary btn-sm">Add old loan</a>
                                        </x-dhiran.empty>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <x-dhiran.pager :paginator="$loans" noun="old loans" />
        </div>
    </div>
</x-dhiran-layout>
