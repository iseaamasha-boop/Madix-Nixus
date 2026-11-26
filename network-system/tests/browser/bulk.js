#!/usr/bin/env node
/*
 * البند 77 في المتصفح: شريط التحديد العائم.
 *   node tests/browser/bulk.js https://madix.test [--theme=dark]
 *
 * صفحة الكروت على الحاسوب والهاتف: Shift مع النقر يحدد مدى، الشريط يطفو أسفل الشاشة داخل حدودها بالعدد الصحيح،
 * Escape وزر الإغلاق يلغيان التحديد، ولا أخطاء JavaScript.
 */
'use strict';

const { chromium } = require('playwright');

const argv = process.argv.slice(2);
const BASE = (argv.find((a) => /^https?:/.test(a)) || 'http://127.0.0.1:8090').replace(/\/$/, '');
const opt = Object.fromEntries(argv.filter((a) => a.startsWith('--')).map((a) => { const [k, v] = a.slice(2).split('='); return [k, v === undefined ? '1' : v]; }));
const TENANT = process.env.MNX_TENANT || 'demo';
const USER = process.env.MNX_USER || 'owner';
const PASS = process.env.MNX_PASS || 'OwnerPass123';
const THEME = opt.theme || 'light';

let failed = 0;
function ok(cond, msg) {
  console.log((cond ? '  ✔ ' : '  ✘ ') + msg);
  if (!cond) { failed++; }
}

(async () => {
  const browser = await chromium.launch({ args: ['--no-proxy-server'] });
  for (const [w, h] of [[1280, 800], [360, 740]]) {
    const ctx = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'ar', viewport: { width: w, height: h }, colorScheme: THEME === 'light' ? 'light' : 'dark' });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e.message || e)));
    await page.goto(BASE + '/' + TENANT + '/login');
    await page.fill('input[name=username]', USER);
    await page.fill('input[name=password]', PASS);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    await page.goto(BASE + '/' + TENANT + '/cards');
    console.log(w + 'px');
    const boxes = page.locator('[data-row-check]');
    if (await boxes.count() < 8) { console.log('  - not enough cards, skipped'); await ctx.close(); continue; }
    await boxes.nth(1).check();
    await boxes.nth(6).click({ modifiers: ['Shift'] });
    ok(await page.locator('[data-row-check]:checked').count() === 6, 'Shift selects the range');
    const bar = await page.evaluate(() => {
      const b = document.querySelector('.bulkbar.is-visible');
      if (!b) { return null; }
      const r = b.getBoundingClientRect();
      return { fixed: getComputedStyle(b).position === 'fixed', inside: r.left >= 0 && r.right <= innerWidth && r.bottom <= innerHeight && r.top > innerHeight / 2, count: b.querySelector('[data-selected-count]').textContent.trim() };
    });
    ok(bar && bar.fixed && bar.inside, 'bar floats at the bottom, inside the screen');
    ok(bar && bar.count === '6', 'count shown');
    await page.keyboard.press('Escape');
    ok(await page.locator('[data-row-check]:checked').count() === 0 && await page.locator('.bulkbar.is-visible').count() === 0, 'Escape clears');
    await boxes.nth(2).check();
    await page.click('.bulkbar-close');
    ok(await page.locator('[data-row-check]:checked').count() === 0, 'close button clears');
    ok(errors.length === 0, 'no JavaScript errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
    await ctx.close();
  }
  await browser.close();
  console.log(failed ? `\n${failed} failed` : '\nall passed');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
