<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeCustomer extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public string $customerName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Chào mừng bạn đến với TechStore');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.welcome');
    }
}
