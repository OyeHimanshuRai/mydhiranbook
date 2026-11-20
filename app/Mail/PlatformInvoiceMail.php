<?php

namespace App\Mail;

use App\Models\Platform\PlatformInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PlatformInvoiceMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly PlatformInvoice $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->invoice->documentTitle().' '.$this->invoice->invoice_number.' - '.($this->invoice->plan?->name ?? 'Subscription').' | '.config('app.name'));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.platform-invoice', with: [
            'invoice' => $this->invoice, 'plan' => $this->invoice->plan, 'payer' => $this->invoice->subscription?->user,
        ]);
    }
}
