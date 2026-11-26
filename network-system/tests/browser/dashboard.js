#!/usr/bin/env node
/*
 * البند 108 في المتصفح: لوحة التحكم على ذوق الموظف والمسؤول.
 *   node tests/browser/dashboard.js https://madix.test
 * - سكربت التخصيص لا يُحمَّل مع الصفحة، فقط عند الضغط على «تخصيص».
 * - نقل بطاقة بلوحة المفاتيح ولوحة بالفأرة، وتغيير مقاس، وحفظ: الترتيب يبقى بعد إعادة التحميل بلا قفزة (CLS)، و«إلغاء» يعيد ما قبله، و«الأصل» يرجع الأصل.
 * - لوحة المسؤول بالطريقة نفسها، ونموذج «ترتيب لوحة تحكم الأنظمة» في استوديو التصميم يكتب الترتيب في حقله.
 * يُعيد كل شيء إلى الأصل في النهاية.
 */
'use strict';

const { chromium } = require('playwright');

const argv = process.argv.slice(2);
const BASE = (argv.find((a) => /^https?:/.test(a)) || 'http://127.0.0.1:8090').replace(/\/$/, '');
const TENANT = process.env.MNX_TENANT || 'demo';
const USER = process.env.MNX_USER || 'owner';
const PASS = process.env.MNX_PASS || 'OwnerPass123';
const ADMIN = process.env.MNX_ADMIN || 'admin';
const ADMIN_PASS = process.env.MNX_ADMIN_PASS || 'AdminPass123';

let failed = 0;
function ok(cond, msg) {
  console.log((cond ? '  ✔ ' : '  ✘ ') + msg);
  if (!cond) { failed++; }
}

const order = (page, g) => page.evaluate((g) => [...document.querySelectorAll('[data-dash-group="' + g + '"] > [data-dash]')].map((e) => e.dataset.dash + ':' + ((/dsz-(\w)/.exec(e.className) || [])[1])), g);
const cls = (page) => page.evaluate(() => new Promise((res) => {
  let v = 0;
  new PerformanceObserver((l) => { l.getEntries().forEach((e) => { if (!e.hadRecentInput) { v += e.value; } }); }).observe({ type: 'layout-shift', buffered: true });
  setTimeout(() => res(v), 1200);
}));

