<?php

namespace Marvel\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ContactAdmin extends Mailable
{
    use Queueable, SerializesModels;

    public $details;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($details)
    {
        $this->details = $details;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        // From must be OUR verified sender — SendGrid 403s a client-supplied From
        // (so this form never delivered), and it's a spoofing surface besides.
        // The submitter goes in Reply-To so "Reply" still reaches them.
        return $this->replyTo($this->details['email'], $this->details['name'] ?? null)
            ->markdown('emails.contact-admin');
    }
}
