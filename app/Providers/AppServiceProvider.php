<?php
namespace App\Providers;
use App\Support\RuntimeGuard;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('db.connector.pgsql', \App\Support\LocalPostgresConnector::class);
    }

    public function boot(): void
    {
        \Illuminate\Support\Facades\Gate::before(fn ($user, $ability) => $user instanceof \App\Models\User ? $user->hasPermission($ability) : null);
        RuntimeGuard::verify($this->app);
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            RuntimeGuard::verifyConnection($event->connection->getPdo(), $this->app->environment(), base_path());
        });
        RuntimeGuard::verifyConnection(DB::connection()->getPdo(), $this->app->environment(), base_path());
    }
}
