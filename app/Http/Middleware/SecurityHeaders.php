<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
/**
 * HSTS only over TLS. Content-Security-Policy in two parts (see docs/deployment.md, "Content-Security-Policy"):
 *  - ENFORCED now: object-src 'none', base-uri 'self', frame-ancestors 'none' (nothing on the site needs plugins, a foreign <base>, or framing).
 *  - REPORT-ONLY: the full policy. It cannot be enforced yet: the pages use Alpine's standard build (evaluates x-* expressions, needs
 *    'unsafe-eval'), 13 inline <script> blocks, 33 inline on*= handlers and inline styles (all need 'unsafe-inline'), so a script-src that
 *    enforces would either break the app or protect nothing. Browsers log violations of the report-only policy in the console.
 * Responses that set their own policy (uploaded images: `sandbox`) and PDFs are left alone.
 */
class SecurityHeaders
{
    public const ENFORCED = "object-src 'none'; base-uri 'self'; frame-ancestors 'none'";
    // blob:/data: images are camera and photo previews; media blob: for recordings is not used but costs nothing; the camera stream itself (getUserMedia) is not a CSP resource.
    public const FULL = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; font-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    public function handle(Request $r, Closure $next)
    {
        $res=$next($r);
        $res->headers->add(['X-Frame-Options'=>'DENY','X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'strict-origin-when-cross-origin',
            // the camera is used for borrower, item and ID photos on this site only; nothing else may use device features
            'Permissions-Policy'=>'camera=(self), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
            'Cross-Origin-Opener-Policy'=>'same-origin']);
        $res->headers->remove('X-Powered-By');
        if (!$res->headers->has('Content-Security-Policy') && !str_starts_with((string) $res->headers->get('Content-Type'), 'application/pdf')) { // a PDF is shown by the browser's own viewer, which refuses restricted documents
            $res->headers->set('Content-Security-Policy', self::ENFORCED);
            $res->headers->set('Content-Security-Policy-Report-Only', self::FULL);
        }
        if ($r->isSecure()) { $res->headers->set('Strict-Transport-Security','max-age=31536000; includeSubDomains'); }
        return $res;
    }
}
