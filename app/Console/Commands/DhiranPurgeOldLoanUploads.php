<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DhiranPurgeOldLoanUploads extends Command
{
    protected $signature = 'dhiran:purge-old-loan-uploads';
    protected $description = 'Delete abandoned Old Loans import spreadsheets (they hold plaintext PAN / ID) older than 24 hours';

    public function handle(): int
    {
        // The upload file is the whole record: the preview token is its file name, no DB or cache row exists.
        $disk = Storage::disk('local');
        $cutoff = now()->subDay()->getTimestamp();
        $purged = 0;

        foreach ($disk->allFiles('dhiran-old-loans') as $file) {
            if ($disk->lastModified($file) < $cutoff && $disk->delete($file)) {
                $purged++;
            }
        }

        $this->info("Purged {$purged} abandoned old-loan upload(s).");

        return self::SUCCESS;
    }
}
