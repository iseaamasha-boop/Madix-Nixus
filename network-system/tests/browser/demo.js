#!/usr/bin/env node
/*
 * البند 105 في المتصفح: «جرّب الآن» من صفحة الهبوط إلى الشبكة التجريبية بلا تسجيل.
 *   node tests/browser/demo.js https://madix.test   (الشبكة التجريبية مفتوحة من لوحة المسؤول)
 * - الزر في صفحة الهبوط يُدخل الزائر إلى لوحة التحكم، وعلامة «تجريبي» ظاهرة، والصفحات تعمل.
 * - إجراء ممنوع يظهر برسالة «غير متاح في الشبكة التجريبية.» ولا يتغير شيء.
 * - بلا أخطاء JavaScript، وبلا تمدد أفقي على الجوال.
 */
'use strict';

const { chromium } = require('playwright');

const BASE = (process.argv.slice(2).find((a) => /^https?:/.test(a)) || 'http://127.0.0.1:8090').replace(/\/$/, '');
let failed = 0;
function ok(cond, msg) {
  console.log((cond ? '  ✔ ' : '  ✘ ') + msg);
  if (!cond) { failed++; }
}

(async () => {
  const browser = await chromium.launch({ args: ['--no-proxy-server'] });
  for (const [w, h] of [[1280, 860], [390, 844]]) {
    const ctx = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'ar', viewport: { width: w, height: h } });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e.message || e)));
    await page.goto(BASE + '/', { waitUntil: 'load' });
    const link = await page.$('[data-lp-try]');
    ok(!!link, w + 'px: try now on the landing page');
    if (!link) { await ctx.close(); continue; }
    await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), link.click()]);
    const path = new URL(page.url()).pathname;
    ok(/^\/[a-z0-9-]+\/$/.test(path), w + 'px: inside the demo network dashboard (' + path + ')');
    ok(await page.isVisible('.demo-badge'), w + 'px: demo badge visible');
    const slug = path.split('/')[1];
    for (const p of ['/subscribers', '/cards', '/map', '/reports']) {
      const r = await page.goto(BASE + '/' + slug + p, { waitUntil: 'domcontentloaded' });
      ok(r && r.status() === 200, w + 'px: ' + p + ' opens');
    }
    await page.goto(BASE + '/' + slug + '/', { waitUntil: 'load' });
    // إجراء ممنوع من الواجهة نفسها: رسالة واضحة ولا شيء يتغير
    await page.evaluate((s) => SN.api('/' + s + '/employees', { method: 'POST', data: { name: 'x' } }).catch(() => null), slug);
    await page.waitForSelector('.toast', { timeout: 5000 }).catch(() => null);
    const toast = await page.evaluate(() => { const t = document.querySelector('.toast'); return t ? t.textContent : ''; });
    ok(/غير متاح في الشبكة التجريبية/.test(toast), w + 'px: blocked action shows its message');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    ok(overflow <= 1, w + 'px: no horizontal overflow (' + overflow + ')');
    ok(errors.length === 0, w + 'px: no JavaScript errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
    await ctx.close();
  }
  await browser.close();
  console.log(failed ? `\n${failed} failed` : '\nall passed');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
