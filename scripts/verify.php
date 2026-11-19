<?php
require dirname(__DIR__).'/vendor/autoload.php';
try {
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$identity = App\Support\RuntimeGuard::verifyConnection(Illuminate\Support\Facades\DB::connection()->getPdo(), $app->environment(), base_path());
echo 'Verified Dhiran targets: '.json_encode($identity, JSON_UNESCAPED_SLASHES).PHP_EOL;
echo 'Storage: '.$app->storagePath().'; cache: file; session: '.config('session.cookie').'; queue: '.config('queue.connections.database.queue').PHP_EOL;

} catch (Throwable $e) { fwrite(STDERR, $e->getMessage().PHP_EOL); exit(1); }
