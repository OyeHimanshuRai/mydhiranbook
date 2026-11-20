<?php
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: ['subscription/payment/webhook']);
        $middleware->web(append: [Illuminate\Session\Middleware\AuthenticateSession::class]);
        $middleware->web(prepend: [App\Http\Middleware\UseRouteScopedSessionCookie::class]);
        $middleware->prependToPriorityList(Illuminate\Routing\Middleware\SubstituteBindings::class, App\Http\Middleware\UseShop::class);
        $middleware->prependToPriorityList(App\Http\Middleware\UseShop::class, App\Http\Middleware\EnsureShopExists::class);
        $middleware->prependToPriorityList(App\Http\Middleware\EnsureShopExists::class, App\Http\Middleware\EnsureDhiranEmailVerified::class);
        $middleware->alias(['shop' => App\Http\Middleware\UseShop::class, 'admin.session'=>App\Http\Middleware\AdminSession::class, 'dhiran.verified'=>App\Http\Middleware\EnsureDhiranEmailVerified::class,'shop.exists'=>App\Http\Middleware\EnsureShopExists::class,'dhiran.enabled'=>App\Http\Middleware\EnsureDhiranEnabled::class]);
    })->withExceptions(function (Illuminate\Foundation\Configuration\Exceptions $exceptions) {})->create();

if (getenv('APP_ENV') === 'testing' || ($_ENV['APP_ENV'] ?? '') === 'testing') {
    $app->useStoragePath(realpath(__DIR__.'/../.runtime/testing/storage'));
}
$app->dontMergeFrameworkConfiguration();
return $app;
