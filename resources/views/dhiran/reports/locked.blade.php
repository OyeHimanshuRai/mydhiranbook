<x-dhiran-layout title="Reports">
    <div style="position:fixed;inset:0;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;z-index:50;padding:16px">
        <div role="dialog" aria-modal="true" aria-labelledby="report-lock-title" style="background:#fff;border-radius:12px;max-width:380px;width:100%;padding:24px;box-shadow:0 20px 50px rgba(0,0,0,.25)">
            <h2 id="report-lock-title" style="font-size:1.125rem;font-weight:700;margin:0 0 6px">Enter your password to open reports</h2>
            <p style="font-size:.875rem;color:#6b7280;margin:0 0 16px">Reports stay open for 1 hour after you confirm.</p>
            <form method="post" action="{{ route('password.confirm.store') }}" data-turbo="false">
                @csrf
                <label for="report-password" style="display:block;font-size:.8125rem;font-weight:600;margin-bottom:4px">Password</label>
                <input id="report-password" name="password" type="password" required autofocus autocomplete="current-password"
                       style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px">
                @error('password')<p role="alert" style="color:#b91c1c;font-size:.8125rem;margin:6px 0 0">{{ $message }}</p>@enderror
                <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px">
                    <a href="{{ route('dhiran.loans') }}" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Open reports</button>
                </div>
            </form>
        </div>
    </div>
</x-dhiran-layout>
