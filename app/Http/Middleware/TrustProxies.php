<?php
namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;

class TrustProxies extends Middleware
{
    /** Read at request time: bootstrap/app.php middleware config runs before .env is loaded, so env() there misses TRUSTED_PROXIES. */
    protected function proxies()
    {
        $list = trim((string) config('app.trusted_proxies'));
        return $list === '*' ? '*' : (array_values(array_filter(array_map('trim', explode(',', $list)))) ?: null);
    }
}
