#!/usr/bin/env node
/*
 * البند 76 في المتصفح: رؤوس الجداول الطويلة تبقى ظاهرة أثناء التمرير.
 *   node tests/browser/tables.js https://madix.test [--theme=dark]
 *
 * المشتركون والكروت وأجهزة MikroTik على الحاسوب والهاتف: سكربت الجداول يُحمَّل بعد الصفحة فقط، النسخة تظهر تحت
 * الشريط العلوي عند التمرير بعرض الأعمدة نفسه وتتبع التمرير الأفقي، وتختفي عند العودة للأعلى. لا أخطاء JavaScript.
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
    console.log(w + 'px');
    for (const path of ['/subscribers', '/cards', '/routers']) {
      await page.goto(BASE + '/' + TENANT + path, { waitUntil: 'load' });
      const long = await page.evaluate(() => !!document.querySelector('.table-wrap > table.table > tbody > tr:nth-child(20)'));
      if (!long) { console.log('  - ' + path + ': short table, skipped'); continue; }
      await page.waitForSelector('.table-float', { state: 'attached', timeout: 5000 }).catch(() => null);
      ok(await page.locator('.table-float.is-on').count() === 0, path + ': hidden at the top');
      await page.evaluate(() => window.scrollTo(0, 900));
      await page.waitForTimeout(400);
      const r = await page.evaluate(() => {
        const f = document.querySelector('.table-float');
        if (!f) { return null; }
        const tb = document.querySelector('.topbar').getBoundingClientRect();
        const a = document.querySelectorAll('.table-wrap > table.table thead th'), b = f.querySelectorAll('th');
        let same = a.length === b.length;
        for (let i = 0; i < a.length && same; i++) {
          const x = a[i].getBoundingClientRect(), y = b[i].getBoundingClientRect();
          same = Math.abs(x.width - y.width) < 1.5 && Math.abs(x.left - y.left) < 1.5;
        }
        return { on: f.classList.contains('is-on'), top: Math.abs(f.getBoundingClientRect().top - tb.bottom) < 1.5, same };
      });
      ok(r && r.on && r.top, path + ': header shown under the top bar while scrolling');
      ok(r && r.same, path + ': columns line up');
      await page.evaluate(() => { const wr = document.querySelector('.table-wrap'); wr.scrollLeft = document.documentElement.dir === 'rtl' ? -120 : 120; });
      await page.waitForTimeout(250);
      const x = await page.evaluate(() => {
        const a = document.querySelectorAll('.table-wrap > table.table thead th'), b = document.querySelectorAll('.table-float th');
        const i = Math.min(2, a.length - 1);
        return Math.abs(a[i].getBoundingClientRect().left - b[i].getBoundingClientRect().left) < 1.5;
      });
      ok(x, path + ': follows horizontal scrolling');
      await page.evaluate(() => window.scrollTo(0, 0));
      await page.waitForTimeout(300);
      ok(await page.locator('.table-float.is-on').count() === 0, path + ': hidden again at the top');
    }
    ok(errors.length === 0, 'no JavaScript errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
    await ctx.close();
  }
  await browser.close();
  console.log(failed ? `\n${failed} failed` : '\nall passed');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
