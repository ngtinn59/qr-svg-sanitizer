<?php

declare(strict_types=1);

namespace Ngtinn\QrSvg;

/**
 * Sanitiser and viewBox normaliser for QR-code SVG that came from somewhere else.
 *
 * Two independent jobs, deliberately kept separate:
 *
 *   sanitize()    Decide whether a blob of SVG is a QR code and nothing else.
 *   addViewBox()  Give it a viewBox so CSS sizing scales it instead of cropping it.
 *
 * Both are pure string functions. No DOM extension, no network, no dependencies.
 */
final class QrSvg
{
    /**
     * Reject anything larger than this, in bytes.
     *
     * A 300x300 QR SVG from a generator service is tens of kilobytes. Something
     * an order of magnitude bigger is not a QR code, and the tag scanner below
     * is a regex loop we would rather not run over a megabyte of hostile input.
     */
    public const MAX_BYTES = 300000;

    /**
     * Quiet zone in modules, per ISO/IEC 18004.
     *
     * The specification requires a light margin of 4 modules on every side.
     * Scanners use it to find the symbol edge; without it, decode rates drop
     * sharply once the code sits against a coloured or textured background.
     */
    public const QUIET_ZONE_MODULES = 4;

    /** Elements a QR code is allowed to consist of. */
    private const ALLOWED_ELEMENTS = ['svg', 'g', 'path', 'rect', 'title', 'desc'];

    /**
     * Attributes a QR code is allowed to carry.
     *
     * This is an allowlist, not a blocklist. A blocklist of event handlers has
     * to enumerate every current and future `on*` attribute correctly; an
     * allowlist only has to enumerate the handful of attributes a QR code
     * actually uses. See the README for the bypass that motivated the switch.
     */
    private const ALLOWED_ATTRIBUTES = [
        'xmlns', 'version', 'viewbox', 'preserveaspectratio',
        'width', 'height', 'x', 'y', 'id', 'class',
        'd', 'style', 'fill', 'fill-opacity', 'fill-rule',
        'stroke', 'stroke-width', 'stroke-opacity',
        'transform', 'shape-rendering', 'role', 'aria-label',
    ];

    /** CSS properties permitted inside a `style` attribute. */
    private const ALLOWED_STYLE_PROPERTIES = [
        'fill', 'fill-opacity', 'fill-rule',
        'stroke', 'stroke-width', 'stroke-opacity',
        'shape-rendering',
    ];

    /**
     * One tag: name plus attribute section.
     *
     * The attribute section alternates quoted runs with unquoted characters so
     * that a `>` inside an attribute value does not end the match early.
     */
    private const TAG_PATTERN = '~<(/?)([a-zA-Z][a-zA-Z0-9:-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>~';

    /** One `name=value` pair, value optionally quoted. */
    private const ATTR_PATTERN = '~([a-zA-Z_:][a-zA-Z0-9_:.-]*)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)~';

    /**
     * Sanitise and normalise in one call.
     *
     * @return string|null Null when the input is rejected.
     */
    public static function clean(string $svg): ?string
    {
        $safe = self::sanitize($svg);

        return null === $safe ? null : self::addViewBox($safe);
    }

