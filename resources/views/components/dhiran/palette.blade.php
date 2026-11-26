{{-- Command palette (Ctrl/Cmd K) and keyboard shortcuts (resources/js/ux/palette.js, shortcuts.js).
     The "Go to" list is built here, only for pages this user may open (same rules as the sidebar and the settings pills),
     and handed to the script as data; the script filters it as the user types. Loans and customers come from
     route('dhiran.palette') once two characters are typed. The shortcuts read their targets from the same list. --}}
@can('dhiran.view')
@php
    $u = auth()->user();
    $go = [
        ['key' => 'loans', 'label' => 'Loans', 'kw' => 'loan list pledges', 'url' => route('dhiran.loans', [], false)],
        $u->can('dhiran.create') ? ['key' => 'new', 'label' => 'New loan', 'kw' => 'create add pledge', 'url' => route('dhiran.create', [], false)] : null,
        ['key' => 'customers', 'label' => 'Customers', 'kw' => 'borrowers people', 'url' => route('dhiran.borrowers.index', [], false)],
        $u->can('dhiran.create') ? ['key' => 'old', 'label' => 'Old loans', 'kw' => 'import upload paper running', 'url' => route('dhiran.old-loans.index', [], false)] : null,
    ];
    if ($u->can('dhiran.reports')) {
        $go[] = ['key' => 'reports', 'label' => 'Reports hub', 'kw' => 'reports all', 'url' => route('dhiran.reports.index', [], false)];
        foreach ([['active', 'Active', 'dhiran.reports.active'], ['overdue', 'Overdue', 'dhiran.reports.overdue'], ['interest', 'Interest', 'dhiran.reports.interest'], ['forfeiture', 'Forfeiture', 'dhiran.reports.forfeiture'], ['cashbook', 'Cash book', 'dhiran.reports.cashbook'], ['profitability', 'Profitability', 'dhiran.reports.profitability']] as [$r, $name, $routeName]) {
            $go[] = ['key' => 'report-'.$r, 'label' => 'Report: '.$name, 'kw' => 'reports', 'url' => route($routeName, [], false)];
        }
    }
    if ($u->can('dhiran.settings')) {
        $go[] = ['key' => 'settings', 'label' => 'Settings: Loan', 'kw' => 'settings rate interest', 'url' => route('dhiran.settings', [], false)];
        $go[] = ['key' => 'receipt', 'label' => 'Settings: Receipt', 'kw' => 'settings print documents', 'url' => route('dhiran.settings.receipt.edit', [], false)];
        $go[] = ['key' => 'notices', 'label' => 'Settings: Notifications', 'kw' => 'settings alerts reminders', 'url' => route('dhiran.settings.notices', [], false)];
    }
    if ($u->can('staff.view')) {
        $go[] = ['key' => 'users', 'label' => 'Settings: Users & roles', 'kw' => 'settings staff team permissions', 'url' => route('settings.edit', ['tab' => 'staff'], false)];
    }
    $go = array_values(array_filter($go));
    $has = fn (string $k) => collect($go)->contains('key', $k);
    $keys = array_filter([
        [['Ctrl', 'K'], ' + ', 'Search loans, customers and pages', true],
        [['/'], '', 'Go to the search box on this page', true],
        [['n'], '', 'New loan', $has('new')],
        [['g', 'l'], ' then ', 'Loans', true],
        [['g', 'c'], ' then ', 'Customers', true],
        [['g', 'r'], ' then ', 'Reports', $has('reports')],
        [['g', 's'], ' then ', 'Settings', $has('settings') || $has('users')],
        [['?'], '', 'Show this list', true],
        [['Esc'], '', 'Close a window', true],
    ], fn ($k) => $k[3]);
@endphp
<button type="button" class="pal-fab" data-palette-open aria-haspopup="dialog" aria-keyshortcuts="Control+K Meta+K" aria-label="Search">
    <x-icon name="search" size="16" />
    <span class="pal-fab-text" aria-hidden="true">Search</span>
    <kbd class="pal-kbd" data-pal-mod aria-hidden="true">Ctrl K</kbd>
</button>

<dialog id="dhPalette" class="dh-dialog pal" aria-label="Search and go to" data-items="{{ json_encode($go, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}" data-search-url="{{ route('dhiran.palette', [], false) }}">
    <div class="pal-field">
        <x-icon name="search" size="18" />
        <input type="text" id="dhPaletteInput" class="pal-input" role="combobox" aria-label="Search loans, customers and pages" aria-expanded="true"
               aria-controls="dhPaletteList" aria-autocomplete="list" aria-activedescendant="" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="go"
               maxlength="60" placeholder="Search loans, customers or pages">
        <button type="button" class="pal-close" data-palette-close aria-label="Close search"><x-icon name="x" size="16" /></button>
    </div>
    <p class="pal-status" id="dhPaletteStatus" role="status" aria-live="polite"></p>
    <div class="pal-list" id="dhPaletteList" role="listbox" aria-label="Results"></div>
    <p class="pal-hint" aria-hidden="true"><span><kbd class="pal-kbd">↑</kbd> <kbd class="pal-kbd">↓</kbd> move</span> <span><kbd class="pal-kbd">Enter</kbd> open</span> <span><kbd class="pal-kbd">Esc</kbd> close</span></p>
</dialog>

<dialog id="dhShortcuts" class="dh-dialog pal pal-help" aria-labelledby="dhShortcuts-t">
    <div class="pal-help-head">
        <h3 id="dhShortcuts-t">Keyboard shortcuts</h3>
        <button type="button" class="pal-close" data-shortcuts-close aria-label="Close"><x-icon name="x" size="16" /></button>
    </div>
    <dl class="pal-help-list">
        @foreach($keys as [$combo, $join, $what])
            <div>
                <dt>@foreach($combo as $i => $part){{ $i ? $join : '' }}<kbd class="pal-kbd" @if($part === 'Ctrl') data-pal-mod @endif>{{ $part }}</kbd>@endforeach</dt>
                <dd>{{ $what }}</dd>
            </div>
        @endforeach
    </dl>
    <p class="pal-help-note">Shortcuts pause while you type in a box.</p>
</dialog>
@endcan
