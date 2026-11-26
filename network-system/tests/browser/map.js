#!/usr/bin/env node
/*
 * المرحلة 5 في المتصفح (الخريطة).
 *   node tests/browser/map.js https://madix.test [--theme=dark|black]
 * - العلامات تظهر بسرعة (بيانات الخريطة كانت 4.4 ثانية بسبب استعلام فرعي لكل مشترك).
 * - 58: كل برج بحالة أجهزة MikroTik عليه (is-up / is-down / is-idle) بموجة؛ تغيّر حالة جهاز يصل مع التحديث المجمّع بلا إعادة تحميل.
 * - 59: نقاط ضوء تتحرك بـ transform على الروابط التي عليها حركة، وتُعاد بعد التكبير.
 * - 60: الخريطة الليلية تتبع الوضع الداكن والأسود.
 * - 93: طبقة حرارية عند تفعيلها فقط؛ 94: شريط إعادة تشغيل اليوم بخطوات 15 دقيقة.
 * - 92: الخريطة ثلاثية الأبعاد تُحمَّل عند الضغط فقط، بأعمدة للأبراج، وتعود إلى 2D.
 * - راوتر باسمه على الخريطة، ورابط بينه وبين جهاز MikroTik بالنقر على الطرفين، وحذفه مع «تراجع».
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
  const browser = await chromium.launch({ args: ['--no-proxy-server', '--enable-unsafe-swiftshader', '--use-angle=swiftshader'] });
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
  // 93: الطبقة الحرارية تُحمَّل عند تفعيلها فقط
  let tools = 0;
  p.on('request', (rq) => { if (/map-tools\.js/.test(rq.url())) { tools++; } });
  ok(tools === 0, 'map tools not loaded with the page');
  await p.click('label.map-layer:has([data-map-heat])');
  await p.waitForSelector('canvas.map-heat', { timeout: 8000 }).catch(() => null);
  await p.waitForTimeout(400);
  const heat = await p.evaluate(() => {
    const c = document.querySelector('canvas.map-heat');
    if (!c) { return null; }
    const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data;
    let n = 0; for (let i = 3; i < d.length; i += 400) { if (d[i]) { n++; } }
    return { n, areas: document.querySelectorAll('.heat-row').length };
  });
  ok(tools === 1 && heat && heat.n > 0, 'heat layer drawn from real usage (' + (heat ? heat.n : 0) + ' samples)');
  ok(heat && heat.areas > 0, 'top areas listed');
  await p.click('label.map-layer:has([data-map-heat])');
  ok(await p.evaluate(() => !document.querySelector('canvas.map-heat')), 'heat layer removed when turned off');
  // 94: إعادة تشغيل اليوم
  await p.click('[data-map-replay]');
  await p.waitForSelector('.map-replay [data-rp-slider]', { timeout: 8000 });
  await p.waitForTimeout(600);
  const labels0 = await p.evaluate(() => document.querySelectorAll('.mk-label.is-site').length);
  const slot = async (v) => { await p.evaluate((v) => { const sl = document.querySelector('[data-rp-slider]'); sl.value = v; sl.dispatchEvent(new Event('input', { bubbles: true })); }, v); return p.evaluate(() => ({ time: document.querySelector('[data-rp-time]').textContent, labels: document.querySelectorAll('.mk-label.is-site').length })); };
  const s1 = await slot(40), s2 = await slot(41);
  ok(s1.time === '10:00' && s2.time === '10:15', 'timeline moves in 15 minute steps');
  ok(s2.labels === labels0, 'tower labels updated in place (no duplicates)');
  await p.click('[data-rp-close]');
  ok(await p.evaluate(() => !document.querySelector('.map-replay')), 'replay closes back to the live map');
  // 92: الخريطة ثلاثية الأبعاد
  let lib = 0;
  p.on('request', (rq) => { if (/maplibre-gl\.js|map3d\.js/.test(rq.url())) { lib++; } });
  ok(lib === 0 && await p.isVisible('[data-map-3d]'), '3D button shown, library not loaded yet');
  await p.click('[data-map-3d]');
  await p.waitForFunction(() => window.SNMap3D && SNMap3D.map() && SNMap3D.map().getLayer('sn-cols'), null, { timeout: 20000 }).catch(() => null);
  await p.waitForTimeout(1200);
  const d3 = await p.evaluate(() => { const m = window.SNMap3D && SNMap3D.map(); return m ? { pitch: Math.round(m.getPitch()), cols: m.queryRenderedFeatures({ layers: ['sn-cols'] }).length } : null; });
  ok(lib === 2 && d3 && d3.pitch > 30 && d3.cols > 0, '3D opens on demand with tower columns (' + JSON.stringify(d3) + ')');
  await p.click('[data-map-3d-close]');
  ok(await p.evaluate(() => !document.querySelector('.map-3d') && document.querySelector('[data-map-3d]').getAttribute('aria-pressed') === 'false'), 'back to the 2D map');
  // راوتر على الخريطة باسمه، ثم رابط بينه وبين جهاز MikroTik بالنقر على الطرفين
  const NAME = 'راوتر اختبار ' + Date.now() % 100000;
  await p.click('[data-map-act="router"]');
  await p.evaluate(() => { const c = SNMap.map.getCenter(); SNMap.map.fire('click', { latlng: L.latLng(c.lat + 0.0007, c.lng + 0.0009) }); });
  await p.waitForSelector('#router-modal[open]', { timeout: 5000 });
  if (await p.isVisible('#router-modal [data-router-kinds]')) { await p.click('#router-modal label:has(input[name="kind"][value="node"])'); }
  await p.fill('#rm-node', NAME);
  await p.click('#router-modal [data-router-submit]');
  await p.waitForFunction((n) => (SNMap.data().nodes || []).some((x) => x.name === n), NAME, { timeout: 8000 }).catch(() => null);
  const node = await p.evaluate((n) => { const x = (SNMap.data().nodes || []).filter((y) => y.name === n)[0]; return x ? x.id : null; }, NAME);
  ok(node !== null, 'router added to the map by name');
  await p.waitForTimeout(400);
  ok(await p.evaluate((id) => { const m = SNMap.markers.nodes[id]; const t = m && m.getTooltip(); return !!(m && m.getElement().querySelector('.mk-node') && t && t.getContent()); }, node), 'router marker with its name label');
  await p.click('[data-map-act="link"]');
  const rid = await p.evaluate(() => { const r = SNMap.data().routers[0]; return r ? r.id : null; });
  await p.evaluate((id) => { SNMap.markers.nodes[id].fire('click'); }, node);
  ok(await p.evaluate((id) => SNMap.markers.nodes[id].getElement().classList.contains('is-picked'), node), 'first end picked');
  await p.evaluate((id) => { SNMap.markers.routers[id].fire('click'); }, rid);
  await p.waitForSelector('#link-modal[open]', { timeout: 5000 });
  const ends = await p.evaluate(() => [document.querySelector('#lm-a').value, document.querySelector('#lm-b').value]);
  ok(ends[0] === 'node:' + node && ends[1] === 'router:' + rid, 'link form filled with both ends (' + ends.join(' → ') + ')');
  const linksBefore = await p.evaluate(() => SNMap.data().links.length);
  await p.click('#link-modal button[type=submit]');
  await p.waitForFunction((n) => SNMap.data().links.length > n, linksBefore, { timeout: 8000 }).catch(() => null);
  ok(await p.evaluate((id) => SNMap.data().links.some((l) => l.a.of === 'node' && l.a.id === id), node), 'link drawn between the router and the MikroTik device');
  // حذف الراوتر (وروابطه) ثم «تراجع»
  await p.evaluate((id) => { SNMap.markers.nodes[id].openPopup(); }, node);
  await p.click('.leaflet-popup [data-pop="edit-node"]');
  await p.waitForSelector('#router-modal[open] [data-node-delete]:not([hidden])', { timeout: 5000 });
  await p.click('#router-modal [data-node-delete]');
  await p.waitForFunction((id) => !(SNMap.data().nodes || []).some((x) => x.id === id), node, { timeout: 8000 }).catch(() => null);
  ok(await p.evaluate((id) => !SNMap.markers.nodes[id] && !SNMap.data().links.some((l) => l.a.of === 'node' && l.a.id === id), node), 'router and its link removed');
  await p.click('.toast .toast-action');
  await p.waitForFunction((id) => (SNMap.data().nodes || []).some((x) => x.id === id), node, { timeout: 8000 }).catch(() => null);
  ok(await p.evaluate((id) => !!SNMap.markers.nodes[id] && SNMap.data().links.some((l) => l.a.of === 'node' && l.a.id === id), node), 'undo brings back the router and its link');
  // تنظيف بيانات العرض
  await p.evaluate((id) => SN.api(SNMap.url('/map/nodes/' + id + '/delete'), { method: 'POST', data: {} }).then(() => true), node);
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
