<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
/** No full CSP (owner ruling); HSTS only over TLS. */
class SecurityHeaders
{
    public function handle(Request $r, Closure $next)
    {
        $res=$next($r);
        $res->headers->add(['X-Frame-Options'=>'DENY','X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'strict-origin-when-cross-origin',
            // the camera is used for borrower, item and ID photos on this site only; nothing else may use device features
            'Permissions-Policy'=>'camera=(self), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
            'Cross-Origin-Opener-Policy'=>'same-origin']);
        $res->headers->remove('X-Powered-By');
        if ($r->isSecure()) { $res->headers->set('Strict-Transport-Security','max-age=31536000; includeSubDomains'); }
        return $res;
    }
}
