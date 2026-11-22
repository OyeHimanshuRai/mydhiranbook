{{-- The required-field star (owner 2026-11-22: a star marks what must be filled; unmarked fields are optional, never said in words).
     Inline right after the label text so long or translated labels wrap cleanly; decorative, the control carries aria-required.
     Only for a plain `required` rule on the server; a nullable or conditional field gets no star. Extra attributes land on the span. --}}
<span {{ $attributes->merge(['class' => 'ui-req', 'aria-hidden' => 'true']) }}>*</span>