async function editFlow(page, home, label) {
  let loads = 0;
  page.on('request', (r) => { if (/dash-edit\.js/.test(r.url())) { loads++; } });
  await page.goto(home, { waitUntil: 'load' });
  const s0 = await order(page, 'stats'), p0 = await order(page, 'panels');
  ok(loads === 0 && s0.length > 0 && p0.length > 0, label + ': blocks drawn, editing script not loaded with the page');
  await page.click('[data-dash-edit]');
  await page.waitForSelector('.dash-bar');
  ok(loads === 1 && (await page.$$('.dash-tools')).length === s0.length + p0.length, label + ': editing script on demand, tools on every block');
  // لوحة المفاتيح: البطاقة الأولى خطوة للأمام
  await page.focus('[data-dash-group="stats"] > [data-dash]:first-child [data-dash-drag]');
  await page.keyboard.press('Alt+ArrowDown');
  // الفأرة: اللوحة الأخيرة فوق الثالثة (المقبض في وسط الشاشة، ونقطة الإفلات داخل الجزء الظاهر من الهدف)
  await page.evaluate(() => document.querySelector('[data-dash-group="panels"] > [data-dash]:last-child [data-dash-drag]').scrollIntoView({ block: 'center', behavior: 'instant' }));
  await page.waitForTimeout(150);
  const h = await page.$('[data-dash-group="panels"] > [data-dash]:last-child [data-dash-drag]');
  const target = await page.$('[data-dash-group="panels"] > [data-dash]:nth-child(3)') || await page.$('[data-dash-group="panels"] > [data-dash]:first-child');
  const hb = await h.boundingBox(), tb = await target.boundingBox();
  const vh = page.viewportSize().height;
  const ty = Math.min(Math.max(tb.y + 70, 90), Math.min(tb.y + tb.height - 20, vh - 90));
  await page.mouse.move(hb.x + hb.width / 2, hb.y + hb.height / 2);
  await page.mouse.down();
  for (let i = 1; i <= 12; i++) { await page.mouse.move(hb.x + (tb.x + tb.width / 2 - hb.x) * i / 12, hb.y + (ty - hb.y) * i / 12); await page.waitForTimeout(25); }
  await page.mouse.up();
  await page.waitForTimeout(250);
  // المقاس: اللوحة الأولى تكبر خطوة
  await page.click('[data-dash-group="panels"] > [data-dash]:first-child [data-dash-size]');
  await page.waitForTimeout(250);
  const s1 = await order(page, 'stats'), p1 = await order(page, 'panels');
  ok(s1[0] === s0[1] && s1[1] === s0[0], label + ': keyboard moved a card');
  ok(p1.indexOf(p0[p0.length - 1]) < p0.length - 1, label + ': mouse moved a panel');
  ok(p1.some((x, i) => x !== p0[i] && x.split(':')[0] === p0[0].split(':')[0]) || p1.join() !== p0.join(), label + ': size changed');
  await page.click('[data-dash-done]');
  await page.waitForSelector('.dash-bar', { state: 'detached' });
  await page.reload({ waitUntil: 'load' });
  ok(JSON.stringify(await order(page, 'stats')) === JSON.stringify(s1) && JSON.stringify(await order(page, 'panels')) === JSON.stringify(p1), label + ': layout kept after reload (drawn by the server)');
  const shift = await cls(page);
  ok(shift < 0.01, label + ': no layout shift with a saved layout (' + shift.toFixed(4) + ')');
  // إلغاء
  await page.click('[data-dash-edit]');
  await page.waitForSelector('.dash-bar');
  await page.focus('[data-dash-group="stats"] > [data-dash]:first-child [data-dash-drag]');
  await page.keyboard.press('Alt+ArrowDown');
  await page.click('[data-dash-cancel]');
  ok(JSON.stringify(await order(page, 'stats')) === JSON.stringify(s1), label + ': cancel restores');
  // الأصل
  await page.click('[data-dash-edit]');
  await page.waitForSelector('.dash-bar');
  await Promise.all([page.waitForNavigation(), page.click('[data-dash-default]')]);
  ok(JSON.stringify(await order(page, 'stats')) === JSON.stringify(s0) && JSON.stringify(await order(page, 'panels')) === JSON.stringify(p0), label + ': default restores the original');
}

(async () => {
  const browser = await chromium.launch({ args: ['--no-proxy-server'] });
  const errors = [];
  const net = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'ar', viewport: { width: 1280, height: 900 } });
  const np = await net.newPage();
  np.on('pageerror', (e) => errors.push(String(e.message || e)));
  await np.goto(BASE + '/' + TENANT + '/login');
  await np.fill('input[name=username]', USER);
  await np.fill('input[name=password]', PASS);
  await Promise.all([np.waitForNavigation(), np.click('button[type=submit]')]);
  console.log('network dashboard');
  await editFlow(np, BASE + '/' + TENANT + '/', 'network');

  const adm = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'ar', viewport: { width: 1280, height: 900 } });
  const ap = await adm.newPage();
  ap.on('pageerror', (e) => errors.push(String(e.message || e)));
  await ap.goto(BASE + '/admin/login');
  await ap.fill('input[name=username]', ADMIN);
  await ap.fill('input[name=password]', ADMIN_PASS);
  await Promise.all([ap.waitForNavigation(), ap.click('button[type=submit]')]);
  console.log('admin dashboard');
  await editFlow(ap, BASE + '/admin', 'admin');

  console.log('design studio: systems dashboard');
  await ap.goto(BASE + '/admin/design#dash', { waitUntil: 'load' });
  await ap.waitForSelector('#dash .dash-tools');
  const before = await ap.inputValue('#dash input[name=layout]');
  await ap.focus('#dash [data-dash-group="panels"] > [data-dash]:first-child [data-dash-drag]');
  await ap.keyboard.press('Alt+ArrowDown');
  await ap.click('#dash [data-dash-group="stats"] > [data-dash]:first-child [data-dash-size]');
  const after = JSON.parse(await ap.inputValue('#dash input[name=layout]'));
  ok(after.panels[1][0] === JSON.parse(before).panels[0][0] && after.stats[0][1] === 'w', 'form field follows the arrangement');
  ok(errors.length === 0, 'no JavaScript errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  await browser.close();
  console.log(failed ? `\n${failed} failed` : '\nall passed');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
