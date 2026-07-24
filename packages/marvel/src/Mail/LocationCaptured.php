<?php

namespace Marvel\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Marvel\Database\Models\LocationCaptureRequest;

/** Admin notice: a vendor's location was captured successfully. */
class LocationCaptured extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public LocationCaptureRequest $request;

    public function __construct(LocationCaptureRequest $request)
    {
        $this->request = $request;
    }

    public function build()
    {
        $name = $this->request->vendor?->name ?? $this->request->user?->name ?? $this->request->email;

        return $this->subject("Location captured: {$name}")
            ->markdown('emails.location-captured', ['req' => $this->request, 'name' => $name]);
    }
}
