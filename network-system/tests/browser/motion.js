#!/usr/bin/env node
/*
 * المرحلة 3 في المتصفح: شريط التحميل، هيكل التحميل، المنحنى النابضي، عدّاد الأرقام، تموج الضغط، وتقليل الحركة.
 *   node tests/browser/motion.js https://madix.test [--theme=dark]
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
  for (const reduced of [false, true]) {
    const ctx = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'ar', viewport: { width: 1280, height: 800 }, reducedMotion: reduced ? 'reduce' : 'no-preference', colorScheme: THEME === 'light' ? 'light' : 'dark' });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(String(e.message || e)));
    await page.goto(BASE + '/' + TENANT + '/login');
    await page.fill('input[name=username]', USER);
    await page.fill('input[name=password]', PASS);
    await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
    await page.goto(BASE + '/' + TENANT + '/subscribers', { waitUntil: 'load' });
    console.log(reduced ? 'reduced motion' : 'normal motion');
    if (!reduced) {
      // 86: الشريط يمتلئ بـ scale
      const bar = await page.evaluate(() => {
        const p = document.createElement('div'); p.className = 'nav-progress is-active'; p.style.setProperty('--p', '40%'); document.body.appendChild(p);
        const cs = getComputedStyle(p, '::before');
        const r = { scale: cs.scale, transition: cs.transitionProperty, width: cs.width };
        p.remove();
        return r;
      });
      ok(/^0\.4\b|^40%/.test(bar.scale) && /scale/.test(bar.transition) && !/width/.test(bar.transition), 'progress bar fills with scale (' + bar.scale + ', ' + bar.transition + ')');
      // 52: لمعة الهيكل تتحرك بـ transform
      const sk = await page.evaluate(() => {
        const d = document.createElement('div'); d.className = 'skeleton'; d.style.height = '20px'; document.body.appendChild(d);
        const cs = getComputedStyle(d, '::after'); const r = { name: cs.animationName, bg: getComputedStyle(d).animationName };
        d.remove(); return r;
      });
      ok(/^sk-sweep/.test(sk.name) && sk.bg === 'none', 'skeleton shimmer sweeps with transform (' + sk.name + ')');
      // 82: منحنى linear()
      const spring = await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--ease-spring').trim());
      ok(/^linear\(/.test(spring), 'spring easing uses linear()');
    }
    // 44: العدّاد
    const cnt = await page.evaluate(() => new Promise((res) => {
      const s = document.createElement('span'); s.textContent = '0'; document.body.appendChild(s);
      SN.countTo(s, 1000);
      const mid = [];
      setTimeout(() => mid.push(s.textContent), 120);
      setTimeout(() => { mid.push(s.textContent); s.remove(); res(mid); }, 450);
    }));
    if (reduced) {
      ok(cnt[0] === '1,000', 'counter: final value at once with reduced motion');
    } else {
      ok(cnt[0] !== '0' && cnt[0] !== '1,000' && cnt[1] === '1,000', 'counter counts up within 300ms (' + cnt.join(' → ') + ')');
    }
    // 54: تموج الضغط
    const btn = page.locator('.page-actions .btn, .btn').first();
    const b = await btn.boundingBox();
    await page.mouse.move(b.x + 10, b.y + b.height / 2);
    await page.mouse.down();
    const has = await page.evaluate(() => !!document.querySelector('.ripple-wrap .ripple'));
    await page.mouse.up();
    await page.keyboard.press('Escape');
    if (reduced) { ok(!has, 'no ripple with reduced motion'); } else {
      ok(has, 'ripple on press');
      await page.waitForTimeout(500);
      ok(await page.evaluate(() => !document.querySelector('.ripple-wrap')), 'ripple removed afterwards');
    }
    ok(errors.length === 0, 'no JavaScript errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
    await ctx.close();
  }
  await browser.close();
  console.log(failed ? `\n${failed} failed` : '\nall passed');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
