<?php

namespace Marvel\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** "Please confirm your location" — the capture-link email. */
class LocationCaptureMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public string $recipientName;
    public string $captureUrl;
    public string $requestUuid;
    public int $expiryHours;

    public function __construct(string $recipientName, string $captureUrl, string $requestUuid, int $expiryHours)
    {
        $this->recipientName = $recipientName;
        $this->captureUrl    = $captureUrl;
        $this->requestUuid   = $requestUuid;
        $this->expiryHours   = $expiryHours;
    }

    public function build()
    {
        return $this->subject('Please Confirm Your Location')
            ->markdown('emails.location-capture', [
                'recipientName' => $this->recipientName,
                'captureUrl'    => $this->captureUrl,
                'openPixelUrl'  => url('/location/open/' . $this->requestUuid . '.gif'),
                'expiryHours'   => $this->expiryHours,
            ]);
    }
}
