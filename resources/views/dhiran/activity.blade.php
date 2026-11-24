<x-dhiran-layout title="Activity log">
    @vite('resources/css/dhiran-detail.css')
    <x-dhiran.page-header>
        <div class="min-w-0">
            <h1 class="page-title">Activity log · {{ $loan->loan_number }}</h1>
        </div>
        <div class="page-actions">
            <x-dhiran.btn-collapse icon="arrow-left" label="Back to loan" :href="route('dhiran.show', $loan)" />
            <x-dhiran.btn-collapse icon="download" label="Export CSV" :href="route('dhiran.activity.export', array_merge(['loan' => $loan], $filters))" data-turbo="false" />
        </div>
    </x-dhiran.page-header>

    <div class="content-inner dd">
        <section class="dd-card dd-card-pad">
            <p class="dd-lead">Everything that happens to this loan is written here as it happens: money taken or paid, items released, documents uploaded, changes, prints. Lines cannot be edited or removed. Open a line for its full details.</p>
            <form method="GET" action="{{ route('dhiran.activity', $loan) }}" class="al-filters">
                <label>What
                    <select name="cat" class="dh-form-control dh-native-select">
                        <option value="">Everything</option>
                        @foreach(\App\Models\Dhiran\LoanEvent::CATEGORIES as $k => $label)
                            <option value="{{ $k }}" @selected(($filters['cat'] ?? '') === $k)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Who
                    <select name="who" class="dh-form-control dh-native-select">
                        <option value="">Everyone</option>
                        @foreach($actors as $name)<option value="{{ $name }}" @selected(($filters['who'] ?? '') === $name)>{{ $name }}</option>@endforeach
                    </select>
                </label>
                <label>From <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="dh-form-control"></label>
                <label>To <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="dh-form-control"></label>
                <button type="submit" class="btn btn-primary btn-sm">Show</button>
                @if(array_filter($filters))<a href="{{ route('dhiran.activity', $loan) }}" class="btn btn-secondary btn-sm">Clear</a>@endif
            </form>
        </section>

        <section class="dd-card dd-card-pad" aria-label="Activity">
            <x-dhiran.loan-log :events="$events" />
            <div class="al-pager">{{ $events->links() }}</div>
        </section>
    </div>
</x-dhiran-layout>
