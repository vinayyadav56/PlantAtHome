<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Application\Rendering;

use App\Modules\Marketing\Domain\Channel;
use App\Modules\Marketing\Domain\VariableMapper;

/**
 * Turns a channel-shaped template + one recipient row into the concrete
 * {subject, body} that gets stored on the notification. Email assembles
 * header + body + buttons + footer into one HTML document; SMS/WhatsApp render
 * their single body string. All {{variables}} are bound from the row.
 */
final class MessageRenderer
{
    /**
     * @param array<string,mixed> $content the template's content payload
     * @param array<string,mixed> $row      one recipient's audience row
     * @return array{subject: ?string, body: string}
     */
    public static function render(string $channel, array $content, array $row): array
    {
        return match ($channel) {
            Channel::EMAIL => self::email($content, $row),
            default        => [
                'subject' => null,
                'body'    => VariableMapper::render((string) ($content['body'] ?? ''), $row),
            ],
        };
    }

    /** @param array<string,mixed> $content @param array<string,mixed> $row */
    private static function email(array $content, array $row): array
    {
        $subject = VariableMapper::render((string) ($content['subject'] ?? ''), $row);

        $parts = [];
        if (! empty($content['header'])) {
            $parts[] = (string) $content['header'];
        }
        $parts[] = (string) ($content['html'] ?? $content['text'] ?? '');

        foreach ((array) ($content['buttons'] ?? []) as $button) {
            $label = trim((string) ($button['label'] ?? ''));
            $url = trim((string) ($button['url'] ?? ''));
            if ($label !== '' && $url !== '') {
                $parts[] = sprintf(
                    '<p style="margin:16px 0"><a href="%s" style="display:inline-block;padding:10px 20px;'
                    .'background:#1F6B3D;color:#fff;border-radius:8px;text-decoration:none;font-weight:600">%s</a></p>',
                    htmlspecialchars($url, ENT_QUOTES),
                    htmlspecialchars($label, ENT_QUOTES),
                );
            }
        }

        if (! empty($content['footer'])) {
            $parts[] = (string) $content['footer'];
        }

        return [
            'subject' => $subject,
            'body'    => VariableMapper::render(implode("\n", $parts), $row),
        ];
    }
}
