@extends('auth.base') @section('title','Verify your email') @section('content')
<h1>Verify your email.</h1><p class="lede">Verify your email to continue. It is also how you reset your password if you ever forget it.</p>
<form id="step1" novalidate>
<x-site.field name="email" label="Email address" type="email" :value="$user->email ?? ''" autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" placeholder="you@example.com" />
<button type="submit" id="sendBtn" class="ui-btn ui-btn--gold ui-btn--block ui-submit">Send verification code</button></form>
<form id="step2" novalidate hidden>
<div class="ui-field"><div class="ui-field-top"><label for="otp">Enter the 6-digit code</label></div><div class="ui-input"><input id="otp" class="ui-otp" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" placeholder="000000" aria-describedby="msg"></div></div>
<button type="submit" id="verifyBtn" class="ui-btn ui-btn--gold ui-btn--block ui-submit">Verify &amp; continue</button>
<div class="auth-links"><button type="button" class="linkbtn" id="resendBtn">Resend code</button><button type="button" class="linkbtn" id="backBtn">Change email</button></div></form>
<div id="msg" class="ui-hint" aria-live="polite" style="font-size:var(--fs-sm)"></div>
<div class="auth-alt"><span>Signed in as {{ \App\Support\Mobile::forDisplay($user->mobile_number) }}</span>
<form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="linkbtn" style="padding-left:0">Sign out</button></form></div>
@endsection
@push('scripts')
<script>
(function () {
    var $ = function (id) { return document.getElementById(id); }, csrf = document.querySelector('meta[name="csrf-token"]').content, tick;
    var msg = function (t, ok) { var m = $('msg'); m.textContent = t; m.style.color = ok ? 'var(--dh-success)' : 'var(--dh-danger)'; m.setAttribute('role', ok ? 'status' : 'alert'); };
    var post = function (url, body) { return fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'}, body: JSON.stringify(body)})
        .then(function (r) { return r.json().catch(function () { return {}; }).then(function (data) { return {ok: r.ok, data: data}; }); }); };
    var firstError = function (d) { if (d.message) { return d.message; } if (d.errors) { return d.errors[Object.keys(d.errors)[0]][0]; } return 'Something went wrong. Please try again.'; };
    var cooldown = function (s) { clearInterval(tick); var b = $('resendBtn'); b.disabled = true;
        tick = setInterval(function () { s--; if (s <= 0) { clearInterval(tick); b.disabled = false; b.textContent = 'Resend code'; } else { b.textContent = 'Resend code in ' + s + 's'; } }, 1000); b.textContent = 'Resend code in ' + s + 's'; };
    var show = function (two) { $('step1').hidden = two; $('step2').hidden = !two; (two ? $('otp') : $('email')).focus(); };
    var sent = function (r) { if (r.ok) { show(true); cooldown(60); msg(r.data.message || 'Code sent. Check your inbox.', true); } else { msg(firstError(r.data), false); } };
    $('step1').addEventListener('submit', function (e) { e.preventDefault(); var email = $('email').value.trim(); if (!email) { return msg('Please enter your email.', false); }
        var b = $('sendBtn'); b.disabled = true; post('{{ route('dhiran.verify-email.send') }}', {email: email}).then(function (r) { b.disabled = false; sent(r); }); });
    $('step2').addEventListener('submit', function (e) { e.preventDefault(); var otp = $('otp').value.trim(); if (otp.length !== 6) { return msg('Enter the 6-digit code.', false); }
        var b = $('verifyBtn'); b.disabled = true; post('{{ route('dhiran.verify-email.verify') }}', {otp: otp}).then(function (r) {
            if (r.ok) { msg('Email verified. Redirecting…', true); setTimeout(function () { window.location.href = '{{ url('/') }}'; }, 800); } else { b.disabled = false; msg(firstError(r.data), false); } }); });
    $('resendBtn').addEventListener('click', function () { post('{{ route('dhiran.verify-email.resend') }}', {}).then(function (r) { if (r.ok) { cooldown(60); msg(r.data.message || 'Code resent.', true); } else { msg(firstError(r.data), false); } }); });
    $('backBtn').addEventListener('click', function () { show(false); $('msg').textContent = ''; });
    $('otp').addEventListener('input', function () { this.value = this.value.replace(/\D/g, '').slice(0, 6); });
})();
</script>
@endpush
