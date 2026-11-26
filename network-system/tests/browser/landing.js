#!/usr/bin/env node
/*
 * صفحة الهبوط في المتصفح:
 *   node tests/browser/landing.js http://127.0.0.1:8090
 * المقاسات الستة، روابط التنقل، التمرير السريع حتى النهاية، العرض بلا JavaScript، تقليل الحركة،
 * التباين اللوني للنصوص، ولا تمدد أفقي ولا أخطاء JavaScript، ولا قفز في التصميم (CLS).
 * البند 104 (التصميم الكلاسيكي): القصة بالتمرير — كل فصل يبدّل شاشة النظام الثابتة، على الحاسوب والجوال.
 */
'use strict';

const { chromium } = require('playwright');

const BASE = (process.argv.slice(2).find((a) => /^https?:/.test(a)) || 'http://127.0.0.1:8090').replace(/\/$/, '');
const SIZES = [[360, 740], [390, 844], [414, 896], [768, 1024], [1280, 800], [1920, 1080]];

function lum(rgb) {
  const c = rgb.map((v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
  return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
}

async function audit(page) {
  return page.evaluate(() => {
    const parse = (s) => { const m = s.match(/rgba?\(([\d.]+), ([\d.]+), ([\d.]+)(?:, ([\d.]+))?\)/); return m ? [+m[1], +m[2], +m[3], m[4] === undefined ? 1 : +m[4]] : null; };
    // لون الخلفية الفعلي: أول خلفية معتمة في سلسلة الآباء
    const bgOf = (el) => {
      for (let p = el; p; p = p.parentElement) {
        const s = getComputedStyle(p);
        if (s.backgroundImage && s.backgroundImage !== 'none') { return null; }
        const c = parse(s.backgroundColor);
        if (c && c[3] > 0.9) { return c; }
      }
      return [255, 255, 255, 1];
    };
    const low = [];
    document.querySelectorAll('h1, h2, h3, p, a, li, button, span, small, b').forEach((el) => {
      if (!el.offsetParent || !el.textContent.trim() || el.children.length > 2) { return; }
      const s = getComputedStyle(el);
      if (parseFloat(s.opacity) < 0.6 || s.visibility === 'hidden') { return; }
      const fg = parse(s.color);
      const bg = bgOf(el);
      if (!fg || !bg || fg[3] < 0.5) { return; }
      low.push([el.tagName + '.' + String(el.className).split(' ')[0], fg.slice(0, 3), bg.slice(0, 3), parseFloat(s.fontSize), s.fontWeight]);
    });
    const de = document.documentElement;
    return { overflow: de.scrollWidth - de.clientWidth, colors: low.slice(0, 400), h1: !!document.querySelector('h1'), title: document.title, desc: !!document.querySelector('meta[name=description]') };
  });
}

(async () => {
  const browser = await chromium.launch({ args: ['--ignore-certificate-errors', '--no-proxy-server'] });
  const problems = [];
  for (const [w, h] of SIZES) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, ignoreHTTPSErrors: true, locale: 'ar' });
    const page = await ctx.newPage();
    const errs = [];
    page.on('pageerror', (e) => errs.push(String(e.message || e)));
    page.on('console', (m) => { if (m.type() === 'error') { errs.push(m.text()); } });
    await page.addInitScript(() => { window.__cls = 0; try { new PerformanceObserver((l) => l.getEntries().forEach((e) => { if (!e.hadRecentInput) { window.__cls += e.value; } })).observe({ type: 'layout-shift', buffered: true }); } catch (e) {} });
    const r = await page.goto(BASE + '/', { waitUntil: 'load' });
    if (!r || r.status() !== 200) { problems.push(w + 'px: HTTP ' + (r && r.status())); }
    await page.waitForTimeout(400);
    // تمرير سريع حتى النهاية ثم العودة
    await page.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 700) { window.scrollTo(0, y); await new Promise((res) => setTimeout(res, 16)); } window.scrollTo(0, 0); });
    await page.waitForTimeout(300);
    const a = await audit(page);
    if (a.overflow > 1) { problems.push(w + 'px: horizontal overflow ' + a.overflow + 'px'); }
    if (!a.h1 || !a.title || !a.desc) { problems.push(w + 'px: SEO basics missing (h1/title/description)'); }
    for (const [tag, fg, bg, size, weight] of a.colors) {
      const l1 = lum(fg);
      const l2 = lum(bg);
      const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
      const large = size >= 24 || (size >= 18.6 && +weight >= 700);
      if (ratio < (large ? 3 : 4.5) - 0.05) { problems.push(w + 'px: low contrast ' + ratio.toFixed(2) + ' ' + tag); break; }
    }
    // روابط القائمة داخل الصفحة تعمل
    const anchors = await page.$$eval('a[href^="#"]', (xs) => xs.map((x) => x.getAttribute('href')).filter((h) => h.length > 1));
    for (const href of anchors.slice(0, 4)) {
      const ok = await page.evaluate((hh) => !!document.querySelector(hh), href).catch(() => false);
      if (!ok) { problems.push(w + 'px: broken anchor ' + href); }
    }
    const cls = await page.evaluate(() => window.__cls);
    if (cls > 0.05) { problems.push(w + 'px: CLS ' + cls.toFixed(3)); }
    errs.forEach((e) => problems.push(w + 'px: ' + e.slice(0, 200)));
    await ctx.close();
  }
  // البند 104: القصة بالتمرير (عند وجودها في التصميم المختار)
  for (const [w, h] of [[1280, 800], [390, 844]]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, ignoreHTTPSErrors: true, locale: 'ar' });
    const page = await ctx.newPage();
    await page.goto(BASE + '/', { waitUntil: 'load' });
    const mids = await page.$$eval('[data-lp-chapter]', (xs) => xs.map((x) => x.getBoundingClientRect().top + scrollY + x.offsetHeight / 2));
    const got = [];
    for (let i = 0; i < mids.length; i++) {
      await page.evaluate((y) => window.scrollTo(0, y - innerHeight * 0.55), mids[i]);
      await page.waitForTimeout(450);
      got.push(await page.evaluate(() => { const s = document.querySelector('.lp-scr.is-on'); const c = document.querySelector('.lp-chapter.is-on'); return s && c ? s.getAttribute('data-scr') + '/' + c.getAttribute('data-lp-chapter') : '-'; }));
    }
    const want = mids.map((_, i) => i + '/' + i);
    if (mids.length && got.join(',') !== want.join(',')) { problems.push(w + 'px: story screens ' + got.join(',') + ' (want ' + want.join(',') + ')'); }
    if (mids.length) {
      const stuck = await page.evaluate(() => { const st = document.querySelector('.lp-story-stage'); return getComputedStyle(st).position; });
      if (stuck !== 'sticky') { problems.push(w + 'px: story window not sticky'); }
    }
    console.log('  story ' + w + 'px: ' + (mids.length ? mids.length + ' chapters → ' + got.join(' ') : 'not in this design'));
    await ctx.close();
  }
  // بلا JavaScript: المحتوى كله ظاهر
  const nojs = await browser.newContext({ javaScriptEnabled: false, ignoreHTTPSErrors: true, viewport: { width: 390, height: 844 } });
  const p2 = await nojs.newPage();
  await p2.goto(BASE + '/');
  const hidden = await p2.evaluate(() => Array.from(document.querySelectorAll('section, h2')).filter((el) => { const s = getComputedStyle(el); return s.opacity === '0' || s.visibility === 'hidden'; }).length);
  if (hidden) { problems.push('no-JS: ' + hidden + ' sections hidden'); }
  await nojs.close();
  // تقليل الحركة: لا حركات طويلة
  const rm = await browser.newContext({ reducedMotion: 'reduce', ignoreHTTPSErrors: true });
  const p3 = await rm.newPage();
  await p3.goto(BASE + '/');
  const long = await p3.evaluate(() => document.getAnimations().filter((an) => { const t = an.effect && an.effect.getComputedTiming(); return t && (t.duration > 300 || t.iterations === Infinity); }).length);
  if (long) { problems.push('reduced motion: ' + long + ' long or infinite animations still running'); }
  await rm.close();
  await browser.close();
  if (problems.length) {
    console.log(problems.map((p) => '✘ ' + p).join('\n'));
  }
  console.log('landing: ' + SIZES.length + ' sizes, no-JS, reduced motion, contrast — ' + (problems.length ? problems.length + ' problems' : 'all clean'));
  process.exit(problems.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
