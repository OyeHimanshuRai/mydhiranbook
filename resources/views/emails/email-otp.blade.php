<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Email verification code</title></head>
<body style="margin:0;padding:0;background:#f5f7fb;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif">
<div style="max-width:520px;margin:40px auto;background:#fff;border:1px solid #e2e8f0">
  <div style="background:#7a1a1d;padding:24px 32px;color:#fff;font-size:20px;font-weight:800">Dhiran</div>
  <div style="padding:32px">
    <p style="font-size:15px;color:#374151">Hi {{ $name }},</p>
    <p style="font-size:13px;color:#64748b">Your Dhiran email verification code is:</p>
    <div style="background:#f8fafc;border:2px solid #e2e8f0;padding:24px;text-align:center;margin:24px 0">
      <div style="font-size:42px;font-weight:800;letter-spacing:.18em;color:#1a2a4a">{{ $otp }}</div>
      <div style="margin-top:10px;font-size:12px;color:#94a3b8">Expires in {{ \App\Services\OtpService::EXPIRY_MINUTES }} minutes</div>
    </div>
    <p style="font-size:13px;color:#64748b"><strong>Do not share this code.</strong> Dhiran will never ask for it by phone or chat.</p>
    <p style="font-size:12px;color:#94a3b8;border-top:1px solid #f1f5f9;padding-top:16px">If you did not request this, you can ignore this email.</p>
  </div>
</div></body></html>
