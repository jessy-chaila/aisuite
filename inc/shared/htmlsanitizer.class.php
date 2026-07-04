<?php

namespace GlpiPlugin\Aisuite\Shared;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Shared HTML sanitizer for AI-generated HTML fragments.
 *
 * Technical: added as part of the security audit. Both AI Level 1 Assistant
 * (solution_html, a JSON field the model is asked to return) and AI Smart
 * Check (the full analysis HTML block) insert AI-generated HTML directly
 * into ITILFollowup content / stored analysis without prior sanitization.
 * If a prompt-injection attempt in the ticket content succeeds in making
 * the model emit `<script>`, event-handler attributes, `javascript:` links,
 * etc., this previously became a stored-XSS payload. Both call sites now go
 * through one of the two modes below before the HTML is persisted/displayed:
 *
 * - sanitizeStrict(): for AI Level 1 Assistant's solution_html, which the
 *   prompt asks to keep to simple prose formatting (paragraphs, lists,
 *   emphasis, links).
 * - sanitizeRich(): for AI Smart Check's analysis HTML, whose mandatory
 *   prompt template requires Bootstrap badges, a checkbox-per-step list and
 *   an inline `style` attribute on one element — a strict allowlist would
 *   break the intended UI, so this mode instead removes only genuinely
 *   dangerous constructs (scripts, event handlers, non-http(s) URLs,
 *   dangerous CSS) and unwraps anything else unexpected.
 *
 * Implementation note: uses DOMDocument rather than regex, since regex-based
 * HTML filtering is well known to be bypassable.
 */
class HtmlSanitizer {

