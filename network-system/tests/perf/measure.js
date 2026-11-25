#!/usr/bin/env node
/*
 * قياس سرعة صفحات MADIX NEXUS (قبل التعديل وبعده بنفس الطريقة).
 *
 *   node tests/perf/measure.js --base=https://madix.test --tenant=demo --user=owner --pass=OwnerPass123 \
 *        --admin=admin --admin-pass=AdminPass123 [--lighthouse] [--runs=7] [--out=result.json] [--label=before]
 *
 * لكل صفحة:
 *   - وقت الخادم: زمن أول بايت (TTFB) بوسيط عدة طلبات متتالية بعد طلب تسخين، وعدد استعلامات SQL ووقتها
 *     (من الترويسة Server-Timing أو X-Mnx-Sql إن كانت مفعّلة على الخادم).
 *   - المتصفح بذاكرة فارغة على «نت عادي» (20 ميجابت، 40ms): LCP وCLS وFCP، حجم JS وCSS المضغوط، وعدد الطلبات.
 *   - INP: أطول تفاعل عند فتح القوائم والكتابة في البحث.
 *   - التنقل بعد أول تحميل: من الضغط على رابط القائمة الجانبية حتى ظهور الصفحة التالية.
 *   - Lighthouse للجوال (اختياري --lighthouse): النتيجة وLCP وCLS وTBT.
 */
'use strict';

const https = require('https');
const http = require('http');
const path = require('path');
const fs = require('fs');
const { chromium } = require('playwright');

const args = Object.fromEntries(process.argv.slice(2).map((a) => {
  const m = a.match(/^--([a-z-]+)(?:=(.*))?$/);
  return m ? [m[1], m[2] === undefined ? '1' : m[2]] : [a, '1'];
}));
const BASE = (args.base || 'https://madix.test').replace(/\/$/, '');
const TENANT = args.tenant || 'demo';
const RUNS = Math.max(3, parseInt(args.runs || '7', 10));
const T = '/' + TENANT;

const PAGES = [
  { key: 'dashboard', name: 'لوحة التحكم', path: T + '/', ctx: 'net' },
  { key: 'subscribers', name: 'المشتركين', path: T + '/subscribers', ctx: 'net' },
  { key: 'cards', name: 'الكروت', path: T + '/cards', ctx: 'net' },
  { key: 'routers', name: 'أجهزة MikroTik', path: T + '/routers', ctx: 'net' },
  { key: 'map', name: 'الخريطة', path: T + '/map', ctx: 'net' },
  { key: 'reports', name: 'التقارير', path: T + '/reports', ctx: 'net' },
  { key: 'hotspot', name: 'ربط الهوتسبوت', path: T + '/hotspot', ctx: 'net' },
  { key: 'admin', name: 'لوحة المسؤول', path: '/admin', ctx: 'admin' },
  { key: 'landing', name: 'صفحة الهبوط', path: '/', ctx: 'guest' },
];

const NORMAL_NET = { offline: false, latency: 40, downloadThroughput: (20 * 1024 * 1024) / 8, uploadThroughput: (5 * 1024 * 1024) / 8 };
const LAUNCH = { args: ['--ignore-certificate-errors', '--no-proxy-server'] };

function median(a) { const s = a.slice().sort((x, y) => x - y); return s.length ? s[Math.floor(s.length / 2)] : 0; }
function round(n, d = 0) { const k = Math.pow(10, d); return Math.round(n * k) / k; }

/** طلب GET خام لقياس زمن أول بايت (اتصال مفتوح مسبقاً) */
function timedGet(agent, url, cookie, nocache) {
  return new Promise((resolve, reject) => {
    const u = new URL(url);
    const mod = u.protocol === 'https:' ? https : http;
    const t0 = process.hrtime.bigint();
    const headers = { Cookie: cookie || '', 'Accept-Encoding': 'gzip, br', Accept: 'text/html' };
    // بلا كاش: أسوأ حالة (تعمل فقط مع app.timing في ملف إعداد الخادم)
    if (nocache) { headers['X-Mnx-Cache'] = 'off'; }
    const req = mod.request(u, { method: 'GET', agent, rejectUnauthorized: false, headers }, (res) => {
      let first = 0;
      let bytes = 0;
      res.on('data', (c) => { if (!first) { first = Number(process.hrtime.bigint() - t0) / 1e6; } bytes += c.length; });
      res.on('end', () => {
        const total = Number(process.hrtime.bigint() - t0) / 1e6;
        resolve({ status: res.statusCode, ttfb: first || total, total, bytes, headers: res.headers });
      });
    });
    req.on('error', reject);
    req.end();
  });
}

