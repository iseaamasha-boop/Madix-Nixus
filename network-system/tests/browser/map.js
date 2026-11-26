#!/usr/bin/env node
/*
 * المرحلة 5 في المتصفح (الخريطة).
 *   node tests/browser/map.js https://madix.test [--theme=dark|black]
 * - العلامات تظهر بسرعة (بيانات الخريطة كانت 4.4 ثانية بسبب استعلام فرعي لكل مشترك).
 * - 58: كل برج بحالة أجهزة MikroTik عليه (is-up / is-down / is-idle) بموجة؛ تغيّر حالة جهاز يصل مع التحديث المجمّع بلا إعادة تحميل.
 * - 59: نقاط ضوء تتحرك بـ transform على الروابط التي عليها حركة، وتُعاد بعد التكبير.
 * - 60: الخريطة الليلية تتبع الوضع الداكن والأسود.
 * - تقليل الحركة: بلا موجة ولا نقاط.
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

async function open(browser, reduced) {
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'ar', viewport: { width: 1280, height: 860 }, reducedMotion: reduced ? 'reduce' : 'no-preference' });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e.message || e)));
  await page.goto(BASE + '/' + TENANT + '/login');
  await page.fill('input[name=username]', USER);
  await page.fill('input[name=password]', PASS);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  await page.goto(BASE + '/' + TENANT + '/map', { waitUntil: 'domcontentloaded' });
  const t0 = Date.now();
  await page.waitForSelector('.leaflet-marker-icon .mk-site', { timeout: 15000 });
  const ms = Date.now() - t0;
  await page.evaluate((t) => {
    if (t === 'light') { return; }
    document.documentElement.setAttribute('data-theme', 'dark');
    document.documentElement.setAttribute('data-theme-pref', t);
  }, THEME);
  await page.waitForTimeout(800);
  return { ctx, page, errors, ms };
}

(async () => {
  const browser = await chromium.launch({ args: ['--no-proxy-server'] });
  const m = await open(browser, false);
  const p = m.page;
  ok(m.ms < 3000, 'map markers shown quickly (' + m.ms + 'ms)');
  const st = await p.evaluate(() => {
    const sites = [...document.querySelectorAll('.leaflet-marker-icon .mk-site')];
    const count = (c) => sites.filter((e) => e.classList.contains(c)).length;
    const lit = document.querySelector('.leaflet-marker-icon .mk-site.is-up, .leaflet-marker-icon .mk-site.is-down');
    return {
      total: sites.length, up: count('is-up'), down: count('is-down'), idle: count('is-idle'),
      wave: lit ? getComputedStyle(lit, '::after').animationName : '',
      dots: document.querySelectorAll('.flow-dot').length,
      moving: document.getAnimations().filter((a) => a.effect && a.effect.target && a.effect.target.classList && a.effect.target.classList.contains('flow-dot')).length,
      filter: document.querySelector('.tiles-street') ? getComputedStyle(document.querySelector('.tiles-street')).filter : null,
    };
  });
  ok(st.total > 0 && st.up + st.down + st.idle === st.total, 'every tower has a status (' + st.up + ' up, ' + st.down + ' down, ' + st.idle + ' idle)');
  ok(st.up + st.down === 0 || st.wave === 'site-wave', 'towers pulse with a wave');
  ok(st.dots > 0 && st.moving === st.dots, 'light dots moving on links (' + st.dots + ')');
  if (st.filter !== null) {
    ok(THEME === 'light' ? st.filter === 'none' : /invert/.test(st.filter), 'night map follows the theme (' + st.filter + ')');
  }
  // تكبير: النقاط تُعاد بمواضع جديدة
  await p.click('.leaflet-control-zoom-in');
  await p.waitForTimeout(900);
  ok(await p.evaluate(() => document.querySelectorAll('.flow-dot').length > 0), 'light dots rebuilt after zoom');
  // التحديث المجمّع: حالة جديدة تُطبَّق على العلامات دون إعادة تحميل (محاكاة رد الخادم)
  const changed = await p.evaluate(() => new Promise((res) => {
    const before = document.querySelectorAll('.leaflet-marker-icon .mk-site.is-down').length;
    const orig = window.fetch;
    window.fetch = function (u, o) {
      if (/\/live\?/.test(String(u))) {
        return orig(u, o).then((r) => r.json()).then((d) => {
          if (d.data && Array.isArray(d.data.map)) { d.data.map.forEach((x) => { x.status = 'offline'; }); }
          return new Response(JSON.stringify(d), { headers: { 'Content-Type': 'application/json' } });
        });
      }
      return orig(u, o);
    };
    SN.live.refresh();
    setTimeout(() => { window.fetch = orig; res({ before, after: document.querySelectorAll('.leaflet-marker-icon .mk-site.is-down').length }); }, 1500);
  }));
  ok(changed.after > changed.before, 'live status reaches the towers without reload (' + changed.before + ' → ' + changed.after + ' down)');
  ok(m.errors.length === 0, 'no JavaScript errors' + (m.errors.length ? ': ' + m.errors.join(' | ') : ''));
  await m.ctx.close();

  const r = await open(browser, true);
  const rs = await r.page.evaluate(() => {
    const lit = document.querySelector('.leaflet-marker-icon .mk-site.is-up, .leaflet-marker-icon .mk-site.is-down');
    return { dots: document.querySelectorAll('.flow-dot').length, wave: lit ? getComputedStyle(lit, '::after').animationName : 'none' };
  });
  ok(rs.dots === 0 && rs.wave === 'none', 'reduced motion: no wave and no light dots');
  await r.ctx.close();
  await browser.close();
  console.log(failed ? `\n${failed} failed` : '\nall passed');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
