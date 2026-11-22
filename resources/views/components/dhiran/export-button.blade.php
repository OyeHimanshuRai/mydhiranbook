{{-- CSV export of the page's filtered records, every page of them (owner 2026-11-11). A plain download: no Turbo. --}}
{{-- Live search changes the page address without reloading, so the link is built from the address when clicked. --}}
<x-dhiran.btn-collapse icon="download" label="Export CSV" :href="request()->fullUrlWithQuery(['export' => 'csv', 'page' => null, 'per_page' => null])"
    data-turbo="false" download data-dhiran-export title="Download every matching record as a spreadsheet (CSV)"
    onclick="const u = new window.URL(window.location.href); u.searchParams.delete('page'); u.searchParams.delete('per_page'); u.searchParams.set('export', 'csv'); this.href = u.toString();" />
