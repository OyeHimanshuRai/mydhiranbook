{{-- Shared by the single Item Tag page and the bulk one: the slip's look, at $w mm wide (58 or 80). --}}
        @page { size: {{ $w }}mm auto; margin: 2mm; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f3f4f6; color: #000; font: 10px/1.3 "Helvetica Neue", Arial, sans-serif; }
        .tag-bar { width: {{ $w }}mm; margin: 12px auto 0; display: flex; gap: 6px; justify-content: flex-end; align-items: center; font-size: 12px; }
        .tag-bar a { color: #111; }
        .tag-btn { border: 1px solid #111; background: #111; color: #fff; padding: 8px 14px; border-radius: 4px; font: 600 13px/1 inherit; cursor: pointer; min-height: 40px; }
        .tag { width: {{ $w }}mm; margin: 10px auto 24px; padding: 2mm; background: #fff; border: 1px dashed #666; } /* the dashed edge is the cutting guide; it prints too */
        .tag-shop { text-align: center; font-size: 8px; text-transform: uppercase; letter-spacing: .06em; }
        .tag-no { text-align: center; font: 700 {{ $w >= 80 ? 24 : 19 }}px/1.15 "Courier New", monospace; margin: 1px 0 3px; }
        .tag-line { display: flex; justify-content: space-between; gap: 6px; border-top: 1px dotted #777; padding: 1px 0; }
        .tag-line b { text-align: right; }
        .tag-item { border-top: 1px dotted #777; padding: 2px 0; }
        .tag-item small { display: block; font-size: 9px; }
        .tag-total { border-top: 1px solid #000; padding-top: 2px; font-weight: 700; }
        .tag-write { display: flex; gap: 6px; margin-top: 5px; font-size: 8px; }
        .tag-write div { flex: 1; } .tag-write span { display: block; height: 14px; border-bottom: 1px solid #000; }
        @media print { body { background: #fff; } .tag-bar { display: none; } .tag { margin: 0; } }
