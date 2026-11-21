<?php

namespace Tests\Feature\Dhiran;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Abandoned Old Loans uploads hold plaintext PAN / ID: the daily purge removes those older than 24 hours. */
class PurgeOldLoanUploadsTest extends TestCase
{
    public function test_purges_only_uploads_older_than_a_day_in_every_shop(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        foreach (['dhiran-old-loans/1/old.csv', 'dhiran-old-loans/1/fresh.csv', 'dhiran-old-loans/2/old.xlsx', 'dhiran-old-loans/2/fresh.xlsx'] as $f) {
            $disk->put($f, 'pan,ABCDE1234F');
        }
        touch($disk->path('dhiran-old-loans/1/old.csv'), now()->subHours(25)->getTimestamp());
        touch($disk->path('dhiran-old-loans/2/old.xlsx'), now()->subDays(5)->getTimestamp());
        touch($disk->path('dhiran-old-loans/1/fresh.csv'), now()->subHours(23)->getTimestamp());

        $this->artisan('dhiran:purge-old-loan-uploads')->assertSuccessful();

        $disk->assertMissing('dhiran-old-loans/1/old.csv');
        $disk->assertMissing('dhiran-old-loans/2/old.xlsx');
        $disk->assertExists('dhiran-old-loans/1/fresh.csv');
        $disk->assertExists('dhiran-old-loans/2/fresh.xlsx');
    }

    public function test_is_scheduled_daily(): void
    {
        $this->assertTrue(collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->contains(fn ($e) => str_contains($e->command, 'dhiran:purge-old-loan-uploads') && $e->expression === '0 0 * * *'));
    }
}
