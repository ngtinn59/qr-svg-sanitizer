# qr-svg-sanitizer

Validate QR-code SVG that came from a third-party generator, and give it a
`viewBox` so CSS can actually resize it.

Zero dependencies. One file. PHP 8.0+.

```php
use Ngtinn\QrSvg\QrSvg;

$svg = QrSvg::clean($responseBody);

if (null === $svg) {
    // Not a QR code. Do not render it.
}
```

---

## Why this exists

If you fetch QR codes from a generator service and store the SVG, you have
inherited two problems that both fail quietly.

### Problem 1: the SVG is remote input, and the obvious filter has a hole

You are taking a response from someone else's server and putting it in your
page unescaped. So you filter it. The filter almost everyone writes first is a
blocklist of event handlers:

```php
if (preg_match('~\son[a-z]+\s*=~i', $svg)) {
    return '';   // looks safe
}
```

That regex requires whitespace before the attribute name. This payload has
none, and executes anyway:

```html
<svg/onload=alert(1)>
```

`/` is a legal attribute separator to an HTML tokeniser. The HTML Standard
specifies the recovery: emit an `unexpected-solidus-in-tag` parse error, then
reconsume in the *before attribute name* state — so `onload` is read as an
attribute and fires. Verified executing in Chromium; the behaviour is
specified, not a browser quirk. The filter above reports the input clean.

You can keep patching the regex, but you are now maintaining a list of every
attribute that might ever execute something, against a parser whose job is to
recover from malformed input. This library inverts it: an allowlist of the six
elements and twenty-two attributes a QR code actually consists of. Anything
else and the whole blob is rejected — not stripped, rejected.

The test suite asserts the bypass on both sides: that the naive blocklist misses
it, and that `sanitize()` catches it.

### Problem 2: no `viewBox` means CSS crops instead of scaling

Generators commonly emit:

```html
<svg width="300" height="300"> … </svg>
```

There is no `viewBox`, so there is no user coordinate system to map onto the
viewport. `width: 86px` in your CSS then shrinks the *viewport* while the
drawing keeps its original units — so the code is **cropped**, not scaled.

It looks correct at native size and silently stops decoding everywhere else. If
your QR code renders as a clean square that no phone will read, this is usually
why.

`addViewBox()` computes one, and includes the quiet zone while it is there.

---

## The quiet zone

ISO/IEC 18004 requires a light margin of **4 modules** on every side. Scanners
use it to locate the symbol edge. Without it, decode rates fall off sharply as
soon as the code sits on a coloured or textured background — which, on a real
web page, it usually does.

`addViewBox()` reads the module size off the first stroke of the pattern
(`M 0,0 l 5,0 0,5 -5,0 z` means one module is 5 units) rather than assuming a
constant, because generators size modules from the requested pixel size and the
symbol version. It then widens the viewBox by `4 × module` on each side.

It only does this when the numbers describe a real symbol: the side must be a
whole number of modules, and that count must be one of the 40 sizes in the
specification — **21 to 177, in steps of 4**. If the path cannot be parsed, or
the arithmetic does not land on a valid version, the input is returned
untouched. Widening by a wrong amount would distort a code that currently
works, and that is worse than doing nothing.

It is idempotent, so it is safe to run over already-processed data.

---

## Sizing: how small is too small?

`moduleCount()` tells you how dense the symbol is, which is what you need to
pick a render box:

```php
$modules = QrSvg::moduleCount($svg);       // e.g. 41
$pxPerModule = 86 / ($modules + 8);        // + quiet zone, both sides
```

Measured against phone cameras at 1x pixel density, roughly **2.4 px per
module** decodes reliably and **1.9 px per module** does not. A 118-character
URL produces a 41-module symbol; at 49 modules including the quiet zone, an
86 px box gives 1.75 px per module and fails, while 120 px gives 2.4 and works.

Your mileage will vary with contrast and camera, but the failure is a cliff
rather than a slope, so measure rather than guess.

---

## API

| Method | Returns |
| --- | --- |
| `QrSvg::sanitize(string $svg)` | The input unchanged if it is a QR code and nothing else, otherwise `null` |
| `QrSvg::addViewBox(string $svg)` | The SVG with a quiet-zone `viewBox` added; unchanged if it already has one, or if the geometry is not a valid symbol |
| `QrSvg::clean(string $svg)` | `sanitize()` then `addViewBox()`, or `null` |
| `QrSvg::moduleCount(string $svg)` | Modules across the symbol, or `null` if unreadable |

`sanitize()` is all-or-nothing on purpose. It does not remove the bad parts and
return the rest: an SVG that needed stripping was not a QR code, and a partially
cleaned payload is the kind of thing that turns out to still be executable a
year later.

### What passes

Elements `svg`, `g`, `path`, `rect`, `title`, `desc`. Attributes limited to
geometry, presentation and accessibility — `d`, `width`, `viewBox`, `fill`,
`transform`, `role`, `aria-label` and similar. `style` is allowed but restricted
to colouring properties with values drawn from the character set `rgb()` and hex
notation need; blocking `style` outright would reject genuine generator output.

Comments and the XML prolog are stripped before inspection, because the tag
scanner matches `<` followed by a letter and would not look inside them.

---

## Install

Composer:

```
composer require ngtinn/qr-svg-sanitizer
```

Or drop `src/QrSvg.php` in and `require` it. There is nothing else.

---

## Tests

```
php tests/run.php
```

No PHPUnit. This library gets dropped into legacy codebases — often a WordPress
install with no Composer at all — so the tests run on a bare PHP binary.

---

## Using it in WordPress

```php
$svg = QrSvg::clean( wp_remote_retrieve_body( $response ) );

if ( null !== $svg ) {
    update_post_meta( $post_id, '_qr_svg', $svg );
}
```

Fetch and store once; never call the generator during a page view. Two reasons,
both measured on a live site: the service timed out on 2 of 6 consecutive calls,
and calling it from the browser hands a third party the URL of the page each
visitor is reading.

Render the stored copy with `echo $svg` — it has already been validated, so
`wp_kses_post()` would only strip the SVG back out.

---

## Licence

MIT.
