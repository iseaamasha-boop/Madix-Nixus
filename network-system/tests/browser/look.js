#!/usr/bin/env node
/*
 * المرحلة 4 في المتصفح: الصور الرمزية الملونة (90)، الوضع الأسود الكامل (88)، والرسم الحي (45).
 *   node tests/browser/look.js https://madix.test
 * - الوضع الأسود من قائمة المظهر: خلفية وأسطح سوداء خالصة، يبقى بعد إعادة التحميل، ويُعاد المالك إلى الفاتح في النهاية.
 * - تباين نص الصورة الرمزية ≥ 4.5 على كل الدرجات في الفاتح والداكن والأسود (بالألوان المحسوبة فعلاً).
 * - تباين نص الصفحة الأساسي على الأسود.
 */
'use strict';

const { chromium } = require('playwright');

const argv = process.argv.slice(2);
const BASE = (argv.find((a) => /^https?:/.test(a)) || 'http://127.0.0.1:8090').replace(/\/$/, '');
const TENANT = process.env.MNX_TENANT || 'demo';
const USER = process.env.MNX_USER || 'owner';
const PASS = process.env.MNX_PASS || 'OwnerPass123';

let failed = 0;
function ok(cond, msg) {
  console.log((cond ? '  ✔ ' : '  ✘ ') + msg);
  if (!cond) { failed++; }
}

// أسوأ تباين لنص الصورة الرمزية على الدرجات 0..359، ونص الصفحة على خلفيتها
const measure = () => {
  const cv = document.createElement('canvas'); cv.width = cv.height = 1;
  const g = cv.getContext('2d', { willReadFrequently: true });
  const rgb = (c) => { g.clearRect(0, 0, 1, 1); g.fillStyle = '#000'; g.fillStyle = c; g.fillRect(0, 0, 1, 1); const d = g.getImageData(0, 0, 1, 1).data; return [d[0], d[1], d[2]]; };
  const lum = (c) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]); };
  const ratio = (a, b) => { const x = lum(rgb(a)), y = lum(rgb(b)); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
  const el = document.createElement('span'); el.className = 'avatar av'; el.textContent = 'AB';
  document.querySelector('.content').appendChild(el);
  let worst = 99;
  for (let h = 0; h < 360; h++) { el.style.setProperty('--h', h); const cs = getComputedStyle(el); worst = Math.min(worst, ratio(cs.backgroundColor, cs.color)); }
  el.remove();
  const body = getComputedStyle(document.body);
  return { avatar: Math.round(worst * 100) / 100, text: Math.round(ratio(body.backgroundColor, body.color) * 100) / 100, bg: body.backgroundColor };
};