    /**
     * Return the SVG unchanged if it is a QR code and nothing else, else null.
     *
     * The contract is deliberately all-or-nothing. This does not strip the bad
     * parts out and hand back the rest: a QR code that needed stripping is not
     * a QR code, and a partially cleaned payload is exactly the kind of thing
     * that turns out to still be executable a year later.
     *
     * @return string|null Null when the input is rejected.
     */
    public static function sanitize(string $svg): ?string
    {
        $svg = trim($svg);

        if ('' === $svg || strlen($svg) > self::MAX_BYTES) {
            return null;
        }

        /*
         * Strip comments and the XML prolog before inspecting anything.
         *
         * The tag scanner below matches `<` followed by a letter, so `<!--` is
         * not a tag to it and the whole comment body would sail past unread.
         * Cutting them out first removes the hiding place entirely.
         */
        $svg = preg_replace('~<!--.*?-->~s', '', $svg);
        $svg = preg_replace('~<\?xml.*?\?>~is', '', (string) $svg);

        if (null === $svg || '' === $svg) {
            return null;
        }

        if (!preg_match('~<svg\b.*</svg>~is', $svg, $m)) {
            return null;
        }

        $svg = $m[0];

        if (!preg_match_all(self::TAG_PATTERN, $svg, $tags, PREG_SET_ORDER)) {
            return null;
        }

        foreach ($tags as $tag) {
            if (!in_array(strtolower($tag[2]), self::ALLOWED_ELEMENTS, true)) {
                return null;
            }

            /*
             * Turn `/` into whitespace before splitting attributes, which is
             * what an HTML tokeniser does.
             *
             * Without this line `<svg/onload=alert(1)>` reads as an `svg` tag
             * carrying no attributes at all, and walks straight through the
             * allowlist. This is the whole bug the README describes.
             */
            $attrSection = str_replace('/', ' ', $tag[3]);

            if (preg_match_all(self::ATTR_PATTERN, $attrSection, $attrs, PREG_SET_ORDER)) {
                foreach ($attrs as $attr) {
                    $name = strtolower($attr[1]);

                    if (!in_array($name, self::ALLOWED_ATTRIBUTES, true)) {
                        return null;
                    }

                    $value = trim($attr[2], "\"'");

                    // url() fetches an external resource; the other two execute code.
                    if (preg_match('~javascript:|url\s*\(|expression\s*\(~i', $value)) {
                        return null;
                    }

                    if ('style' === $name && !self::styleIsSafe($value)) {
                        return null;
                    }
                }
            }

            /*
             * Remove every `name=value` pair and whatever is left must be
             * whitespace. This catches a bare attribute with no `=` sign.
             * `<svg onload>` does not execute anything on its own, but nothing
             * that is genuinely a QR code has one, so it is rejected as a sign
             * the response is not what we asked for.
             */
            $leftover = preg_replace(self::ATTR_PATTERN, '', $attrSection);

            if (null === $leftover || '' !== trim($leftover)) {
                return null;
            }
        }

        /*
         * Every `<` must have belonged to a tag we just walked. Strip the tags
         * and any surviving `<` is something the scanner never looked at —
         * `< svg`, a doctype, or stray text outside title/desc.
         */
        $remainder = preg_replace(self::TAG_PATTERN, '', $svg);

        if (null === $remainder || str_contains($remainder, '<')) {
            return null;
        }

        return $svg;
    }

