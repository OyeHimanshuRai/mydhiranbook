<?php
use Illuminate\Support\Facades\Schedule;
Schedule::command('dhiran:accrue-interest')->daily()->withoutOverlapping();
Schedule::command('dhiran:forfeiture-check')->daily()->withoutOverlapping();
