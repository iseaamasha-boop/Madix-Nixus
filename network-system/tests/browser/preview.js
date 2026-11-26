#!/usr/bin/env node
/*
 * البند 73 في المتصفح: المعاينة السريعة.
 *   node tests/browser/preview.js https://madix.test [--theme=dark]
 *
 * المرور بالفأرة على رابط مشترك وجهاز MikroTik: سكربت المعاينة يُحمَّل عند أول مرور فقط، البطاقة تظهر داخل الشاشة
 * بالاسم والتفاصيل، وتختفي عند الابتعاد وبـ Escape. اللمس لا يُظهر بطاقة. لا أخطاء JavaScript.
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
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'ar', viewport: { width: 1280, height: 800 }, colorScheme: THEME === 'light' ? 'light' : 'dark' });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e.message || e)));
  let loads = 0;
  page.on('request', (r) => { if (/\/preview\.js/.test(r.url())) { loads++; } });
  await page.goto(BASE + '/' + TENANT + '/login');
  await page.fill('input[name=username]', USER);
  await page.fill('input[name=password]', PASS);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  // حركة الظهور (.mx) تحرك الجدول بضع بكسلات أول 200ms: المرور يكون على الرابط بعد استقراره في مكانه
  const settle = () => page.waitForFunction(() => !document.getAnimations().some((a) => /^mx-/.test(a.animationName || '') && a.playState === 'running'), null, { timeout: 5000 }).catch(() => null);
  const hoverOn = async (loc) => { await settle(); const b = await loc.boundingBox(); await page.mouse.move(b.x + b.width / 2, b.y + b.height / 2); };
  const card = () => page.evaluate(() => {
    const c = document.querySelector('.qp-card.is-on');
    if (!c || !c.querySelector('.qp-title')) { return null; }
    const r = c.getBoundingClientRect();
    return { title: c.querySelector('.qp-title').textContent.trim(), rows: c.querySelectorAll('dt').length, inside: r.left >= 0 && r.right <= innerWidth && r.top >= 0 && r.bottom <= innerHeight };
  });

  await page.goto(BASE + '/' + TENANT + '/subscribers');
  ok(loads === 0, 'script not loaded with the page');
  const link = page.locator('a.cell-title').nth(1);
  const name = (await link.textContent()).trim();
  await hoverOn(link);
  await page.waitForSelector('.qp-card.is-on .qp-title', { timeout: 5000 }).catch(() => null);
  const c = await card();
  ok(c && c.title === name && c.rows >= 1, 'subscriber card with its details');
  ok(c && c.inside, 'card inside the screen');
  ok(loads === 1, 'script loaded on the first hover');
  await page.mouse.move(5, 790);
  await page.waitForTimeout(400);
  ok(!(await card()), 'hidden when the mouse leaves');
  await hoverOn(link);
  await page.waitForSelector('.qp-card.is-on .qp-title', { timeout: 5000 }).catch(() => null);
  await page.keyboard.press('Escape');
  ok(!(await card()), 'Escape hides it');

  // لوحة المفاتيح: التركيز على الرابط بـ Tab يُظهر البطاقة
  await page.mouse.move(5, 790);
  await page.keyboard.press('Shift');
  await page.locator('a.cell-title').nth(3).focus();
  await page.waitForSelector('.qp-card.is-on .qp-title', { timeout: 5000 }).catch(() => null);
  ok(!!(await card()), 'keyboard focus shows the card');
  await page.keyboard.press('Escape');

  await page.goto(BASE + '/' + TENANT + '/routers');
  const rl = page.locator('table a[href*="/routers/"]').first();
  await hoverOn(rl);
  await page.waitForSelector('.qp-card.is-on .qp-title', { timeout: 5000 }).catch(() => null);
  const r = await card();
  ok(r && r.rows >= 1, 'MikroTik device card');

  const touch = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'ar', viewport: { width: 360, height: 740 }, hasTouch: true, isMobile: true, storageState: await ctx.storageState() });
  const tp = await touch.newPage();
  await tp.goto(BASE + '/' + TENANT + '/subscribers');
  await tp.evaluate(() => { const a = document.querySelector('a.cell-title'); a.addEventListener('click', (e) => e.preventDefault(), { once: true }); });
  await tp.tap('a.cell-title');
  await tp.waitForTimeout(900);
  ok(await tp.evaluate(() => !document.querySelector('.qp-card.is-on')), 'no card on touch');
  await touch.close();

  ok(errors.length === 0, 'no JavaScript errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await browser.close();
  console.log(failed ? `\n${failed} failed` : '\nall passed');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
