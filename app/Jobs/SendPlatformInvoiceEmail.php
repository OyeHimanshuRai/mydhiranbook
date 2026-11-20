<?php

namespace App\Jobs;

use App\Mail\PlatformInvoiceMail;
use App\Models\Platform\PlatformInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPlatformInvoiceEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    /**
     * Never queue until the surrounding transaction has committed: the receipt is issued inside the payment
     * transaction, and a worker must not look for a row that is not visible yet (or was rolled back).
     * Set in the constructor because Queueable already declares `public $afterCommit;`.
     */
    public function __construct(public readonly int $invoiceId)
    {
        $this->afterCommit = true;
    }

    public function handle(): void
    {
        $invoice = PlatformInvoice::with(['subscription.user', 'plan'])->find($this->invoiceId);
        if (! $invoice) {
            Log::warning('SendPlatformInvoiceEmail: invoice not found', ['invoice_id' => $this->invoiceId]);
            return;
        }

        $email = $invoice->subscription?->user?->email;
        if (! $email) {
            Log::warning('SendPlatformInvoiceEmail: payer has no email address, receipt not sent', ['invoice_id' => $this->invoiceId]);
            return;
        }

        Mail::to($email)->send(new PlatformInvoiceMail($invoice));
    }
}
