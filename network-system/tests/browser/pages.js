#!/usr/bin/env node
/*
 * فحص كل صفحات الموقع في المتصفح:
 *   node tests/browser/pages.js http://127.0.0.1:8090 [--sizes=360,1280] [--theme=light|dark|black] [--quick]
 * بيانات الدخول: MNX_TENANT (اسم الحساب)، MNX_USER/MNX_PASS (مالك الشبكة)، MNX_ADMIN/MNX_ADMIN_PASS (مسؤول المنصة).
 *
 * لكل صفحة وكل مقاس: لا أخطاء JavaScript، لا نص خطأ PHP، لا تمدد أفقي للصفحة، الصفحة قابلة للتمرير،
 * ولا عنصر أعرض من الشاشة خارج حاويات التمرير المقصودة.
 */
'use strict';

const { chromium } = require('playwright');

const argv = process.argv.slice(2);
const BASE = (argv.find((a) => /^https?:/.test(a)) || 'http://127.0.0.1:8090').replace(/\/$/, '');
const opt = Object.fromEntries(argv.filter((a) => a.startsWith('--')).map((a) => { const [k, v] = a.slice(2).split('='); return [k, v === undefined ? '1' : v]; }));
const TENANT = process.env.MNX_TENANT || 'demo';
const USER = process.env.MNX_USER || 'owner';
const PASS = process.env.MNX_PASS || 'OwnerPass123';
const ADMIN = process.env.MNX_ADMIN || 'admin';
const ADMIN_PASS = process.env.MNX_ADMIN_PASS || 'AdminPass123';
const ALL_SIZES = [[360, 740], [390, 844], [414, 896], [768, 1024], [1280, 800], [1920, 1080]];
const sizes = opt.sizes ? ALL_SIZES.filter(([w]) => opt.sizes.split(',').includes(String(w))) : ALL_SIZES;
const THEME = opt.theme || 'light';
// أخطاء متوقعة في بيئة بلا إنترنت: مربعات الخريطة من الإنترنت
// وأدوات الفحص الحي التي تتصل بجهاز MikroTik بعد فتح الصفحة (أجهزة البيانات التجريبية بعناوين لا تصل لأي جهاز)
const IGNORE = [/tile\.openstreetmap|arcgisonline|tile\.|basemaps|ERR_NAME_NOT_RESOLVED|ERR_INTERNET_DISCONNECTED|ERR_TUNNEL_CONNECTION_FAILED|ERR_PROXY/i,
  /status of 422.*\/cables\/ports/];

async function login(browser, kind) {
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'ar', colorScheme: THEME === 'light' ? 'light' : 'dark' });
  const page = await ctx.newPage();
  if (kind === 'admin') {
    await page.goto(BASE + '/admin/login');
    await page.fill('input[name=username]', ADMIN);
    await page.fill('input[name=password]', ADMIN_PASS);
  } else {
    await page.goto(BASE + '/' + TENANT + '/login');
    await page.fill('input[name=username]', USER);
    await page.fill('input[name=password]', PASS);
  }
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  if (/\/login/.test(page.url())) { throw new Error('login failed: ' + kind); }
  if (kind === 'net' && THEME !== 'light') {
    // الوضع المختار يُحفظ للمستخدم على الخادم
    await page.evaluate(async (t) => {
      const csrf = document.querySelector('meta[name="csrf-token"]').content;
      const base = document.querySelector('meta[name="base-url"]').content;
      await fetch(base + '/account/theme', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, Accept: 'application/json' }, body: JSON.stringify({ theme: t }) });
    }, THEME);
  }
  return { ctx, page };
}

