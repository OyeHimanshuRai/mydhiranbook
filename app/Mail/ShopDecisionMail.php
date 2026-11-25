<?php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Tells the owner their new shop was approved (sign in and start) or turned down (with the reason). */
class ShopDecisionMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $shopName, public bool $approved, public ?string $note = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->approved ? 'Your MyDhiranBook shop is approved' : 'About your MyDhiranBook shop request');
    }

    public function content(): Content { return new Content(view: 'emails.shop-decision'); }
}
