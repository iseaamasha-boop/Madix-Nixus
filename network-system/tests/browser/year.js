#!/usr/bin/env node
/*
 * البند 106 في المتصفح: ملخص السنة.
 *   node tests/browser/year.js https://madix.test
 * - العرض القصصي ينتقل بالنقر وبالأسهم، والأرقام تعدّ حتى قيمتها، وبلا تقدّم تلقائي مع تقليل الحركة.
 * - «حفظ صورة» يُنزّل صورة PNG بمقاس 1080×1350 تُرسم في المتصفح.
 * - بلا أخطاء JavaScript، وبلا تمدد أفقي على الجوال.
 */
'use strict';

const { chromium } = require('playwright');
const fs = require('fs');
const os = require('os');
const path = require('path');

const argv = process.argv.slice(2);
const BASE = (argv.find((a) => /^https?:/.test(a)) || 'http://127.0.0.1:8090').replace(/\/$/, '');
const TENANT = process.env.MNX_TENANT || 'demo';
let failed = 0;
function ok(cond, msg) {
  console.log((cond ? '  ✔ ' : '  ✘ ') + msg);
  if (!cond) { failed++; }
}
async function open(browser, opts) {
  const ctx = await browser.newContext(Object.assign({ ignoreHTTPSErrors: true, locale: 'ar', acceptDownloads: true }, opts));
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e.message || e)));
  await page.goto(BASE + '/' + TENANT + '/login');
  await page.fill('input[name=username]', process.env.MNX_USER || 'owner');
  await page.fill('input[name=password]', process.env.MNX_PASS || 'OwnerPass123');
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  await page.goto(BASE + '/' + TENANT + '/reports/year', { waitUntil: 'load' });
  return { ctx, page, errors };
}

(async () => {
  const browser = await chromium.launch({ args: ['--no-proxy-server'] });
  const a = await open(browser, { viewport: { width: 1280, height: 900 } });
  const p = a.page;
  const on = () => p.evaluate(() => [...document.querySelectorAll('[data-slide]')].findIndex((s) => s.classList.contains('is-on')));
  ok(await on() === 0, 'starts at the first slide');
  await p.click('[data-year-next]');
  await p.waitForTimeout(450);
  ok(await on() === 1, 'click moves to the next slide');
  const count = await p.evaluate(() => { const el = document.querySelector('.year-slide.is-on [data-count]'); return el ? [el.textContent.replace(/[^0-9]/g, ''), el.getAttribute('data-count')] : null; });
  ok(count && count[0] === count[1], 'number counted up to its value (' + (count ? count.join(' = ') : '-') + ')');
  await p.keyboard.press('ArrowLeft');
  await p.waitForTimeout(400);
  ok(await on() === 2, 'arrow key (RTL: left = next)');
  await p.click('[data-year-prev]');
  await p.waitForTimeout(400);
  ok(await on() === 1, 'back to the previous slide');
  const [dl] = await Promise.all([p.waitForEvent('download'), p.click('[data-year-save]')]);
  const file = path.join(os.tmpdir(), 'mnx-year-' + process.pid + '.png');
  await dl.saveAs(file);
  const buf = fs.readFileSync(file);
  const png = buf.slice(1, 4).toString() === 'PNG';
  const w = buf.readUInt32BE(16), h = buf.readUInt32BE(20);
  ok(png && w === 1080 && h === 1350, 'image saved as PNG 1080×1350 (' + w + '×' + h + ', ' + Math.round(buf.length / 1024) + ' KB)');
  fs.unlinkSync(file);
  ok(a.errors.length === 0, 'no JavaScript errors' + (a.errors.length ? ': ' + a.errors.join(' | ') : ''));
  await a.ctx.close();

  // الجوال وتقليل الحركة: بلا تقدّم تلقائي وبلا تمدد أفقي
  const m = await open(browser, { viewport: { width: 360, height: 740 }, reducedMotion: 'reduce' });
  const idx = await m.page.evaluate(() => [...document.querySelectorAll('[data-slide]')].findIndex((s) => s.classList.contains('is-on')));
  await m.page.waitForTimeout(5600);
  const idx2 = await m.page.evaluate(() => [...document.querySelectorAll('[data-slide]')].findIndex((s) => s.classList.contains('is-on')));
  ok(idx === 0 && idx2 === 0, 'reduced motion: no auto advance');
  const overflow = await m.page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  ok(overflow <= 1, '360px: no horizontal overflow (' + overflow + ')');
  ok(m.errors.length === 0, 'no JavaScript errors on mobile');
  await m.ctx.close();
  await browser.close();
  console.log(failed ? `\n${failed} failed` : '\nall passed');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