    /** Tags removed entirely, including their content: never safe to keep. */
    private const HARD_BLOCKED_TAGS = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'svg', 'math',
        'link', 'meta', 'base', 'textarea', 'select', 'option', 'applet',
        'audio', 'video', 'source', 'track', 'frame', 'frameset', 'noscript',
        'img', 'button',
    ];

    private const STRICT_ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ol', 'ul', 'li',
        'a', 'code', 'pre', 'blockquote', 'span', 'h3', 'h4', 'h5', 'h6',
    ];

    private const RICH_ALLOWED_TAGS = [
        'div', 'p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ol', 'ul', 'li',
        'a', 'code', 'pre', 'blockquote', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'label', 'input', 'small',
    ];

    private const STRICT_ALLOWED_ATTRS = [
        'a' => ['href'],
    ];

    private const RICH_ALLOWED_ATTRS = [
        'a'     => ['href'],
        'div'   => ['class'],
        'span'  => ['class'],
        'label' => ['class', 'style'],
        'input' => ['class', 'type', 'checked'],
        'i'     => ['class'],
        'ul'    => ['class'],
        'li'    => ['class'],
        'h1'    => ['class'], 'h2' => ['class'], 'h3' => ['class'],
        'h4'    => ['class'], 'h5' => ['class'], 'h6' => ['class'],
    ];

    public static function sanitizeStrict(string $html): string {
        return self::sanitize($html, self::STRICT_ALLOWED_TAGS, self::STRICT_ALLOWED_ATTRS);
    }

    public static function sanitizeRich(string $html): string {
        return self::sanitize($html, self::RICH_ALLOWED_TAGS, self::RICH_ALLOWED_ATTRS);
    }

    private static function sanitize(string $html, array $allowedTags, array $allowedAttrs): string {
        if (trim($html) === '') {
            return '';
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');

        $previous = libxml_use_internal_errors(true);
        // Wrap in a root element + force UTF-8 interpretation (DOMDocument
        // defaults to Latin-1 for fragments without an explicit meta charset).
        $doc->loadHTML(
            '<?xml encoding="utf-8" ?><div id="__aisuite_root__">' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('__aisuite_root__');
        if ($root === null) {
            return '';
        }

        self::sanitizeNode($doc, $root, $allowedTags, $allowedAttrs);

        $result = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $result .= $doc->saveHTML($child);
        }

        return $result;
    }

    private static function sanitizeNode(\DOMDocument $doc, \DOMNode $node, array $allowedTags, array $allowedAttrs): void {
        // Snapshot children first: we mutate the tree (remove/replace nodes)
        // while iterating, so a live NodeList would skip/misalign entries.
        $children = iterator_to_array($node->childNodes);

        foreach ($children as $child) {
            if ($child instanceof \DOMComment) {
                $node->removeChild($child);
                continue;
            }

            if (!($child instanceof \DOMElement)) {
                // Text nodes and similar: keep as-is.
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::HARD_BLOCKED_TAGS, true)) {
                $node->removeChild($child);
                continue;
            }

            if (!in_array($tag, $allowedTags, true)) {
                // Unknown/unexpected tag: not dangerous by itself, but not
                // part of the expected template either. Unwrap it (keep its
                // sanitized children, drop the wrapping element) rather than
                // dropping the content outright.
                self::sanitizeNode($doc, $child, $allowedTags, $allowedAttrs);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            self::sanitizeAttributes($child, $tag, $allowedAttrs);
            self::sanitizeNode($doc, $child, $allowedTags, $allowedAttrs);
        }
    }

    private static function sanitizeAttributes(\DOMElement $el, string $tag, array $allowedAttrs): void {
        $allowed = $allowedAttrs[$tag] ?? [];

        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);

            // Event handlers (onclick, onerror, onload, ...) are never
            // allowed, on any element, regardless of the tag's allowlist.
            if (str_starts_with($name, 'on') || !in_array($name, $allowed, true)) {
                $el->removeAttribute($attr->name);
                continue;
            }

            if ($name === 'href') {
                $safeHref = self::sanitizeUrl($attr->value);
                if ($safeHref === null) {
                    $el->removeAttribute($attr->name);
                } else {
                    $el->setAttribute('href', $safeHref);
                }
                continue;
            }

            if ($name === 'style') {
                $safeStyle = self::sanitizeStyle($attr->value);
                if ($safeStyle === '') {
                    $el->removeAttribute('style');
                } else {
                    $el->setAttribute('style', $safeStyle);
                }
                continue;
            }

            if ($tag === 'input' && $name === 'type') {
                // The only <input> the template ever needs is a plain,
                // client-side, non-submitting checkbox.
                $el->setAttribute('type', 'checkbox');
                continue;
            }

            if ($tag === 'input' && $name === 'checked') {
                // Boolean presence attribute reflecting the user's own
                // "step done" state (persisted via update_content). Not an
                // injection vector - normalize its value regardless of what
                // was submitted.
                $el->setAttribute('checked', 'checked');
                continue;
            }
        }

        // Links: always force safe target/rel ourselves, never trust the
        // model's own target="..."/rel="..." (irrelevant since those aren't
        // in the attribute allowlist above, but kept explicit here).
        if ($tag === 'a' && $el->hasAttribute('href')) {
            $el->setAttribute('target', '_blank');
            $el->setAttribute('rel', 'noopener noreferrer');
        }

        if ($tag === 'input') {
            // Plain UI-only checkbox: no <form> ever survives sanitization
            // (HARD_BLOCKED_TAGS), so this can never submit anything. It
            // must stay enabled - it is the "mark step as done" control the
            // ticket UI relies on (see inc/smartcheck/ticket.class.php JS).
            $el->setAttribute('type', 'checkbox');
        }
    }

    /**
     * Allows only http(s)/mailto absolute URLs. Rejects javascript:, data:,
     * vbscript: and any other scheme, as well as protocol-relative URLs
     * (//evil.example) which browsers resolve using the page's own scheme.
     */
    private static function sanitizeUrl(string $url): ?string {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '//')) {
            return null;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme === null) {
            // No scheme at all (relative path or fragment): safe to keep.
            return $url;
        }

        return in_array(strtolower($scheme), ['http', 'https', 'mailto'], true) ? $url : null;
    }

    /**
     * Denylist-based CSS filter for the one legitimate inline `style` use in
     * AI Smart Check's mandated template (cursor/width on a <label>). Strips
     * the whole attribute if it contains any construct that could execute
     * code or exfiltrate data via CSS.
     */
    private static function sanitizeStyle(string $style): string {
        $lower = strtolower($style);
        $dangerous = ['javascript', 'expression', 'behavior', 'behaviour', 'import', 'url(', '</style'];

        foreach ($dangerous as $needle) {
            if (str_contains($lower, $needle)) {
                return '';
            }
        }

        return $style;
    }
}
