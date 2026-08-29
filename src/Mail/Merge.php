<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Merge-field substitution for email templates.
 *
 * Placeholders are `{{ field_name }}`; whitespace inside the braces is ignored,
 * so `{{order_number}}` and `{{ order_number }}` are the same field. A
 * placeholder the sending code did not supply is removed rather than left
 * showing braces in somebody's inbox — but the template editor checks for
 * unknown fields when a template is saved, which is the point at which a typo
 * can still be fixed by the person who made it.
 *
 * Values are data, never markup: in an HTML template every substituted value is
 * escaped before it goes in. The template body itself is written by a staff
 * administrator and is left alone.
 *
 * `$rawFields` is the one exception, and it is deliberately narrow. A field is
 * only listed there when the application builds its markup itself — the
 * parts-outstanding digest lays its own table out, because a table is what
 * survives an email client — and the code that builds it escapes every value
 * it puts inside. Nothing a user typed ever reaches a raw field unescaped. The
 * list is per template, declared beside the template's wording, so adding to it
 * is a visible decision rather than a flag somebody passes.
 */
final class Merge
{
    private const PATTERN = '/\{\{\s*([a-z0-9_]+)\s*\}\}/i';

    /**
     * @param array<string,string> $fields
     * @param array<int,string>    $rawFields names whose values are already markup
     */
    public static function render(string $text, array $fields, bool $html = false, array $rawFields = []): string
    {
        return (string) preg_replace_callback(
            self::PATTERN,
            static function (array $match) use ($fields, $html, $rawFields): string {
                $name = strtolower($match[1]);
                $value = (string) ($fields[$name] ?? '');

                if (!$html) {
                    return $value;
                }

                if (in_array($name, $rawFields, true)) {
                    return $value;
                }

                $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                // A list field such as {{items}} arrives as one line per row; in
                // an HTML body those newlines would otherwise collapse.
                return nl2br($escaped, false);
            },
            $text
        );
    }

    /**
     * The field names a piece of template text refers to.
     *
     * @return array<int,string>
     */
    public static function placeholders(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1] ?? [])));
    }

    /**
     * Placeholders used in the text that the sending code does not supply.
     *
     * @param array<string,string> $known Field name => description
     * @return array<int,string>
     */
    public static function unknown(string $text, array $known): array
    {
        return array_values(array_diff(self::placeholders($text), array_keys($known)));
    }

    /**
     * A readable plain-text version of an HTML body, for the multipart
     * alternative. Deliberately simple: block tags become line breaks, the rest
     * are stripped.
     */
    public static function htmlToText(string $html): string
    {
        // Links keep their address. Stripping the tag and leaving "View the
        // order" would give a text-only reader an instruction with no way to
        // follow it — the one thing the text part exists to avoid.
        $text = (string) preg_replace_callback(
            '#<a\b[^>]*href=(["\'])(.*?)\1[^>]*>(.*?)</a>#is',
            static function (array $m): string {
                $href  = html_entity_decode($m[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $label = trim(strip_tags($m[3]));

                if ($label === '' || $label === $href) {
                    return $href;
                }

                // Entity-encoded angle brackets, because strip_tags() runs
                // further down and would treat a bare <http://…> as a tag and
                // delete the address this branch exists to keep.
                return $label . ' &lt;' . $href . '&gt;';
            },
            $html
        );

        // A <br> at the end of a source line would otherwise become two breaks.
        $text = preg_replace('#<br\s*/?>\s*#i', "\n", $text) ?? $text;

        // Cells within a row are separated, not broken. Without this a table —
        // which is the only layout an email client can be relied on to render,
        // so it is what the parts-outstanding digest is built from — collapses
        // in the text alternative to "ORD-2026-000728 of 4011 days open", three
        // columns run together with nothing between them.
        $text = preg_replace('#</(td|th)\s*>#i', '   ', $text) ?? $text;
        // One line per row rather than a blank line between every one.
        $text = preg_replace('#</tr\s*>#i', "\n", $text) ?? $text;

        $text = preg_replace('#</(p|div|li|h[1-6]|table)\s*>#i', "\n\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $text = preg_replace('/[ \t]+$/m', '', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
