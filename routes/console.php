<?php
use Illuminate\Support\Facades\Schedule;
Schedule::command('dhiran:accrue-interest')->daily()->withoutOverlapping();
Schedule::command('dhiran:forfeiture-check')->daily()->withoutOverlapping();
Schedule::command('subscription:check-expiry')->daily()->withoutOverlapping();
Schedule::command('dhiran:purge-old-loan-uploads')->daily()->withoutOverlapping();
Schedule::command('dhiran:prune-idempotency-keys')->daily()->withoutOverlapping();
