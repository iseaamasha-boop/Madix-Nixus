#!/usr/bin/env node
/*
 * البندان 69 و72 في المتصفح: لوحة الأوامر والبحث العربي.
 *   node tests/browser/palette.js https://madix.test [--theme=dark]
 *
 * على الحاسوب (Ctrl K) والهاتف (زر البحث): تفتح وتركّز خانة الكتابة فوراً، سكربتها يُحمَّل مرة واحدة عند أول فتح،
 * الصفحات تُطابَق بصيغ الكلمة («مشتركين» ← «المشتركون»)، «ابو» تجد «أبو» في البحث الحي، الأسهم وEnter تفتح النتيجة،
 * ولا أخطاء JavaScript.
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
    const ctx = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'ar', viewport: { width: w, height: h }, hasTouch: w < 600, colorScheme: THEME === 'light' ? 'light' : 'dark' });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e.message || e)));
    let loads = 0;
    page.on('request', (r) => { if (/\/palette\.js/.test(r.url())) { loads++; } });
    await page.goto(BASE + '/' + TENANT + '/login');
    await page.fill('input[name=username]', USER);
    await page.fill('input[name=password]', PASS);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    await page.goto(BASE + '/' + TENANT + '/subscribers');
    console.log(w + 'px');
    ok(loads === 0, 'palette script not loaded with the page');
    if (w > 600) { await page.keyboard.press('Control+k'); } else { await page.tap('.topbar-search'); }
    await page.waitForSelector('dialog.cmdk[open]', { timeout: 5000 }).catch(() => null);
    ok(await page.evaluate(() => !!document.querySelector('dialog.cmdk[open]')), 'opens');
    ok(await page.evaluate(() => document.activeElement && document.activeElement.classList.contains('cmdk-input')), 'typing box focused at once');
    await page.waitForSelector('.cmdk-item', { timeout: 5000 }).catch(() => null);
    ok(loads === 1, 'script loaded once on first open');
    await page.keyboard.type('مشتركين');
    await page.waitForTimeout(200);
    const pages = await page.$$eval('.cmdk-item .cmdk-title', (t) => t.map((x) => x.textContent));
    ok(pages.includes('المشتركون'), '«مشتركين» finds the page «المشتركون»');
    const name = await page.evaluate(() => fetch(document.querySelector('meta[name="base-url"]').content + '/subscribers/lookup?q=' + encodeURIComponent('أبو'), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }).then((r) => r.json()).then((d) => (d.items[0] || {}).label || ''));
    if (name) {
      await page.fill('.cmdk-input', 'ابو');
      await page.waitForFunction(() => Array.from(document.querySelectorAll('.cmdk-item .cmdk-title')).some((t) => /أبو/.test(t.textContent)), null, { timeout: 6000 }).catch(() => null);
      ok(await page.evaluate(() => Array.from(document.querySelectorAll('.cmdk-item .cmdk-title')).some((t) => /أبو/.test(t.textContent))), '«ابو» finds names written «أبو»');
      const target = await page.evaluate(() => { const items = Array.from(document.querySelectorAll('.cmdk-item')); const i = items.findIndex((x) => /أبو/.test(x.textContent)); return i; });
      for (let i = 0; i < target; i++) { await page.keyboard.press('ArrowDown'); }
      await Promise.all([page.waitForNavigation({ timeout: 8000 }).catch(() => null), page.keyboard.press('Enter')]);
      ok(/\/subscribers\/\d+$/.test(page.url()), 'Enter opens the result');
    }
    await page.keyboard.press(w > 600 ? 'Control+k' : 'Escape');
    if (w < 600) { await page.tap('.topbar-search'); }
    await page.waitForSelector('dialog.cmdk[open] .cmdk-group', { timeout: 5000 }).catch(() => null);
    const groups = await page.$$eval('.cmdk-group', (g) => g.map((x) => x.textContent));
    ok(groups.includes('آخر ما فُتح'), 'recently opened listed');
    await page.keyboard.press('Escape');
    ok(await page.evaluate(() => !document.querySelector('dialog.cmdk[open]')), 'Escape closes');
    ok(errors.length === 0, 'no JavaScript errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
    await ctx.close();
  }
  await browser.close();
  console.log(failed ? `\n${failed} failed` : '\nall passed');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
