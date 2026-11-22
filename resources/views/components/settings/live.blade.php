{{-- Announces the page's success / error flash to screen readers (the shell's flash box is not a live region), and sends focus
     to the first invalid field after a failed save. Include once per settings page. --}}
@php $say = session('success') ?? session('error') ?? ($errors->any() ? 'Not saved. ' . count($errors->keys()) . ' ' . \Illuminate\Support\Str::plural('field', count($errors->keys())) . (count($errors->keys()) === 1 ? ' needs' : ' need') . ' attention.' : null); @endphp
<div class="st-sr" role="status" aria-live="polite" x-data x-init="$nextTick(() => { $el.textContent = @js($say ?? ''); document.querySelector('.st-page [aria-invalid=true]')?.focus({ preventScroll: false }); })"></div>
