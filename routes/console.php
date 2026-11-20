<?php
use Illuminate\Support\Facades\Schedule;
Schedule::command('dhiran:accrue-interest')->daily()->withoutOverlapping();
Schedule::command('dhiran:forfeiture-check')->daily()->withoutOverlapping();
Schedule::command('subscription:reconcile-payments')->everyTenMinutes()->withoutOverlapping(20);
Schedule::command('subscription:check-expiry')->daily()->withoutOverlapping();
Schedule::command('dhiran:overdue-reminders')->daily()->withoutOverlapping();
