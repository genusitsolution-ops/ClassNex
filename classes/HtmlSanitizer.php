<?php
declare(strict_types=1);

/** Whitelist HTML sanitizer for CMS pages (prevents stored XSS even from admin input). */
final class HtmlSanitizer
{
    private const TAGS = ['p', 'br', 'h2', 'h3', 'h4', 'h5', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i', 'u', 'a', 'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'img', 'hr', 'span', 'div'];
    private const ATTRS = ['a' => ['href', 'title'], 'img' => ['src', 'alt', 'width', 'height'], 'td' => ['colspan'], 'th' => ['colspan']];

    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="cnx-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $root = $doc->getElementById('cnx-root') ?? $doc->documentElement;
        self::walk($root);
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (!in_array($tag, self::TAGS, true)) {
                    if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form'], true)) {
                        $node->removeChild($child);
                        continue;
                    }
                    self::walk($child);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $name = strtolower($attr->name);
                    $ok = in_array($name, self::ATTRS[$tag] ?? [], true);
                    if ($ok && in_array($name, ['href', 'src'], true) && !preg_match('#^(https?://|/|mailto:|tel:|uploads/)#i', trim($attr->value))) {
                        $ok = false;
                    }
                    if (!$ok) {
                        $child->removeAttribute($attr->name);
                    }
                }
                if ($tag === 'a') {
                    $child->setAttribute('rel', 'noopener');
                }
                self::walk($child);
            } elseif ($child instanceof DOMComment) {
                $node->removeChild($child);
            }
        }
    }
}
