<!doctype html>
<html><body style="margin:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#0f172a">
<div style="max-width:520px;margin:24px auto;background:#fff;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden">
  <div style="background:#7a1a1d;padding:24px 32px;color:#fff;font-size:20px;font-weight:800">MyDhiranBook</div>
  <div style="padding:28px 32px">
    @if($approved)
      <p style="font-size:16px;margin:0 0 12px"><strong>{{ $shopName }}</strong> is approved.</p>
      <p style="font-size:14px;color:#334155">Sign in to open your dashboard and start your pledge book.</p>
      <p><a href="{{ url('/login') }}" style="display:inline-block;background:#7a1a1d;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px;font-weight:700">Sign in</a></p>
    @else
      <p style="font-size:16px;margin:0 0 12px">We could not approve <strong>{{ $shopName }}</strong>.</p>
      @if($note)<p style="font-size:14px;color:#334155"><strong>Reason:</strong> {{ $note }}</p>@endif
      <p style="font-size:14px;color:#334155">If you think this is a mistake, reply to this email or write to us from the contact page.</p>
    @endif
  </div>
</div>
</body></html>
