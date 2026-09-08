import { test, expect } from '@playwright/test';

/**
 * Cross-engine verification of the bypass the README describes.
 *
 * The README's argument rests on one claim about browsers: in `<svg/onload=…>`
 * the solidus is consumed as an attribute separator, so `onload` is read as an
 * attribute and fires. The HTML Standard specifies exactly that recovery —
 * emit `unexpected-solidus-in-tag`, then reconsume in the *before attribute
 * name* state — but a specified behaviour is still an implementation claim
 * until each engine is checked. This file checks all three.
 *
 * Deliberately no PHP here. These tests assert what the *browser* does, which
 * is the premise of the library rather than the library itself; `tests/run.php`
 * asserts what `sanitize()` does about it. Keeping them apart means the PHP
 * suite stays runnable with nothing but `php tests/run.php`.
 */

/** The payload, exactly as the README prints it. */
const PAYLOAD = '<svg/onload="window.__fired = true"><path d="M 0,0 l 4,0 0,4 -4,0 z"/></svg>';

/** The naive filter the README opens with. */
const NAIVE_BLOCKLIST = /\son[a-z]+\s*=/i;

const page_ = (body) => `<!doctype html><meta charset="utf-8"><title>solidus</title>${body}`;

test.describe('unexpected-solidus-in-tag', () => {
  test('the handler executes', async ({ page }) => {
    await page.setContent(page_(PAYLOAD));

    // The load event on an <svg> root is dispatched during parsing, so by the
    // time setContent resolves it has already run or it never will.
    await expect.poll(() => page.evaluate(() => window.__fired === true)).toBe(true);
  });

  test('the tokeniser reads onload as an attribute', async ({ page }) => {
    await page.setContent(page_(PAYLOAD));

    const attributes = await page.evaluate(() =>
      Array.from(document.querySelector('svg').attributes, (a) => a.name),
    );

    expect(attributes).toContain('onload');
  });

  test('the element is a real SVG root, not an unknown element', async ({ page }) => {
    await page.setContent(page_(PAYLOAD));

    // If an engine bailed out of foreign-content parsing the node would be an
    // HTMLUnknownElement and the whole payload would be inert for other reasons.
    const namespace = await page.evaluate(() => document.querySelector('svg').namespaceURI);

    expect(namespace).toBe('http://www.w3.org/2000/svg');
  });

  test('a whitespace-separated handler executes too, as the baseline', async ({ page }) => {
    await page.setContent(page_(PAYLOAD.replace('<svg/', '<svg ')));

    // Guards against a green run that only means the engine ignores svg onload
    // entirely: the ordinary form must fire in the same engine.
    await expect.poll(() => page.evaluate(() => window.__fired === true)).toBe(true);
  });

  test('the naive blocklist reports the payload clean', () => {
    // Premise check, not a check on this library: no whitespace precedes
    // `onload`, so the regex never matches while the browser still executes it.
    expect(NAIVE_BLOCKLIST.test(PAYLOAD)).toBe(false);
    expect(NAIVE_BLOCKLIST.test(PAYLOAD.replace('<svg/', '<svg '))).toBe(true);
  });
});