(async () => {
  const browser = await chromium.launch({ args: ['--no-proxy-server'] });
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'ar', viewport: { width: 1280, height: 800 } });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e.message || e)));
  await page.goto(BASE + '/' + TENANT + '/login');
  await page.fill('input[name=username]', USER);
  await page.fill('input[name=password]', PASS);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  const pick = async (t) => { await page.evaluate((v) => document.querySelector('[data-theme-set="' + v + '"]').click(), t); await page.waitForTimeout(400); };
  try {
    await page.goto(BASE + '/' + TENANT + '/subscribers', { waitUntil: 'load' });
    ok(await page.$('[data-theme-set="black"]') !== null, 'black option in the theme menu');
    for (const t of ['light', 'dark', 'black']) {
      await pick(t);
      const m = await page.evaluate(measure);
      ok(m.avatar >= 4.5, t + ': avatar contrast on every hue ≥ 4.5 (' + m.avatar + ')');
      ok(m.text >= 7, t + ': page text contrast ' + m.text);
      if (t === 'black') {
        const s = await page.evaluate(() => ({ theme: document.documentElement.dataset.theme, pref: document.documentElement.dataset.themePref, panel: getComputedStyle(document.querySelector('.panel')).backgroundColor, sidebar: getComputedStyle(document.querySelector('.sidebar')).backgroundImage }));
        ok(m.bg === 'rgb(0, 0, 0)' && s.panel === 'rgb(0, 0, 0)', 'black: background and panels are pure black');
        ok(s.theme === 'dark' && s.pref === 'black', 'black is drawn over the dark theme');
        await page.reload({ waitUntil: 'load' });
        ok(await page.evaluate(() => document.documentElement.dataset.themePref === 'black' && getComputedStyle(document.body).backgroundColor === 'rgb(0, 0, 0)'), 'black kept after reload');
      }
    }
    // البند 45: الرسم الحي على لوحة التحكم: مكتبة الرسوم مرة واحدة عند الظهور، نقطة جديدة مع كل تحديث مجمّع، والرسم الثاني عند فتح تبويبه
    let libLoads = 0;
    page.on('request', (r) => { if (/chart\.umd/.test(r.url())) { libLoads++; } });
    await page.goto(BASE + '/' + TENANT + '/', { waitUntil: 'load' });
    await page.locator('#live-chart').scrollIntoViewIfNeeded();
    await page.waitForFunction(() => window.Chart && Chart.getChart(document.getElementById('live-chart')), null, { timeout: 8000 }).catch(() => null);
    const c0 = await page.evaluate(() => { const c = window.Chart && Chart.getChart(document.getElementById('live-chart')); return c ? { n: c.data.datasets.map((d) => d.data.length), x: c.options.scales.x.type, y2: !!c.options.scales.y2, max: c.options.scales.x.max } : null; });
    ok(c0 && c0.n.length === 3 && c0.n.every((n) => n >= 1) && c0.x === 'linear' && c0.y2, 'live chart: users, download and upload on a time axis (' + JSON.stringify(c0) + ')');
    const last = () => page.evaluate(() => { const c = Chart.getChart(document.getElementById('live-chart')); const d = c.data.datasets[0].data; return { x: d[d.length - 1].x, first: d[0].x, max: c.options.scales.x.max }; });
    const l0 = await last();
    await page.waitForTimeout(50);
    await page.evaluate(() => SN.live.refresh());
    await page.waitForFunction((x) => { const d = Chart.getChart(document.getElementById('live-chart')).data.datasets[0].data; return d[d.length - 1].x > x; }, l0.x, { timeout: 5000 }).catch(() => null);
    const l1 = await last();
    ok(l1.x > l0.x && l1.max > l0.max, 'live update adds the newest point and moves the window');
    ok(l1.max - l1.first <= 3600e3 + 1000, 'only the last hour is kept (' + Math.round((l1.max - l1.first) / 60000) + ' minutes)');
    ok(await page.evaluate(() => !Chart.getChart(document.getElementById('online-chart') || document.createElement('canvas'))), '24 hours chart not drawn before its tab opens');
    await page.click('label:has(input[value="24h"])');
    await page.waitForTimeout(600);
    ok(await page.evaluate(() => { const c = document.getElementById('online-chart'); return !c || (!!Chart.getChart(c) && document.querySelector('[data-range-box="live"]').hidden); }), '24 hours tab shows its chart');
    ok(libLoads === 1, 'chart library loaded once (' + libLoads + ')');
    // صفحة صغيرة على الهاتف بالأسود: لا عناصر أعرض من الشاشة
    const phone = await ctx.newPage();
    await phone.setViewportSize({ width: 360, height: 740 });
    await phone.goto(BASE + '/' + TENANT + '/', { waitUntil: 'load' });
    ok(await phone.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'black at 360px: no horizontal overflow');
    await phone.close();
    ok(errors.length === 0, 'no JavaScript errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  } finally {
    await page.goto(BASE + '/' + TENANT + '/', { waitUntil: 'load' });
    await pick('light');
  }
  await browser.close();
  console.log(failed ? `\n${failed} failed` : '\nall passed');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