async function links(page, sel) {
  return page.$$eval(sel, (as) => Array.from(new Set(as.map((a) => a.getAttribute('href')).filter((h) => h && h.charAt(0) === '/' && !/logout|export|download|print|\/lang\//.test(h)))));
}

async function check(page, url, w, h) {
  const errors = [];
  const onErr = (e) => errors.push('pageerror: ' + (e.message || e));
  const onCon = (m) => { if (m.type() === 'error' && !IGNORE.some((r) => r.test(m.text() + ' ' + (m.location() || {}).url))) { errors.push('console: ' + m.text().slice(0, 200) + ' ' + String((m.location() || {}).url || '').replace(BASE, '')); } };
  page.on('pageerror', onErr);
  page.on('console', onCon);
  await page.setViewportSize({ width: w, height: h });
  let status = 0;
  try {
    const r = await page.goto(BASE + url, { waitUntil: 'load', timeout: 45000 });
    status = r ? r.status() : 0;
    await page.waitForTimeout(350);
  } catch (e) {
    errors.push('navigation: ' + e.message.split('\n')[0]);
  }
  const res = await page.evaluate(() => {
    const out = { php: '', overflow: 0, wide: [], scroll: true };
    const txt = document.body ? document.body.innerText : '';
    const m = txt.match(/(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Stack trace)/);
    if (m) { out.php = m[1]; }
    const de = document.documentElement;
    out.overflow = de.scrollWidth - de.clientWidth;
    // عناصر تتجاوز عرض الشاشة وليست داخل حاوية تمرير أفقي مقصودة
    const vw = de.clientWidth;
    const scrollers = (el) => { for (let p = el.parentElement; p; p = p.parentElement) { const s = getComputedStyle(p); if (/(auto|scroll|hidden|clip)/.test(s.overflowX)) { return true; } } return false; };
    document.querySelectorAll('body *').forEach((el) => {
      const r = el.getBoundingClientRect();
      if (r.width && (r.right > vw + 2 || r.left < -2) && getComputedStyle(el).position !== 'fixed' && !scrollers(el)) {
        if (out.wide.length < 5) { out.wide.push(el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '') + ' ' + Math.round(r.left) + '..' + Math.round(r.right)); }
      }
    });
    const bs = getComputedStyle(document.body);
    out.scroll = !(bs.overflowY === 'hidden' && de.scrollHeight > de.clientHeight + 5 && !document.querySelector('dialog[open]'));
    return out;
  }).catch((e) => ({ php: '', overflow: 0, wide: [], scroll: true, evalError: e.message }));
  page.off('pageerror', onErr);
  page.off('console', onCon);
  const problems = [];
  if (status >= 500) { problems.push('HTTP ' + status); }
  if (res.php) { problems.push('PHP text: ' + res.php); }
  if (res.overflow > 1) { problems.push('horizontal overflow ' + res.overflow + 'px'); }
  if (res.wide.length) { problems.push('too wide: ' + res.wide.join(', ')); }
  if (!res.scroll) { problems.push('page cannot scroll'); }
  return problems.concat(errors);
}

(async () => {
  const browser = await chromium.launch({ args: ['--ignore-certificate-errors', '--no-proxy-server'] });
  const net = await login(browser, 'net');
  const adm = await login(browser, 'admin');
  const guest = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'ar' });
  const gpage = await guest.newPage();

  await net.page.goto(BASE + '/' + TENANT + '/');
  let netLinks = await links(net.page, '.sidebar a[href], .topbar a[href]');
  await adm.page.goto(BASE + '/admin');
  let admLinks = await links(adm.page, '.sidebar a[href], .admin-nav a[href], nav a[href]');
  admLinks = admLinks.filter((h) => h.startsWith('/admin'));
  const guestLinks = ['/', '/login', '/signup', '/admin/login', '/' + TENANT + '/login'];
  if (opt.quick) { netLinks = netLinks.slice(0, 12); admLinks = admLinks.slice(0, 6); }

  const plan = [];
  for (const [w, h] of sizes) {
    for (const u of netLinks) { plan.push(['net', u, w, h]); }
    for (const u of admLinks) { plan.push(['admin', u, w, h]); }
    for (const u of guestLinks) { plan.push(['guest', u, w, h]); }
  }
  const pages = { net: net.page, admin: adm.page, guest: gpage };
  let fails = 0;
  let n = 0;
  for (const [kind, u, w, h] of plan) {
    const p = await check(pages[kind], u, w, h);
    n++;
    if (p.length) {
      fails++;
      console.log('✘ ' + w + 'px ' + kind + ' ' + u + '\n    ' + p.join('\n    '));
    }
  }
  console.log('\n' + n + ' page views (' + netLinks.length + ' network, ' + admLinks.length + ' admin, ' + guestLinks.length + ' public) × ' + sizes.map((s) => s[0]).join('/') + 'px, theme ' + THEME + ': ' + (fails ? fails + ' with problems' : 'all clean'));
  await browser.close();
  process.exit(fails ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
