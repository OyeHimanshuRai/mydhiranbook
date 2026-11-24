@props(['events', 'showLoan' => false])
@php
    // A loan's activity log, one day at a time (newest first), the time on every line, and each line opens to its full details.
    $tz = config('app.timezone');
    $labels = \App\Models\Dhiran\LoanEvent::CATEGORIES;
    $moneyKeys = ['amount', 'principal', 'interest', 'penalty', 'fee', 'principal_left', 'interest_left', 'penalty_left', 'interest_owed', 'penalty_owed', 'covered_by_advance', 'market_value'];
    $fmt = function ($key, $v) use ($moneyKeys) {
        if (is_bool($v)) { return $v ? 'Yes' : 'No'; }
        if (in_array($key, $moneyKeys, true) && is_numeric($v)) { return '₹' . \App\Support\IndianNumber::format($v, 2); }
        if (in_array($key, ['gross_weight', 'net_weight', 'fine_weight', 'stone_weight'], true) && is_numeric($v)) { return number_format((float) $v, 3) . ' g'; }
        return is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE);
    };
    $rows = function (array $details) use ($fmt) {
        $out = [];
        foreach ($details as $key => $v) {
            $label = ucfirst(str_replace('_', ' ', (string) $key));
            if (is_array($v) && array_key_exists('from', $v)) { $out[$label] = $v['from'] . ' → ' . $v['to']; }
            elseif (is_array($v) && array_key_exists('changed', $v)) { $out[$label] = 'changed (value not kept in the log)'; }
            elseif ($key === 'changes' && is_array($v)) { foreach ($v as $name => $c) { $out[$name] = is_array($c) && isset($c['from']) ? $c['from'] . ' → ' . $c['to'] : 'changed'; } }
            elseif ($key === 'items' && is_array($v)) { $out['Items'] = collect($v)->map(fn ($i) => ($i['description'] ?? 'Item') . (isset($i['gross_weight']) ? ' (gross ' . number_format((float) $i['gross_weight'], 3) . ' g)' : ''))->implode(', '); }
            elseif (is_array($v)) { $out[$label] = $fmt($key, $v); }
            else { $out[$label] = $fmt($key, $v); }
        }
        return $out;
    };
    $days = $events->groupBy(fn ($e) => $e->occurred_at->timezone($tz)->toDateString());
@endphp
@forelse($days as $day => $list)
    <section class="al-day" aria-label="{{ \Illuminate\Support\Carbon::parse($day)->format('l, d M Y') }}">
        <div class="al-day-head">
            <h3 class="al-day-title">{{ \Illuminate\Support\Carbon::parse($day)->format('l, d M Y') }}</h3>
            <span class="al-day-count">{{ $list->count() }} {{ $list->count() === 1 ? 'event' : 'events' }}</span>
        </div>
        <ol class="al-list">
            @foreach($list as $e)
                @php $at = $e->occurred_at->timezone($tz); $detail = $rows((array) $e->details); @endphp
                <li class="al-item al-item--{{ $e->category }}">
                    <time class="al-time" datetime="{{ $at->toIso8601String() }}" title="{{ $at->format('d M Y, h:i:s A') }}">{{ $at->format('h:i A') }}</time>
                    <details class="al-body">
                        <summary>
                            <span class="al-chip al-chip--{{ $e->category }}">{{ $labels[$e->category] ?? ucfirst($e->category) }}</span>
                            @if($showLoan)<span class="al-loan">{{ $e->loan?->loan_number }}</span>@endif
                            <span class="al-text">{{ $e->summary }}</span>
                            <span class="al-who">by {{ $e->actor_name }}</span>
                        </summary>
                        <dl class="al-details">
                            <div><dt>When</dt><dd>{{ $at->format('l, d M Y, h:i:s A') }}</dd></div>
                            <div><dt>Who</dt><dd>{{ $e->actor_name }}@if($e->ip) <span class="al-muted">({{ $e->ip }})</span>@endif</dd></div>
                            <div><dt>Event type</dt><dd>{{ $e->event }}</dd></div>
                            @foreach($detail as $label => $value)
                                <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                            @endforeach
                        </dl>
                    </details>
                </li>
            @endforeach
        </ol>
    </section>
@empty
    <p class="al-empty">Nothing has been recorded for this loan yet.</p>
@endforelse
