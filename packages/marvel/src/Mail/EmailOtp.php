<?php

namespace Marvel\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * One-time code emailed to verify a customer's email address. Inline HTML so it
 * is self-contained in the marvel package (no published blade template needed).
 */
class EmailOtp extends Mailable
{
    use Queueable, SerializesModels;

    public string $code;

    public function __construct(string $code)
    {
        $this->code = $code;
    }

    public function build()
    {
        $code = e($this->code);
        $html = <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:0 auto;padding:24px;color:#16301A">
  <h2 style="color:#2E5E2A;margin:0 0 12px">Verify your email</h2>
  <p style="font-size:15px;line-height:1.5;color:#333">Use this one-time code to verify your email address on <strong>PlantAtHome</strong>. It expires in 10 minutes.</p>
  <div style="font-size:32px;font-weight:700;letter-spacing:8px;color:#2E5E2A;background:#F1F6EE;border-radius:10px;text-align:center;padding:16px 0;margin:20px 0">$code</div>
  <p style="font-size:13px;color:#888">If you didn't request this, you can safely ignore this email.</p>
</div>
HTML;

        return $this->subject('Your PlantAtHome verification code')->html($html);
    }
}
