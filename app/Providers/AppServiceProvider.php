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
        // Every model id in a URL is numeric: a non-numeric value must 404 here, not reach Postgres as a bigint and 500.
        \Illuminate\Support\Facades\Route::patterns(array_fill_keys(['admin','attachment','customer','event','invoice','item','loan','payment','plan','role','shop','staff'], '[0-9]{1,18}')); // 18 digits max: a longer id is out of bigint range and would 500 in Postgres
        \Illuminate\Support\Facades\Gate::before(fn ($user, $ability) => $user instanceof \App\Models\User ? $user->hasPermission($ability) : null);
        RuntimeGuard::verify($this->app);
        if (RuntimeGuard::isLocal($this->app->environment())) {
            Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
                RuntimeGuard::verifyConnection($event->connection->getPdo(), $this->app->environment(), base_path());
            });
            RuntimeGuard::verifyConnection(DB::connection()->getPdo(), $this->app->environment(), base_path());
        }
    }
}