    /**
     * Add a viewBox that includes the ISO quiet zone, if one is missing.
     *
     * WHY THIS IS NEEDED
     *
     * An `<svg>` with width/height but no viewBox has no user coordinate system
     * to map onto the viewport. Setting `width: 86px` in CSS then resizes the
     * viewport while the drawing keeps its original units, so the code is
     * cropped rather than scaled. The visible result is a QR code that looks
     * fine at its native size and silently stops decoding everywhere else.
     *
     * Idempotent: an SVG that already declares a viewBox is returned untouched.
     */
    public static function addViewBox(string $svg): string
    {
        if ('' === $svg) {
            return '';
        }

        $svg = (string) preg_replace('~<!--.*?-->~s', '', $svg);

        if (preg_match('~<svg\b[^>]*\bviewBox\s*=~i', $svg)) {
            return $svg;
        }

        if (!preg_match('~<svg\b([^>]*)>~i', $svg, $tag)) {
            return $svg;
        }

        if (!preg_match('~\bwidth\s*=\s*["\']?(\d+(?:\.\d+)?)~i', $tag[1], $w)) {
            return $svg;
        }

        $side = (float) $w[1];

        /*
         * Read the module size off the first stroke of the pattern:
         * `M 0,0 l 5,0 0,5 -5,0 z` means one module is 5 units.
         *
         * Do not hard-code that 5. Generators pick a module size from the
         * requested pixel size and the symbol version, so a longer URL — more
         * modules in the same box — produces a different one.
         */
        if (!preg_match('~<path\b[^>]*\bd\s*=\s*["\']\s*M\s*[\d.]+\s*,\s*[\d.]+\s+l\s*([\d.]+)\s*,~i', $svg, $m)) {
            return $svg;
        }

        $module = (float) $m[1];

        if ($module <= 0.0 || $side <= 0.0) {
            return $svg;
        }

        $modules = $side / $module;

        /*
         * Only widen when the numbers describe a real QR symbol: the side must
         * be a whole number of modules, and that count must be one of the 40
         * sizes in ISO/IEC 18004 — 21 to 177 in steps of 4.
         *
         * Anything else means we misread the path, and returning the input
         * unchanged is better than widening by a wrong amount and distorting a
         * code that currently works.
         */
        if (abs($modules - round($modules)) > 0.001) {
            return $svg;
        }

        $modules = (int) round($modules);

        if ($modules < 21 || $modules > 177 || 1 !== $modules % 4) {
            return $svg;
        }

        $margin = self::QUIET_ZONE_MODULES * $module;
        $outer  = $side + $margin * 2;

        $viewBox = sprintf(' viewBox="%s %s %s %s"', -$margin, -$margin, $outer, $outer);

        return (string) preg_replace('~<svg\b~i', '<svg' . $viewBox, $svg, 1);
    }

    /**
     * How many modules across the symbol is, or null if it cannot be read.
     *
     * Useful for deciding a render size: a 41-module symbol inside an 86px box
     * leaves under 1.9 device pixels per module, which is below what most phone
     * cameras decode at 1x pixel density.
     */
    public static function moduleCount(string $svg): ?int
    {
        if (!preg_match('~<svg\b([^>]*)>~i', $svg, $tag)) {
            return null;
        }

        if (!preg_match('~\bwidth\s*=\s*["\']?(\d+(?:\.\d+)?)~i', $tag[1], $w)) {
            return null;
        }

        if (!preg_match('~<path\b[^>]*\bd\s*=\s*["\']\s*M\s*[\d.]+\s*,\s*[\d.]+\s+l\s*([\d.]+)\s*,~i', $svg, $m)) {
            return null;
        }

        $side   = (float) $w[1];
        $module = (float) $m[1];

        if ($module <= 0.0 || $side <= 0.0) {
            return null;
        }

        $modules = $side / $module;

        if (abs($modules - round($modules)) > 0.001) {
            return null;
        }

        $modules = (int) round($modules);

        if ($modules < 21 || $modules > 177 || 1 !== $modules % 4) {
            return null;
        }

        return $modules;
    }

    /**
     * Is a `style` attribute value limited to colouring the code?
     *
     * Blocking `style` outright would reject genuine output: generators use it
     * for `fill:rgb(255, 255, 255); fill-opacity:1` on the background rect and
     * `fill:rgb(0, 0, 0)` on the pattern. So the property names are allowlisted
     * and the values restricted to the character set `rgb()` and hex need.
     */
    private static function styleIsSafe(string $style): bool
    {
        $style = trim($style);

        if ('' === $style) {
            return true;
        }

        if (strlen($style) > 200) {
            return false;
        }

        $properties = implode('|', self::ALLOWED_STYLE_PROPERTIES);

        foreach (explode(';', $style) as $declaration) {
            $declaration = trim($declaration);

            if ('' === $declaration) {
                continue;
            }

            if (!preg_match('~^(' . $properties . ')\s*:\s*([a-zA-Z0-9#(),.%\s-]+)$~', $declaration)) {
                return false;
            }
        }

        return true;
    }
}
