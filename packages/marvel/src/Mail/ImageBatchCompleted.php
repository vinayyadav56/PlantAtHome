<?php

namespace Marvel\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Marvel\Database\Models\ImageBatch;

/** Completion (or failure) summary for a background AI image batch. */
class ImageBatchCompleted extends Mailable
{
    use Queueable;
    use SerializesModels;

    public ImageBatch $batch;

    public function __construct(ImageBatch $batch)
    {
        $this->batch = $batch;
    }

    public function build()
    {
        $batch = $this->batch;

        $subject = sprintf(
            'AI image batch %s %s — %d/%d images',
            $batch->display_id,
            str_replace('_', ' ', (string) $batch->status),
            (int) $batch->generated_count,
            (int) $batch->total_images
        );

        return $this->subject($subject)->html($this->htmlBody($batch));
    }

    private function htmlBody(ImageBatch $batch): string
    {
        $rows = [
            'Batch'     => $batch->display_id,
            'Status'    => str_replace('_', ' ', (string) $batch->status),
            'Plants'    => number_format((int) $batch->valid_rows),
            'Generated' => number_format((int) $batch->generated_count) . ' / ' . number_format((int) $batch->total_images),
            'Failed'    => number_format((int) $batch->failed_count),
        ];
        if ($batch->row_error_count) {
            $rows['Skipped rows'] = number_format((int) $batch->row_error_count);
        }
        if ($batch->zip_error) {
            $rows['Packaging error'] = e($batch->zip_error);
        }

        $table = '';
        foreach ($rows as $label => $value) {
            $table .= "<tr><td style=\"padding:6px 16px 6px 0;color:#6b7280;\">{$label}</td>"
                . "<td style=\"padding:6px 0;font-weight:600;color:#111827;\">{$value}</td></tr>";
        }

        $links = '';
        foreach ((array) ($batch->zip_paths ?? []) as $part) {
            $url = Storage::disk('s3')->url($part['path']);
            $links .= "<p><a href=\"{$url}\">Download part {$part['part']} ("
                . number_format(($part['bytes'] ?? 0) / 1048576, 1) . ' MB)</a></p>';
        }

        return '<div style="font-family:Arial,sans-serif;max-width:560px;">'
            . '<h2 style="color:#111827;">AI image batch ' . $batch->display_id . '</h2>'
            . '<table>' . $table . '</table>'
            . $links
            . '<p style="color:#6b7280;font-size:13px;">Files are retained for '
            . (int) config('image-batches.retention_days', 30)
            . ' days. Manage this batch from Admin → Tools → AI Image Generator → Background Batches.</p>'
            . '</div>';
    }
}
