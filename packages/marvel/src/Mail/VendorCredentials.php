<?php

namespace Marvel\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VendorCredentials extends Mailable
{
    use Queueable, SerializesModels;

    public $email;
    public $password;
    public $shopName;
    public $ownerName;
    public $loginUrl;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($email, $password, $shopName, $ownerName, $loginUrl)
    {
        $this->email = $email;
        $this->password = $password;
        $this->shopName = $shopName;
        $this->ownerName = $ownerName;
        $this->loginUrl = $loginUrl;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this->subject('Your PlantAtHome vendor account')
            ->markdown('emails.vendor-credentials');
    }
}