/** عدد الاستعلامات ووقتها من ترويسات الخادم */
function sqlOf(headers) {
  const st = String(headers['server-timing'] || '');
  let m = st.match(/db;dur=([\d.]+)(?:;desc="?(\d+))?/);
  if (m) { return { n: parseInt(m[2] || '0', 10), ms: parseFloat(m[1]) }; }
  const x = String(headers['x-mnx-sql'] || '');
  m = x.match(/n=(\d+);ms=([\d.]+)/);
  return m ? { n: parseInt(m[1], 10), ms: parseFloat(m[2]) } : null;
}

async function login(browser, kind) {
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'ar' });
  if (kind === 'guest') { return ctx; }
  const page = await ctx.newPage();
  if (kind === 'admin') {
    await page.goto(BASE + '/admin/login');
    await page.fill('input[name=username]', args.admin || 'admin');
    await page.fill('input[name=password]', args['admin-pass'] || 'AdminPass123');
  } else {
    await page.goto(BASE + T + '/login');
    await page.fill('input[name=username]', args.user || 'owner');
    await page.fill('input[name=password]', args.pass || 'OwnerPass123');
  }
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('button[type=submit]')]);
  if (/login/.test(page.url())) { throw new Error('login failed for ' + kind + ' at ' + page.url()); }
  await page.close();
  return ctx;
}

async function cookieHeader(ctx, url) {
  const list = await ctx.cookies(url);
  return list.map((c) => c.name + '=' + c.value).join('; ');
}

/** قياس المتصفح: تحميل بذاكرة فارغة على نت عادي */
async function browserLoad(ctx, url) {
  const page = await ctx.newPage();
  const cdp = await ctx.newCDPSession(page);
  await cdp.send('Network.enable');
  await cdp.send('Network.clearBrowserCache');
  await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
  await cdp.send('Network.emulateNetworkConditions', NORMAL_NET);
  await page.addInitScript(() => {
    window.__m = { lcp: 0, cls: 0, inp: 0, ev: [] };
    try {
      new PerformanceObserver((l) => { l.getEntries().forEach((e) => { window.__m.lcp = e.startTime; }); }).observe({ type: 'largest-contentful-paint', buffered: true });
      new PerformanceObserver((l) => { l.getEntries().forEach((e) => { if (!e.hadRecentInput) { window.__m.cls += e.value; } }); }).observe({ type: 'layout-shift', buffered: true });
      new PerformanceObserver((l) => { l.getEntries().forEach((e) => { if (e.interactionId) { window.__m.inp = Math.max(window.__m.inp, e.duration); window.__m.ev.push([e.name, Math.round(e.duration)]); } }); }).observe({ type: 'event', buffered: true, durationThreshold: 16 });
    } catch (e) {}
  });
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e.message || e)));
  page.on('console', (m) => { if (m.type() === 'error') { errors.push(m.text()); } });
  const resp = await page.goto(url, { waitUntil: 'load', timeout: 60000 });
  await page.waitForTimeout(1500);
  const data = await page.evaluate(() => {
    const n = performance.getEntriesByType('navigation')[0] || {};
    const res = performance.getEntriesByType('resource');
    const by = { js: 0, css: 0, font: 0, img: 0, other: 0 };
    const files = [];
    res.forEach((r) => {
      const size = r.encodedBodySize || r.transferSize || 0;
      let t = 'other';
      if (r.initiatorType === 'script' || /\.js(\?|$)/.test(r.name)) { t = 'js'; }
      else if (/\.css(\?|$)/.test(r.name) || (r.initiatorType === 'link' && /css/.test(r.name))) { t = 'css'; }
      else if (/\.(woff2?|ttf|otf)(\?|$)/.test(r.name)) { t = 'font'; }
      else if (r.initiatorType === 'img' || /\.(png|jpe?g|webp|svg|gif|ico)(\?|$)/.test(r.name)) { t = 'img'; }
      by[t] += size;
      files.push([t, r.name.replace(location.origin, '').replace(/\?.*$/, ''), size]);
    });
    let fcp = 0;
    performance.getEntriesByType('paint').forEach((p) => { if (p.name === 'first-contentful-paint') { fcp = p.startTime; } });
    return {
      ttfb: n.responseStart - n.requestStart, fcp, lcp: window.__m.lcp, cls: window.__m.cls,
      load: n.loadEventEnd, dcl: n.domContentLoadedEventEnd, htmlBytes: n.encodedBodySize || 0, requests: res.length + 1, by, files,
      inlineCss: Array.prototype.reduce.call(document.querySelectorAll('style'), (s, el) => s + el.textContent.length, 0),
      inlineJs: Array.prototype.reduce.call(document.querySelectorAll('script:not([src])'), (s, el) => s + el.textContent.length, 0),
    };
  });
  // INP: فتح القوائم وإغلاقها، والكتابة في حقل بحث، والضغط على أزرار التبويبات إن وُجدت
  await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
  const clickables = ['.user-chip', '[data-dropdown]', '[data-sidebar-toggle]', '.tabs .tab', 'button.btn'];
  for (const sel of clickables) {
    const el = await page.$(sel);
    if (el && await el.isVisible().catch(() => false)) {
      try { await el.click({ timeout: 2000, noWaitAfter: true, trial: false }); await page.waitForTimeout(250); await page.keyboard.press('Escape'); } catch (e) {}
      break;
    }
  }
  const input = await page.$('input[type=search]:visible, input[type=text]:visible');
  if (input) { try { await input.click({ timeout: 2000 }); await page.keyboard.type('abc', { delay: 60 }); } catch (e) {} }
  await page.waitForTimeout(600);
  const inp = await page.evaluate(() => window.__m.inp);
  await page.close();
  return Object.assign(data, { status: resp ? resp.status() : 0, inp, errors });
}

