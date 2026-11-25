<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->replace(Illuminate\Http\Middleware\TrustProxies::class, App\Http\Middleware\TrustProxies::class); // TRUSTED_PROXIES via config('app.trusted_proxies')
        $middleware->append(App\Http\Middleware\SecurityHeaders::class);
        $middleware->web(append: [Illuminate\Session\Middleware\AuthenticateSession::class]);
        $middleware->web(prepend: [App\Http\Middleware\UseRouteScopedSessionCookie::class]);
        $middleware->prependToPriorityList(Illuminate\Routing\Middleware\SubstituteBindings::class, App\Http\Middleware\UseShop::class);
        $middleware->prependToPriorityList(App\Http\Middleware\UseShop::class, App\Http\Middleware\EnsureShopExists::class);
        $middleware->prependToPriorityList(App\Http\Middleware\EnsureShopExists::class, App\Http\Middleware\EnsureDhiranEmailVerified::class);
        $middleware->alias(['shop' => App\Http\Middleware\UseShop::class, 'admin.session'=>App\Http\Middleware\AdminSession::class, 'dhiran.verified'=>App\Http\Middleware\EnsureDhiranEmailVerified::class,'shop.exists'=>App\Http\Middleware\EnsureShopExists::class,'dhiran.enabled'=>App\Http\Middleware\EnsureDhiranEnabled::class,'report.password'=>App\Http\Middleware\ConfirmReportPassword::class]);
    })->withExceptions(function (Illuminate\Foundation\Configuration\Exceptions $exceptions) {
        // JSON clients get a fixed sentence per status, never the text an abort() or exception carried (it can name SQL, hosts or paths).
        $generic = [401 => 'Please sign in.', 403 => 'You do not have access.', 404 => 'Not found.', 405 => 'Method not allowed.', 419 => 'This page expired. Refresh and try again.', 429 => 'Too many requests. Wait a minute and try again.', 500 => 'Something went wrong on our side.', 503 => 'We are updating. Try again in a few minutes.'];
        $exceptions->render(function (Illuminate\Auth\Access\AuthorizationException $e, Illuminate\Http\Request $r) use ($generic) {
            return $r->expectsJson() ? response()->json(['message' => $generic[403]], 403) : null;
        });
        $exceptions->render(function (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e, Illuminate\Http\Request $r) use ($generic) {
            return $r->expectsJson() ? response()->json(['message' => $generic[$e->getStatusCode()] ?? 'Request failed.'], $e->getStatusCode(), $e->getHeaders()) : null;
        });
    })->create();

if (getenv('APP_ENV') === 'testing' || ($_ENV['APP_ENV'] ?? '') === 'testing') {
    $app->useStoragePath(realpath(__DIR__.'/../.runtime/testing/storage'));
}
$app->dontMergeFrameworkConfiguration();
return $app;
