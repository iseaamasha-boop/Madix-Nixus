#!/usr/bin/env node
/*
 * البند 80 في المتصفح: السحب والإفلات لإعادة الترتيب.
 *   node tests/browser/sortable.js https://madix.test [--theme=dark]
 *
 * باقات الاشتراك: سحب الصف الأول بالمقبض إلى المكان الثالث يغيّر الترتيب فوراً ويبقى بعد التحديث،
 * Alt مع السهم يحرّك الصف بلوحة المفاتيح، واللمس يعمل على الهاتف. يُعاد الترتيب الأصلي في النهاية. بلا أخطاء JavaScript.
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
  const order = (page) => page.$$eval('tbody[data-sortable] > tr[data-id]', (r) => r.map((x) => x.getAttribute('data-id')));
  for (const [w, h, touch] of [[1280, 800, false], [360, 740, true]]) {
    const ctx = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'ar', viewport: { width: w, height: h }, hasTouch: touch, colorScheme: THEME === 'light' ? 'light' : 'dark' });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e.message || e)));
    await page.goto(BASE + '/' + TENANT + '/login');
    await page.fill('input[name=username]', USER);
    await page.fill('input[name=password]', PASS);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    await page.goto(BASE + '/' + TENANT + '/plans', { waitUntil: 'load' });
    console.log(w + 'px');
    const start = await order(page);
    if (start.length < 3) { console.log('  - fewer than 3 plans, skipped'); await ctx.close(); continue; }
    await page.waitForFunction(() => !!window.SNSortable, null, { timeout: 5000 }).catch(() => null);
    ok(await page.evaluate(() => !!window.SNSortable), 'drag script loaded after the page');
    const handles = page.locator('tbody[data-sortable] .drag-handle');
    const a = await handles.nth(0).boundingBox(), c = await page.locator('tbody[data-sortable] > tr').nth(2).boundingBox();
    const x = a.x + a.width / 2, y0 = a.y + a.height / 2, y1 = c.y + c.height * 0.8;
    // سحب بالفأرة الحقيقية على الحاسوب، وبأحداث لمس (Pointer Events) على الهاتف
    if (!touch) {
      await page.mouse.move(x, y0);
      await page.mouse.down();
      for (let i = 1; i <= 12; i++) { await page.mouse.move(x, y0 + (y1 - y0) * i / 12); }
      await page.mouse.up();
    } else await page.evaluate(([x, y0, y1, type]) => {
      const el = document.elementFromPoint(x, y0).closest('.drag-handle');
      const ev = (t, y) => el.dispatchEvent(new PointerEvent(t, { bubbles: true, pointerId: 7, pointerType: type, clientX: x, clientY: y, button: 0, isPrimary: true }));
      ev('pointerdown', y0);
      for (let i = 1; i <= 12; i++) { ev('pointermove', y0 + (y1 - y0) * i / 12); }
      ev('pointerup', y1);
    }, [x, y0, y1, touch ? 'touch' : 'mouse']);
    await page.waitForTimeout(250);
    const after = await order(page);
    ok(after[2] === start[0] && after[0] === start[1], 'first row dragged to the third place (' + (touch ? 'touch' : 'mouse') + ')');
    await page.waitForTimeout(900);
    await page.reload({ waitUntil: 'load' });
    ok((await order(page)).join(',') === after.join(','), 'new order kept after reload');
    await page.waitForFunction(() => !!window.SNSortable, null, { timeout: 5000 }).catch(() => null);
    // لوحة المفاتيح: الصف الثالث (الأول سابقاً) يعود للأعلى بخطوتين
    const h3 = page.locator('tbody[data-sortable] > tr').nth(2).locator('.drag-handle');
    await h3.focus();
    await page.keyboard.press('Alt+ArrowUp');
    await page.keyboard.press('Alt+ArrowUp');
    ok((await order(page)).join(',') === start.join(','), 'Alt + arrows move the row');
    await page.waitForTimeout(1200);
    await page.reload({ waitUntil: 'load' });
    ok((await order(page)).join(',') === start.join(','), 'original order saved back');
    ok(errors.length === 0, 'no JavaScript errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
    await ctx.close();
  }
  await browser.close();
  console.log(failed ? `\n${failed} failed` : '\nall passed');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
