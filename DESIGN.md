---
name: Dhiran
description: The bahi-khata made software. An oxblood cloth shell around calm white pages, with ledger rhythm on every money table.
colors:
  cloth: "#7a1a1d"
  cloth-deep: "#5a1215"
  cloth-lift: "#8c2a2d"
  on-cloth: "#ffffff"
  on-cloth-soft: "#f0d9d9"
  paper: "#f3f4f7"
  panel: "#ffffff"
  wash: "#eaedf2"
  graphite: "#1d2230"
  graphite-2: "#444c5e"
  graphite-3: "#5b6477"
  ink: "#1a2a4a"
  ink-pressed: "#101c36"
  ink-deep: "#0a1226"
  ink-soft: "#eef1f7"
  ink-line: "#c6cee0"
  margin-red: "#c9484d"
  rule: "#d9dce3"
  rule-soft: "#e8eaef"
  field-edge: "#868fa2"
  ok: "#1f6f4a"
  ok-soft: "#e9f4ee"
  error: "#b3261e"
  error-soft: "#fcefee"
  warning-text: "#8a4700"
  warning-solid: "#7d4200"
  info: "#1d4f91"
  info-soft: "#eaf1fb"
typography:
  display:
    fontFamily: "Georgia, 'Times New Roman', serif"
    fontSize: "1.625rem"
    fontWeight: 600
    lineHeight: 1
    letterSpacing: "-0.01em"
  headline:
    fontFamily: "system-ui, 'Noto Sans', 'Segoe UI', Roboto, 'Noto Sans Devanagari', sans-serif"
    fontSize: "clamp(1.25rem, 1.107rem + 0.714vw, 1.75rem)"
    fontWeight: 700
    lineHeight: 1.2
    letterSpacing: "-0.005em"
  title:
    fontFamily: "system-ui, 'Noto Sans', 'Segoe UI', Roboto, 'Noto Sans Devanagari', sans-serif"
    fontSize: "clamp(1rem, 0.929rem + 0.357vw, 1.25rem)"
    fontWeight: 600
    lineHeight: 1.2
  figure:
    fontFamily: "system-ui, 'Noto Sans', 'Segoe UI', Roboto, 'Noto Sans Devanagari', sans-serif"
    fontSize: "clamp(1.25rem, 1.036rem + 1.071vw, 2rem)"
    fontWeight: 700
    lineHeight: 1.2
  body:
    fontFamily: "system-ui, 'Noto Sans', 'Segoe UI', Roboto, 'Noto Sans Devanagari', sans-serif"
    fontSize: "clamp(0.875rem, 0.82rem + 0.28vw, 1rem)"
    fontWeight: 400
    lineHeight: 1.45
  label:
    fontFamily: "system-ui, 'Noto Sans', 'Segoe UI', Roboto, 'Noto Sans Devanagari', sans-serif"
    fontSize: "0.75rem"
    fontWeight: 600
    lineHeight: 1.3
    letterSpacing: "0.06em"
rounded:
  sm: "4px"
  md: "6px"
  button: "8px"
  card: "12px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "12px"
  lg: "16px"
  xl: "24px"
  xxl: "32px"
  margin-gutter: "14px"
components:
  button-primary:
    backgroundColor: "{colors.ink}"
    textColor: "{colors.panel}"
    rounded: "{rounded.button}"
    height: "44px"
  button-primary-hover:
    backgroundColor: "{colors.ink-pressed}"
  button-secondary:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.ink}"
    rounded: "{rounded.button}"
    height: "44px"
  button-secondary-hover:
    backgroundColor: "{colors.ink-soft}"
  button-danger:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.error}"
    rounded: "{rounded.button}"
    height: "44px"
  input:
    backgroundColor: "{colors.panel}"
    textColor: "{colors.graphite}"
    rounded: "{rounded.md}"
    height: "44px"
    padding: "0 12px"
  badge:
    backgroundColor: "{colors.wash}"
    textColor: "{colors.graphite-2}"
    rounded: "{rounded.sm}"
    padding: "0 8px"
  nav-link-active:
    backgroundColor: "{colors.cloth-lift}"
    textColor: "{colors.on-cloth}"
    rounded: "{rounded.md}"
  ledger-card:
    backgroundColor: "{colors.panel}"
    rounded: "{rounded.card}"
---

# Design System: Dhiran

## Overview

**Creative North Star: "The Bahi-khata"**

