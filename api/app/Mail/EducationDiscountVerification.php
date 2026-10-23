<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EducationDiscountVerification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $verificationCode,
        public string $audienceLabel,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Mã xác minh ưu đãi TechStore');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.education-discount-verification');
    }
}
