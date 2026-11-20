<?php
return [
    'default' => env('QUEUE_CONNECTION', 'database'), 'connections' => ['database' => ['driver' => 'database', 'connection' => 'pgsql', 'table' => 'jobs', 'queue' => env('QUEUE_NAME', 'dhiran-local'), 'retry_after' => 90, 'after_commit' => true]], 'failed' => ['driver' => 'database-uuids', 'database' => 'pgsql', 'table' => 'failed_jobs']
];
