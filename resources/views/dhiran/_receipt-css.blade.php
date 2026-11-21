<style>
    @page { size: A4; margin: 14mm; }
    * { box-sizing: border-box; }
    body.rc-body { margin: 0; background: #f3f4f6; color: #111; font: 13px/1.45 "Helvetica Neue", Arial, sans-serif; }
    .rc-bar { max-width: 190mm; margin: 12px auto 0; padding: 0 8px; display: flex; gap: 8px; justify-content: flex-end; }
    .rc-btn { border: 1px solid #111; background: #111; color: #fff; padding: 7px 14px; border-radius: 4px; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .rc-btn.rc-btn-light { background: #fff; color: #111; }
    .receipt-container { max-width: 190mm; margin: 12px auto 24px; padding: 14mm 12mm; background: #fff; border: 1px solid #d1d5db; }
    .rc-head { text-align: center; padding-bottom: 10px; border-bottom: 2px solid #111; margin-bottom: 14px; }
    .receipt-shop-name { font-size: 20px; font-weight: 700; letter-spacing: .02em; }
    .rc-muted { color: #555; font-size: 12px; }
    .rc-title { margin: 8px 0 0; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .12em; }
    .rc-sec { margin-bottom: 14px; break-inside: avoid; }
    .rc-sec-title { margin: 0 0 4px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #555; border-bottom: 1px solid #bbb; padding-bottom: 3px; }
    .rc-rows { margin: 0; }
    .rc-row { display: flex; justify-content: space-between; gap: 16px; padding: 4px 0; border-bottom: 1px dotted #ccc; break-inside: avoid; }
    .rc-row dt { color: #444; margin: 0; }
    .rc-row dd { margin: 0; font-weight: 600; text-align: right; }
    .rc-cols { column-count: 2; column-gap: 24px; }
    .rc-row.rc-strong { border-bottom: 1px solid #111; border-top: 1px solid #111; font-size: 14px; }
    .rc-row.rc-strong dt, .rc-row.rc-strong dd { color: #111; font-weight: 700; }
    .rc-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    .rc-table th, .rc-table td { border: 1px solid #888; padding: 5px 7px; text-align: left; vertical-align: top; }
    .rc-table th { background: #f0f0f0; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; }
    .rc-table tfoot td { font-weight: 700; background: #fafafa; }
    .rc-table .r { text-align: right; } .rc-table .c { text-align: center; }
    .rc-item-meta { color: #555; font-size: 11px; display: block; }
    .rc-summary { margin: 0 0 14px auto; width: 60%; min-width: 240px; }
    .rc-terms { white-space: pre-line; font-size: 11px; color: #333; }
    .rc-sigs { display: flex; gap: 40px; margin-top: 44px; }
    .rc-sig { flex: 1; border-top: 1px solid #111; padding-top: 5px; text-align: center; font-size: 12px; }
    .rc-foot { margin-top: 16px; text-align: center; font-size: 10px; color: #666; }
    @media print {
        body.rc-body { background: #fff; }
        .rc-bar { display: none; }
        .receipt-container { margin: 0; padding: 0; border: 0; max-width: none; }
    }
    @media (max-width: 560px) { .rc-cols { column-count: 1; } .receipt-container { padding: 14px; } .rc-summary { width: 100%; } }
</style>