/** التنقل بعد أول تحميل: الضغط على رابط القائمة (بلا مرور الماوس) ومع مرور الماوس قبله */
async function navTime(ctx, fromUrl, toPath, hover) {
  const page = await ctx.newPage();
  await page.goto(fromUrl, { waitUntil: 'load' });
  await page.waitForTimeout(800);
  const sel = 'a[href="' + toPath + '"]';
  const link = await page.$('.sidebar ' + sel) || await page.$(sel);
  if (!link) { await page.close(); return null; }
  const isVisible = await link.isVisible();
  if (!isVisible) {
    const t = await page.$('[data-sidebar-toggle]');
    if (t) { await t.click(); await page.waitForTimeout(400); }
  }
  if (hover) { await link.hover(); await page.waitForTimeout(350); }
  const t0 = await page.evaluate(() => performance.timeOrigin + performance.now());
  await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), hover ? link.click() : link.evaluate((a) => a.click())]);
  await page.waitForTimeout(500);
  const shown = await page.evaluate(() => {
    let fcp = 0;
    performance.getEntriesByType('paint').forEach((p) => { if (p.name === 'first-contentful-paint') { fcp = p.startTime; } });
    return { origin: performance.timeOrigin, fcp };
  });
  await page.close();
  return round(shown.origin + shown.fcp - t0);
}

async function lighthouseRun(url, cookie) {
  // مكتبتا Lighthouse وchrome-launcher من مجلد التثبيت المحدد بالمتغير LH_DIR (node_modules) أو من المسارات العادية
  const { pathToFileURL } = require('url');
  const from = (name, entry) => (process.env.LH_DIR ? import(pathToFileURL(path.join(process.env.LH_DIR, name, entry)).href) : import(name));
  const lighthouse = (await from('lighthouse', 'core/index.js')).default;
  const chromeLauncher = await from('chrome-launcher', 'dist/index.js');
  const chrome = await chromeLauncher.launch({
    chromePath: chromium.executablePath(),
    chromeFlags: ['--headless=new', '--no-sandbox', '--ignore-certificate-errors', '--no-proxy-server'],
  });
  try {
    const r = await lighthouse(url, { port: chrome.port, output: 'json', logLevel: 'error', onlyCategories: ['performance'], extraHeaders: cookie ? { Cookie: cookie } : undefined });
    const a = r.lhr.audits;
    return {
      score: Math.round((r.lhr.categories.performance.score || 0) * 100),
      lcp: Math.round(a['largest-contentful-paint'].numericValue), fcp: Math.round(a['first-contentful-paint'].numericValue),
      cls: round(a['cumulative-layout-shift'].numericValue, 3), tbt: Math.round(a['total-blocking-time'].numericValue),
      si: Math.round(a['speed-index'].numericValue),
    };
  } finally {
    await chrome.kill();
  }
}

