#!/usr/bin/env node
/*
 * البندان 53 و81 في المتصفح: الانتقال بين الصفحات (خيار «انتقال ناعم بين الصفحات» في استوديو التصميم)
 * وتحول البطاقة المضغوطة إلى رأس الصفحة التالية بـ translate فقط. يشغّل الخيار من نموذج التصميم ثم يعيده كما كان.
 *   node tests/browser/transitions.js https://madix.test
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

async function setPageFx(admin, on) {
  await admin.goto(BASE + '/admin/design', { waitUntil: 'load' });
  const box = admin.locator('input[name=page_fx]');
  const was = await box.isChecked();
  if (was !== on) {
    await box.evaluate((el, v) => { el.checked = v; }, on);
    await Promise.all([admin.waitForNavigation(), admin.evaluate(() => document.querySelector('form[data-design-form]').requestSubmit())]);
  }
  return was;
}

async function networkPage(browser, reduced) {
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'ar', viewport: { width: 1280, height: 800 }, reducedMotion: reduced ? 'reduce' : 'no-preference' });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e.message || e)));
  await page.goto(BASE + '/' + TENANT + '/login');
  await page.fill('input[name=username]', USER);
  await page.fill('input[name=password]', PASS);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  // يسجل ما يجري في الصفحة الجديدة: هل جرى انتقال، وحركة مجموعة التحول، وتنظيف الاسم بعده
  await page.addInitScript(() => {
    addEventListener('pagereveal', (e) => {
      const vt = window.__vt = { has: !!e.viewTransition };
      if (!e.viewTransition) { return; }
      e.viewTransition.ready.then(() => new Promise((r) => setTimeout(r, 0))).then(() => {
        const head = document.querySelector('.page-head');
        vt.named = head ? head.style.viewTransitionName : '';
        vt.group = document.getAnimations().filter((a) => a.effect && a.effect.pseudoElement === '::view-transition-group(mnx-morph)')
          .map((a) => a.effect.getKeyframes().map((k) => k.transform));
      });
      e.viewTransition.finished.then(() => new Promise((r) => setTimeout(r, 0))).then(() => {
        const head = document.querySelector('.page-head');
        vt.done = true;
        vt.cleared = head ? head.style.viewTransitionName === '' : true;
      });
    });
  });
  await page.goto(BASE + '/' + TENANT + '/', { waitUntil: 'load' });
  return { ctx, page, errors };
}

(async () => {
  const browser = await chromium.launch({ args: ['--no-proxy-server'] });
  const actx = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'ar' });
  const admin = await actx.newPage();
  await admin.goto(BASE + '/admin/login');
  await admin.fill('input[name=username]', ADMIN);
  await admin.fill('input[name=password]', ADMIN_PASS);
  await Promise.all([admin.waitForNavigation(), admin.click('button[type=submit]')]);

  let was = null;
  try {
    // الخيار مطفأ: لا قاعدة انتقال ولا سكربت تحول (الوضع الافتراضي لا يتغير حجمه)
    await setPageFx(admin, false);
    let n = await networkPage(browser, false);
    let html = await n.page.content();
    ok(!/@view-transition/.test(html) && !/mnx-morph/.test(html) && /data-page-fx="0"/.test(html), 'option off: no transition rule and no morph script');
    await n.ctx.close();

    was = await setPageFx(admin, true);
    console.log('page transitions on');
    n = await networkPage(browser, false);
    html = await n.page.content();
    ok(/@view-transition\{navigation:auto\}/.test(html) && /pagereveal/.test(html), 'option on: transition rule and morph script in the head');
    const card = n.page.locator('a.stat[href]').first();
    const href = await card.getAttribute('href');
    await Promise.all([n.page.waitForNavigation(), card.click()]);
    await n.page.waitForFunction(() => window.__vt && (!window.__vt.has || window.__vt.done), null, { timeout: 5000 });
    const vt = await n.page.evaluate(() => window.__vt);
    ok(vt.has, 'navigation runs a view transition');
    ok(vt.named === 'mnx-morph', 'clicked card and next page header share the morph name');
    const frames = (vt.group && vt.group[0]) || [];
    ok(frames.length === 2 && frames.every((t) => /^translate\([^)]*\)$/.test(t)), 'morph moves with translate only (' + JSON.stringify(frames) + ')');
    ok(vt.cleared, 'morph name removed after the transition');
    ok(new URL(n.page.url()).pathname === new URL(href, BASE).pathname, 'landed on the card link');
    // رابط عادي (القائمة): انتقال بلا تحول
    await n.page.goto(BASE + '/' + TENANT + '/', { waitUntil: 'load' });
    if (!(await n.page.locator('.sidebar .nav-link:visible').count())) { await n.page.click('[data-sidebar-toggle]'); }
    await Promise.all([n.page.waitForNavigation(), n.page.locator('.sidebar .nav-link:not(.is-active):visible').first().click()]);
    await n.page.waitForFunction(() => window.__vt && (!window.__vt.has || window.__vt.done), null, { timeout: 5000 });
    const plain = await n.page.evaluate(() => window.__vt);
    ok(plain.has && !plain.named && (!plain.group || plain.group.length === 0), 'menu link: page transition without the card morph');
    ok(n.errors.length === 0, 'no JavaScript errors' + (n.errors.length ? ': ' + n.errors.join(' | ') : ''));
    await n.ctx.close();

    // تقليل الحركة: لا تحول ولا حركة
    n = await networkPage(browser, true);
    await Promise.all([n.page.waitForNavigation(), n.page.locator('a.stat[href]').first().click()]);
    await n.page.waitForTimeout(400);
    const red = await n.page.evaluate(() => ({ vt: window.__vt, anims: document.getAnimations().filter((a) => a.effect && a.effect.pseudoElement && a.playState === 'running').length }));
    ok(!red.vt || !red.vt.named, 'reduced motion: no card morph');
    ok(red.anims === 0, 'reduced motion: no running transition animations');
    await n.ctx.close();
  } finally {
    if (was !== null) { await setPageFx(admin, was); }
  }
  await browser.close();
  console.log(failed ? `\n${failed} failed` : '\nall passed');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
