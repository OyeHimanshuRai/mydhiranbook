<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** A Razorpay payment was captured but could not be applied. Carries references only, never secrets or payloads. */
class UnresolvedPaymentAlert extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $paymentId, public string $orderId, public string $reason, public bool $transient) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Dhiran payment needs review: '.($this->paymentId ?: 'unknown payment'));
    }

    public function content(): Content { return new Content(text: 'mail.unresolved-payment'); }
}