(async () => {
  const browser = await chromium.launch(LAUNCH);
  const ctxs = { net: await login(browser, 'net'), admin: await login(browser, 'admin'), guest: await login(browser, 'guest') };
  const cookies = { net: await cookieHeader(ctxs.net, BASE + T + '/'), admin: await cookieHeader(ctxs.admin, BASE + '/admin'), guest: '' };
  const agent = new (BASE.startsWith('https') ? https : http).Agent({ keepAlive: true, maxSockets: 1, rejectUnauthorized: false });
  const results = [];
  for (const p of PAGES) {
    const url = BASE + p.path;
    await timedGet(agent, url, cookies[p.ctx]);
    const ttfbs = [];
    const sqls = [];
    let status = 0;
    let htmlBytes = 0;
    for (let i = 0; i < RUNS; i++) {
      const r = await timedGet(agent, url, cookies[p.ctx]);
      status = r.status;
      htmlBytes = r.bytes;
      ttfbs.push(r.ttfb);
      const s = sqlOf(r.headers);
      if (s) { sqls.push(s); }
    }
    // الصفحة نفسها بلا كاش الخادم (أول فتح بعد تغيّر البيانات)
    const cold = [];
    for (let i = 0; i < Math.min(RUNS, 5); i++) {
      cold.push((await timedGet(agent, url, cookies[p.ctx], true)).ttfb);
    }
    const b = await browserLoad(ctxs[p.ctx], url);
    const row = {
      key: p.key, name: p.name, path: p.path, status,
      ttfb: round(median(ttfbs), 1), ttfbMin: round(Math.min.apply(null, ttfbs), 1), ttfbMax: round(Math.max.apply(null, ttfbs), 1),
      ttfbCold: round(median(cold), 1), ttfbColdMax: round(Math.max.apply(null, cold), 1),
      sqlN: sqls.length ? median(sqls.map((s) => s.n)) : null, sqlMs: sqls.length ? round(median(sqls.map((s) => s.ms)), 1) : null,
      htmlKB: round(b.htmlBytes / 1024, 1), jsKB: round(b.by.js / 1024, 1), cssKB: round(b.by.css / 1024, 1), fontKB: round(b.by.font / 1024, 1), imgKB: round(b.by.img / 1024, 1),
      requests: b.requests, fcp: round(b.fcp), lcp: round(b.lcp), cls: round(b.cls, 3), inp: round(b.inp), load: round(b.load),
      inlineCssKB: round(b.inlineCss / 1024, 1), inlineJsKB: round(b.inlineJs / 1024, 1), errors: b.errors, files: b.files,
    };
    if (p.ctx === 'net' && p.key !== 'dashboard') {
      row.navClick = await navTime(ctxs.net, BASE + T + '/', p.path, false);
      row.navHover = await navTime(ctxs.net, BASE + T + '/', p.path, true);
    }
    if (args.lighthouse) {
      try { row.lh = await lighthouseRun(url, cookies[p.ctx]); } catch (e) { row.lh = { error: String(e.message || e) }; }
    }
    results.push(row);
    process.stderr.write(p.key + ' ttfb=' + row.ttfb + ' cold=' + row.ttfbCold + '/' + row.ttfbColdMax + ' sql=' + row.sqlN + '/' + row.sqlMs + ' lcp=' + row.lcp + ' cls=' + row.cls + ' inp=' + row.inp
      + ' js=' + row.jsKB + ' css=' + row.cssKB + ' req=' + row.requests + (row.navClick != null ? ' nav=' + row.navClick + '/' + row.navHover : '')
      + (row.lh ? ' lh=' + JSON.stringify(row.lh) : '') + (row.errors.length ? ' ERR=' + row.errors.length : '') + '\n');
  }

  // واجهات صفحة الهوتسبوت على أجهزة MikroTik (تُستدعى من login.html على الجهاز)
  const hs = { key: 'hotspot-api', name: 'واجهة صفحة الهوتسبوت' };
  try {
    // مفتاح جهاز MikroTik (--hotspot-key) أو أول مفتاح يظهر في صفحة ربط الهوتسبوت
    let key = args['hotspot-key'] || '';
    if (!key) {
      const html = await (await ctxs.net.request.get(BASE + T + '/hotspot')).text();
      const m = html.match(/([a-f0-9]{32})\.js/);
      key = m ? m[1] : '';
    }
    if (key) {
      const calls = {
        info: BASE + T + '/api/hotspot/info?key=' + key,
        status: BASE + T + '/api/hotspot/status?key=' + key + '&u=' + (args['hotspot-user'] || 'd0000012') + '&mac=02:00:00:00:00:01',
        config: BASE + T + '/hotspot/sdk/config/' + key + '.js',
        sdk: BASE + T + '/hotspot/sdk/sohaibnet.js',
      };
      for (const [k, u] of Object.entries(calls)) {
        await timedGet(agent, u, '');
        const t = [];
        let bytes = 0;
        let sql = null;
        for (let i = 0; i < RUNS; i++) { const r = await timedGet(agent, u, ''); t.push(r.ttfb); bytes = r.bytes; sql = sqlOf(r.headers) || sql; }
        hs[k] = { ttfb: round(median(t), 1), bytes, sqlN: sql ? sql.n : null, sqlMs: sql ? sql.ms : null };
      }
    } else {
      hs.error = 'no router key (use --hotspot-key)';
    }
  } catch (e) { hs.error = String(e.message || e); }
  process.stderr.write('hotspot-api ' + JSON.stringify(hs) + '\n');

  await browser.close();
  const out = { label: args.label || '', base: BASE, at: new Date().toISOString(), runs: RUNS, pages: results, hotspot: hs };
  const file = args.out || path.join(process.cwd(), 'perf-' + (args.label || 'run') + '.json');
  fs.writeFileSync(file, JSON.stringify(out, null, 2));
  process.stderr.write('saved ' + file + '\n');
  process.exit(0);
})().catch((e) => { console.error(e); process.exit(1); });
