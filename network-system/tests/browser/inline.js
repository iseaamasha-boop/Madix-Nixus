#!/usr/bin/env node
/*
 * البند 79 في المتصفح: التعديل المباشر في الجدول.
 *   node tests/browser/inline.js https://madix.test [--theme=dark]
 *
 * سعر باقة الاشتراك: النقر يحوّل الخلية لحقل، Enter يحفظ بلا إعادة تحميل ويبقى بعد التحديث، Escape يلغي،
 * والقيمة الخاطئة تعود كما كانت مع رسالة خطأ. على الحاسوب والهاتف، بلا أخطاء JavaScript.
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
    await page.goto(BASE + '/' + TENANT + '/plans');
    console.log(w + 'px');
    const cell = page.locator('[data-edit][data-field="price"]').first();
    if (!(await cell.count())) { console.log('  - no editable price, skipped'); await ctx.close(); continue; }
    const old = await cell.getAttribute('data-value');
    let navigated = false;
    page.on('framenavigated', () => { navigated = true; });
    // السكربت محمّل مسبقاً بعد اكتمال الصفحة: أول نقرة تفتح الحقل فوراً
    await page.waitForFunction(() => !!window.SNInline, null, { timeout: 5000 }).catch(() => null);
    await cell.click();
    const input = cell.locator('input');
    ok(await input.count() === 1, 'click turns the cell into a field');
    await input.fill('');
    await input.type('77.5');
    await input.press('Enter');
    await page.waitForFunction(() => { const c = document.querySelector('[data-edit][data-field="price"]'); return c && c.getAttribute('data-value') === '77.50' && !c.classList.contains('is-saving'); }, null, { timeout: 6000 }).catch(() => null);
    ok((await cell.getAttribute('data-value')) === '77.50' && /77/.test(await cell.textContent()), 'saved and shown');
    ok(!navigated, 'no page reload');
    await page.reload();
    ok((await page.locator('[data-edit][data-field="price"]').first().getAttribute('data-value')) === '77.50', 'kept after reload');
    const c2 = page.locator('[data-edit][data-field="price"]').first();
    await c2.click();
    await c2.locator('input').fill('999');
    await c2.locator('input').press('Escape');
    ok((await c2.getAttribute('data-value')) === '77.50' && await c2.locator('input').count() === 0, 'Escape cancels');
    await c2.click();
    await c2.locator('input').fill('abc');
    await c2.locator('input').press('Enter');
    await page.waitForSelector('.toast-error', { timeout: 6000 }).catch(() => null);
    ok(await page.locator('.toast-error').count() >= 1, 'bad value: error message');
    ok((await c2.getAttribute('data-value')) === '77.50' && /77/.test(await c2.textContent()), 'bad value: previous value back');
    // إعادة السعر الأصلي
    await c2.click();
    await c2.locator('input').fill(old);
    await c2.locator('input').press('Enter');
    await page.waitForTimeout(800);
    ok(errors.length === 0, 'no JavaScript errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
    await ctx.close();
  }
  await browser.close();
  console.log(failed ? `\n${failed} failed` : '\nall passed');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
