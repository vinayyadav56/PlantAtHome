<?php

declare(strict_types=1);

namespace App\Modules\Marketing\Domain;

/**
 * Handlebars-lite templating for message bodies: {{name}}, {{order_number}} …
 * Extracts the variable names a template declares (so the UI can auto-map them
 * to audience SQL columns) and renders a template against one recipient row.
 * A missing variable renders as empty string — never leaks the raw {{token}}.
 */
final class VariableMapper
{
    private const PATTERN = '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_\.]*)\s*\}\}/';

    /**
     * Distinct variable names used anywhere in the given strings, in first-seen
     * order.
     *
     * @return string[]
     */
    public static function extract(string ...$texts): array
    {
        $vars = [];
        foreach ($texts as $text) {
            if (preg_match_all(self::PATTERN, $text, $matches)) {
                foreach ($matches[1] as $name) {
                    $vars[$name] = true;
                }
            }
        }

        return array_keys($vars);
    }

    /**
     * Substitute {{var}} tokens with values from $row. Values are cast to string;
     * unknown/blank vars become ''. $row keys are matched case-insensitively so
     * a {{City}} token binds to a `city` column.
     *
     * ⚠️ $escapeHtml MUST be true whenever $text is an HTML document. The values
     * come from an audience row — i.e. from customer-controlled columns such as
     * name and address. Substituting them raw into the assembled email body is a
     * stored-XSS sink: the poisoned body is persisted on the notification and
     * later rendered in the admin panel, which would execute it in the admin's
     * origin. Escape the VALUES, never the template — the template is authored
     * HTML and must keep working.
     *
     * @param array<string,mixed> $row
     */
    public static function render(string $text, array $row, bool $escapeHtml = false): string
    {
        $lookup = [];
        foreach ($row as $key => $value) {
            $lookup[strtolower((string) $key)] = $value;
        }

        return (string) preg_replace_callback(self::PATTERN, function (array $m) use ($lookup, $escapeHtml) {
            $key = strtolower($m[1]);
            $value = $lookup[$key] ?? '';
            if (is_array($value) || is_object($value)) {
                return '';
            }
            $value = (string) $value;

            return $escapeHtml ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $value;
        }, $text);
    }

    /**
     * For the admin UI: which of a template's variables are satisfied by the
     * audience's columns.
     *
     * @param string[] $templateVars
     * @param string[] $audienceColumns
     * @return array<int, array{name:string, mapped:bool}>
     */
    public static function mapping(array $templateVars, array $audienceColumns): array
    {
        $columns = array_map('strtolower', $audienceColumns);

        return array_map(fn (string $var) => [
            'name'   => $var,
            'mapped' => in_array(strtolower($var), $columns, true),
        ], array_values($templateVars));
    }
}