Dhiran is the shop's own red ledger made software. One committed oxblood cloth holds the shell (rail, phone top bar, auth brand panel); inside it the pages are calm and white, and every money table keeps the ledger's rhythm: a red margin line, a double rule under the header and above totals, figures right-aligned in tabular numerals. The next action is always the one blue-black ink button.

It is a book opened all day by counter staff and checked from a phone by the owner, so density is steady and calm, never decorative. Accuracy comes first: no figure is rounded or hidden by styling. The system refuses the grey SaaS dashboard with a yellow accent, and the inherited JewelFlow look. There is no yellow or gold anywhere, enforced by `tests/Feature/NoYellowTest.php`.

**Key Characteristics:**
- Oxblood cloth shell (rail, phone top bar, auth panel); cool-white pages (#f3f4f7 ground) under white panels.
- Blue-black ink carries every action, link, figure and selected state. Four things never look alike: button, dropdown, card, link (see Affordance Vocabulary).
- Ledger furniture: red margin line, 3px double rules, 1px dotted stitch seam.
- Near-flat: cards rest on a 1px-spread hairline shadow (`--elev-1`), 12px card / 8px button / 6px field radius; only overlays lift.
- System fonts with Devanagari fallbacks, no web-font request; one serif wordmark "dhiran.".
- Fixed-height app shell: the body never scrolls.
- 44px touch targets (40px on desktop), fluid type from 320px to 1440px.

## Colors

A committed two-material palette: oxblood cloth for the shell, cool paper and blue-black ink for the pages. Red appears twice only, as cloth and as the hairline margin.

### Primary
- **Blue-Black Ink** (#1a2a4a): primary buttons, links, key figures, selected tabs and pager page, focus ring, form accents, scrollbar thumb (35% mix). Hover: **Pressed Ink** (#101c36); active press: **Deep Ink** (#0a1226). Soft fills (selected rows, secondary hover): **Ink Mist** (#eef1f7); ink hairline: #c6cee0.

### Secondary
- **Oxblood Cloth** (#7a1a1d): sidebar rail, top bar on phones, auth brand panel. **Deep Cloth** (#5a1215) for pressed parts, scrims and dialog backdrops; **Cloth Lift** (#8c2a2d) for the active or hover nav fill. Text on cloth is white (#ffffff) or **Cloth Blush** (#f0d9d9, 8:1) for secondary text. Cloth hairlines are white at 22%.

### Tertiary
- **Margin Red** (#c9484d): the ledger margin line only, 1px, 80% opacity. Never text, never a fill.

### Neutral
- **Cool Paper** (#f3f4f7): page ground (`.dh-scroll`, body). **Panel White** (#ffffff): cards, tables, the desktop top bar. **Quiet Wash** (#eaedf2): table head, muted panels, disabled fields, neutral badges, the dropdown arrow zone.
- **Graphite** (#1d2230) body text; **Graphite 2** (#444c5e) labels and table headers; **Graphite 3** (#5b6477) faintest readable text (5.9:1 on white), placeholders, double rules.
- **Rule Grey** (#d9dce3) card and header hairlines; **Soft Rule** (#e8eaef) row separators; **Field Edge** (#868fa2) control borders (3.2:1 on white).

### Status
- **Ledger Green** (#1f6f4a, soft #e9f4ee, line #b8d8c6); **Crimson** (#b3261e, soft #fcefee, line #f0c4c1), also the required-field star (see Fields); **Info Blue** (#1d4f91, soft #eaf1fb, line #bcd0ec).
- **Deep Brass-Brown** (#8a4700, line #d9ae86): warning text, outline and icon only on white. **Warning Solid** (#7d4200): the one solid use, the warning toast. Hue 33 degrees, outside the yellow band.

### Named Rules
**The No Yellow Rule.** No yellow or gold fill, text or accent anywhere. Warning is brown text on white with a brown hairline, never a tinted fill. The legacy `--jf-gold*` and `--dh-gold*` names are remapped to ink so old rules cannot reintroduce it.
**The Margin Is Hairline Rule.** Red is the cloth and the 1px margin line. It is never a button, link or fill.
**The Ink Is The Next Action Rule.** A filled blue-black control means "the next step". One per view; everything else is outlined.
**The Word Plus Tone Rule.** Status is never colour alone: a word, a tone and a shape (see Badges).

## Typography

**Display Font:** Georgia (with Times New Roman, serif), wordmark "dhiran." only
**Body Font:** system-ui (with Noto Sans, Segoe UI, Roboto, Noto Sans Devanagari)
**Label/Mono Font:** body stack; monospace only for loan numbers and identifiers in tables

**Character:** A plain system voice that costs no web-font request on low-end phones, and tolerates longer Hindi labels later. The one serif is the shop's name on the cloth.

### Hierarchy
- **Display** (600, 22 to 26px, 1.0, -0.01em): the "dhiran." wordmark in the rail, 20px on phones.
- **Headline** (700, 20 to 28px fluid, 1.2): page title (h1). Bold is reserved for h1 and key figures.
- **Title** (600, 16 to 20px, 1.2): section headings (h2); h3 is 14 to 16px/600.
- **Figure** (700, 20 to 32px, 1.2, tabular): key money figures. Stat-card values are 600 at the h2 size in ink.
- **Body** (400, 14 to 16px, 1.45): fields, buttons (600), table cells (13 to 15px). Hints and secondary text 13 to 14px; field-group and stat labels are sentence case, 500.
- **Label** (600, 12px, +0.06em, uppercase): table headers only. Nothing is set smaller than 12px.

### Named Rules
**The Tabular Rule.** All figures, tables, outputs and stat values use tabular numerals; money is right-aligned and a touch heavier than words (500; 600 for key cells).
**The Caps Are For Table Heads Rule.** All-caps appears only on table headers. Field labels, stat labels and legends are sentence case.
**The Three Weights Rule.** 400, 500 and 600. 700 only for h1 and key figures.
**The Sixteen On Phones Rule.** Fields are 16px under 768px so iOS does not zoom on focus.

## Layout

A fixed-height app shell: the body never scrolls. The rail is oxblood (full labels on desktop, icon rail on tablet 768 to 1023px, slide-in drawer under 768px). The page scrolls inside `.dh-scroll`. On list pages (Loans, Customers) only the list region scrolls: the title row and filter toolbar stay put, the table header row is sticky, the pager is pinned to the bottom, and a wide table widens its card and scrolls sideways so nothing is clipped. The footer exists only on the four Settings pages.

Spacing steps are 4, 8, 12, 16, 24, 32, 48px. Page gutter is fluid (12px at 320 up to 32px). Card padding 12 to 20px, gap 8 to 16px. Top bar is 56px on phones and 64px from 641px up. Controls are 44px high up to tablet and 40px from 1024px. Money tables carry a 14px reserved left gutter in which the margin line is drawn at 6px, so it can never touch content. On phones tables become stacked row cards, each with its own margin line at 10px.

## Elevation & Depth

Near-flat. Depth is hairlines, the cloth against the grey-white page, and tonal fills (Ink Mist on hover, Quiet Wash on muted panels). Cards and tables sit on a 1px-spread hairline plus a soft shadow; overlays lift higher. No blur, glow, glass or gradient on surfaces. Utility `shadow-*` classes inside the app are neutralised to the card token.

### Shadow Vocabulary
- **Card** (`--elev-1`: `0 0 0 1px rgba(29,34,48,.05), 0 1px 3px rgba(29,34,48,.09)`): cards, panels, tables, stat cards. Same at rest and on hover unless the card is a link (see Affordance Vocabulary).
- **Popover** (`--elev-2`): header action menu, filter select menus.
- **Lift** (`--kh-lift`: `0 12px 32px rgba(26,17,20,.22), 0 2px 6px rgba(26,17,20,.12)`): dialogs, filter panel, toast, phone drawer.
- Sticky action bars (save bar, form totals bar) carry a small upward shadow only while they float over content (under 1024px); on desktop they are plain rows.

### Named Rules
**The Cards Do Not Move Rule.** A card never lifts, grows or changes shadow on hover. Only a card that is itself a link or button may change border and tint on hover, and never move.
**The Double Rule Rule.** A 3px double rule in Graphite 3 sits under every table header and above every totals row, with the sum in ink 600. It is the ledger's way of saying "the line above is final".
**The Stitch Rule.** A 1px dotted seam marks the cloth: inside the rail edge, under the rail head, above the rail foot, and under the desktop top bar in rule grey.

## Shapes

Radius by role: **6px** fields, selects and alerts; **8px** buttons, chips and tabs; **12px** cards, panels and tables; **4px** badges and read-only labels; 50% only for a badge's dot and avatars. Utility rounded classes inside the app are forced to 6px. Borders are 1px hairlines (buttons 1.5px ink); field edges are Field Edge (#868fa2).

**The No Pills Rule (owner).** No 999px capsules: not on chips, tabs, labels or counts. In particular **no count pill in a page header** (the old `$badge` slot of `<x-dhiran.page-header>` is gone, and nothing replaces it); counts live in the table pager ("Showing 1-15 of 15") or inside a filter chip's own label. The only fully rounded shapes are the switch track, progress bar and avatars.

## Components

### Buttons
- **Shape:** 8px radius, 1.5px border, 44px high (40px desktop), 600 at body size, no letter-spacing, 1px hairline shadow.
- **Primary:** Blue-Black Ink fill, white text; hover Pressed Ink, active Deep Ink. `btn-dark`, `btn-success` map to the same fill. One per view.
- **Secondary:** white fill, ink text, ink border; hover Ink Mist.
- **Destructive:** white fill, crimson text and border; hover crimson tint.
- **Ghost:** no fill, no border, ink text; hover underlines. Only for tertiary in-row actions.
- **Focus:** 2px ink outline, 2px offset (white ring on the cloth).
- **Disabled:** a different object, not a faded one: Quiet Wash fill, Rule border, Graphite 3 text, no shadow, `not-allowed` cursor.
- **Busy:** `aria-busy="true"` shows a 14px spinner at the left of the label and ignores taps (`kh-spin`, 700ms; kept under reduced motion at 1.2s because it is information). Pair with `disabled` and a "...ing" label.
- **On the phone top bar:** the primary becomes a white button with deep-cloth text; the rest outline in white.

### Fields
- **Style:** white fill, 1px Field Edge border, 6px radius, 12px side padding, 44px high (40px desktop), placeholder Graphite 3.
- **Select chevron rule.** A dropdown is a field with an arrow zone: always `appearance: none`, 52px right padding, an ink chevron in a 40px Quiet Wash zone divided from the value by a 1px Field Edge line. No select ever renders without the chevron zone (native arrow or none are both bugs). Number fields with a unit use the same zone as a static suffix (`.st-unit`), without the chevron.
- **Hover / Focus:** border to ink; focus a 2px ink outline with 2px offset.
- **Error / Disabled:** crimson border; disabled Quiet Wash fill with Graphite 3 text.
- **Required star rule (server rules only).** The crimson star (`.ui-req`, `<x-req>`, aria-hidden) goes after the label of a field only when the server rejects it empty (a `required` rule in the FormRequest or controller validation); the HTML `required` attribute follows the same rule, never the other way round. One-line legend (`.ui-req-legend`); no "optional" wording. `tests/Feature/RequiredMarkersTest.php` guards it.
- **Borrower picker (New Loan).** A combobox that searches the server (`dhiran.borrowers.search`, at least 2 characters, 8 matches at most, latest request wins). The page never carries the borrower book; PAN and ID are fetched for the one chosen borrower. States: "Type at least 2 letters or digits", "Searching...", "No borrower matches...", and a retryable "Could not search" error.

### Badges
- **Style:** 24px high, 4px radius, 1px tone border, tone-soft fill, 13 to 14px/500; a word always present.
- **Shape cue:** circle (ok, info), triangle (warning), rotated square (danger), hollow ring (muted). The warning badge is white with brown outline text, never filled.

### Cards / Containers
- **Corner Style:** 12px (`--dh-card-radius`). **Background:** panel white on cool paper. **Border:** none (transparent) with the Card shadow (`--elev-1`). Every card family (`dh-table-card`, `dd-card`, `fm-card`, `st-panel`, `rs-group`) takes radius, border and shadow from the `--dh-card-*` tokens; never a per-page literal.
- **Stat cards and money tables:** carry the margin line; stat values in ink 600 at title size.

### Ledger Tables (signature)
Headers are 12px/600 uppercase with +0.06em, Graphite 2 on white, over a 3px double rule. Rows are 1px Soft Rule hairlines with a faint ink tint on hover. Figures are right-aligned tabular, loan numbers monospace 500, links ink 500. Totals sit under a 3px double rule in ink 600. The margin line is red at 6px in a 14px gutter.

### Affordance Vocabulary
Four things must never be mistaken for each other. Anything that breaks a row below is a bug, fixed in the shared class, not per page.
- **Button:** solid ink fill, or white with an ink border; 8px radius, 600 weight, a hairline shadow. Does something.
- **Dropdown:** a field (Field Edge border, 6px radius) with the Quiet Wash chevron zone. Chooses something.
- **Card:** a white 12px panel with the Card shadow. Holds things; no hover change, no pointer cursor, unless the whole card is a link (then border and tint change on hover, never movement).
- **Link:** ink text, underlined at rest (1px, 50% ink, 3px offset); hover darkens and thickens the line to 2px. Covers body links, text links in tables (loan numbers, names), phone numbers (`dh-tel`, `dd-tel`) and the borrower name on loan detail. Buttons, badges and rail items are never underlined. Row-action icon buttons are boxed buttons, not links.
- **Badge / label:** 4px radius, a word, not clickable, no pointer cursor. (A status badge that links, such as "Photo / ID missing", is still a badge in shape; its link is the whole badge.)

### Navigation
- **Rail:** oxblood, white 500 labels with a dotted stitch; hover a 9% white wash; active Cloth Lift with 600 weight and a 30% white inset hairline; focus a white 2px outline.
- **Top bar:** white with a Rule Grey hairline and dotted seam on desktop; cloth on phones with white title and hamburger.
- **Tabs, chips, pager:** outlined buttons at control height with the 8px button radius (never pills); hover Ink Mist with an ink border; selected is a tinted fill plus a check mark, so it is never colour alone. The settings sub-navigation is underline tabs.

### Save Bar (settings forms)
Loan settings, Receipt settings, Users & roles and Staff forms share one pattern (`<x-settings.savebar>`, state from `stSaveForm()` in `settings-pane.js`; Receipt keeps its own bar with the same three states plus Discard):
- **Clean:** status "No changes yet" (muted), Save disabled.
- **Dirty:** status "Unsaved changes" (brown, circle-alert icon), Save enabled. A pane switch or link away asks "Leave this page?" first.
- **Saving:** status "Saving..." (ink), Save disabled and busy with the spinner, a second submit is ignored. A failed save (validation or network) frees the form; a good one is replaced by the next page.
- The status is a polite live region. Under 1024px the bar is sticky at the bottom (safe-area aware); on desktop it is a plain row at the end of the form. Settings pages switch inside `<turbo-frame id="settings-pane">`: the shell, title and rail stay, only the pane swaps, and the address advances.

### States and Motion
Every control has rest, hover, focus-visible, active, disabled and (for submits) busy states, all from tokens. Motion is restrained and never blocks: only opacity, transform, colour, background, border and shadow animate, at 120ms (`--dur-1`), 180ms or 260ms with `--ease-out`. `prefers-reduced-motion` collapses transitions and animations to 1ms except the busy spinner. The page body and tab panels fade in (`kh-fade`, 120 to 140ms); nothing slides across the page or bounces.

### Alerts and Dialogs
- **Alerts:** full 1px border, 6px radius; tone by tint (ok, crimson, info) while warning is white with brown text and brown line. Icon or word carries the tone.
- **Dialogs and sheets:** flat panel with Lift shadow; backdrop is Deep Cloth at 45%.

## Do's and Don'ts

### Do:
- **Do** let the oxblood cloth carry the shell and keep the pages light (#f3f4f7 ground, #ffffff panels).
- **Do** use Blue-Black Ink (#1a2a4a) for the primary action, links, key figures, selected states and the focus ring.
- **Do** draw the red margin line (#c9484d, 1px) at 6px inside a 14px reserved gutter on money tables, stat cards and report grids.
- **Do** put a 3px double rule under table headers and above totals, and right-align money in tabular numerals.
- **Do** pair every status colour with a word and a distinct shape.
- **Do** size controls with `--fl-ctl-h` (44px touch, 40px desktop) and text with the fluid scale; keep metadata at 12px or larger.
- **Do** keep list pages scrolling only inside the list region, with a sticky thead and a pinned pager.
- **Do** write warnings as #8a4700 text with a #d9ae86 outline on white.
- **Do** keep colours in `khata-tokens.css` only; new code uses `--kh-*`, never a new literal.

### Don't:
- **Don't** use yellow or gold anywhere; warning is never a filled yellow.
- **Don't** use red as a button, link or fill; it is cloth and margin line only.
- **Don't** lift, grow or re-shadow a card on hover; only overlays lift, and only a link card may change border and tint.
- **Don't** use pills: no 999px chips, tabs, labels or count badges, and never a count pill in a page header.
- **Don't** use gradients, glass, glow or blur.
- **Don't** use all-caps outside table headers, or text under 12px.
- **Don't** use a web font; the wordmark serif is the one exception to the system stack.
- **Don't** let the page body scroll, or add a footer outside the four Settings pages.
- **Don't** rely on colour alone for status or required fields.
- **Don't** let the margin line touch cell content; draw it only in the reserved gutter.
- **Don't** leave a link as plain coloured text or underline a button: links are underlined at rest, buttons never.
- **Don't** render a select without its chevron zone, or mark a field required (star or attribute) that the server accepts empty.
- **Don't** invent testimonials or stats on public pages.
