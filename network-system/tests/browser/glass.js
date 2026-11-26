#!/usr/bin/env node
/*
 * البنود 46 و54 و64 في المتصفح، كما في نموذج صاحب المشروع:
 *   node tests/browser/glass.js https://madix.test
 * - «بطاقات زجاجية» من استوديو التصميم: مسرح داكن بهالات تتحرك، بطاقات شفافة بنص أبيض، ترتفع وتُضاء تحت الفأرة واللمس،
 *   والتمويه 16px على الأجهزة القادرة، في الفاتح والداكن والأسود، ومع تقليل الحركة كل شيء ساكن.
 * - أيقونات القائمة: رسم الأيقونة يتحرك بحركة من معناها عند المرور وعند فتح القائمة على الجوال، ومربّعها ثابت.
 * - التموج على روابط القائمة.
 * يُعاد خيار الزجاج في النهاية إلى ما كان عليه.
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

async function setGlass(page, on) {
  await page.goto(BASE + '/admin/design', { waitUntil: 'load' });
  const was = await page.evaluate(() => document.querySelector('input[name=glass]').checked);
  if (was !== on) {
    await Promise.all([page.waitForNavigation(), page.evaluate((v) => {
      const box = document.querySelector('input[name=glass]');
      box.checked = v;
      box.form.requestSubmit();
    }, on)]);
  }
  return was;
}

async function owner(browser, opts) {
  const ctx = await browser.newContext(Object.assign({ ignoreHTTPSErrors: true, locale: 'ar' }, opts));
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e.message || e)));
  await page.goto(BASE + '/' + TENANT + '/login');
  await page.fill('input[name=username]', USER);
  await page.fill('input[name=password]', PASS);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  await page.goto(BASE + '/' + TENANT + '/', { waitUntil: 'load' });
  return { ctx, page, errors };
}

const stage = () => {
  const st = document.querySelector('.stats, .kpi-row');
  const card = st.querySelector('.stat');
  const cs = getComputedStyle(card);
  return {
    glass: document.documentElement.getAttribute('data-glass'),
    fx: document.documentElement.classList.contains('fx-blur'),
    glow: getComputedStyle(st, '::before').animationName,
    glowShown: getComputedStyle(st, '::before').display !== 'none',
    color: cs.color,
    bg: cs.backgroundColor,
    image: cs.backgroundImage,
    blur: cs.backdropFilter || cs.webkitBackdropFilter || 'none',
  };
};

(async () => {
  const browser = await chromium.launch({ args: ['--no-proxy-server'] });
  const adm = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'ar', viewport: { width: 1280, height: 900 } });
  const ap = await adm.newPage();
  await ap.goto(BASE + '/admin/login');
  await ap.fill('input[name=username]', ADMIN);
  await ap.fill('input[name=password]', ADMIN_PASS);
  await Promise.all([ap.waitForNavigation(), ap.click('button[type=submit]')]);
  const was = await setGlass(ap, true);
  try {
    // الحاسوب: المسرح والبطاقات والمرور
    console.log('desktop');
    const d = await owner(browser, { viewport: { width: 1280, height: 900 }, colorScheme: 'light' });
    let s = await d.page.evaluate(stage);
    ok(s.glass === '1', 'design studio option reaches the network');
    ok(s.glow === 'glass-float' && s.glowShown, 'stage glows float behind the cards');
    ok(s.color === 'rgb(255, 255, 255)' && /linear-gradient/.test(s.image), 'cards are see-through with white text');
    ok(s.fx ? /blur\(16px\)/.test(s.blur) : s.blur === 'none', 'blur 16px on capable devices only (' + (s.fx ? 'capable' : 'not capable') + ': ' + s.blur + ')');
    const card = d.page.locator('.stats .stat, .kpi-row .stat').first();
    await card.hover();
    await d.page.waitForTimeout(500);
    const lift = await card.evaluate((el) => ({ t: getComputedStyle(el).transform, lit: el.classList.contains('is-lit') }));
    ok(/matrix\(1, 0, 0, 1, 0, -4\)/.test(lift.t) && lift.lit, 'card lifts and lights under the mouse (' + lift.t + ')');
    // القائمة: يتحرك رسم الأيقونة لا مربّعها
    if (await d.page.evaluate(() => document.body.classList.contains('sidebar-hidden'))) {
      await d.page.evaluate(() => document.body.classList.remove('sidebar-hidden'));
      await d.page.waitForTimeout(400);
    }
    const links = d.page.locator('.sidebar .nav-link:not(.is-active):visible');
    const seen = new Set();
    const n = Math.min(await links.count(), 14);
    for (let i = 0; i < n; i++) {
      const l = links.nth(i);
      await l.hover();
      await d.page.waitForTimeout(100);
      const r = await l.evaluate((el) => {
        const ic = el.querySelector('.icon');
        const u = ic.querySelector('use');
        const kind = (ic.getAttribute('class').match(/ni-(\w+)/) || [])[1];
        return { kind, glyph: getComputedStyle(u).animationName, glyphT: getComputedStyle(u).transform, tile: getComputedStyle(ic).animationName };
      });
      if (!r.kind || seen.has(r.kind)) { continue; }
      seen.add(r.kind);
      const moving = r.kind === 'spin' ? r.glyphT !== 'none' : r.glyph === 'ni-' + r.kind;
      ok(moving && r.tile === 'none', 'menu icon "' + r.kind + '" moves its drawing, tile still (' + (r.kind === 'spin' ? r.glyphT : r.glyph) + ')');
    }
    ok(seen.size >= 3, 'several icon motions in the menu (' + [...seen].join(', ') + ')');
    // التموج على رابط القائمة
    const nl = links.first();
    const nb = await nl.boundingBox();
    await d.page.mouse.move(nb.x + nb.width / 2, nb.y + nb.height / 2);
    await d.page.mouse.down();
    const rip = await nl.evaluate((el) => { const r = el.querySelector('.ripple-wrap .ripple'); return r ? getComputedStyle(r).animationName : null; });
    await d.page.mouse.move(5, 5);
    ok(rip === 'ripple', 'ripple on a menu link');
    // الداكن والأسود
    await d.page.evaluate(() => { document.documentElement.setAttribute('data-theme', 'dark'); });
    s = await d.page.evaluate(stage);
    ok(s.color === 'rgb(255, 255, 255)' && s.glowShown, 'dark: same stage and white text');
    await d.page.evaluate(() => { document.documentElement.setAttribute('data-theme-pref', 'black'); });
    s = await d.page.evaluate(stage);
    ok(s.bg === 'rgb(0, 0, 0)' && !s.glowShown, 'black: black cards, no glows');
    ok(d.errors.length === 0, 'no JavaScript errors' + (d.errors.length ? ': ' + d.errors.join(' | ') : ''));
    // كل صفحة فيها بطاقات أرقام: كل بطاقة داخل المسرح الداكن، وكل نص فيها فاتح (لا نص أبيض على صفحة فاتحة ولا نص داكن على المسرح)
    await d.page.evaluate(() => { document.documentElement.setAttribute('data-theme', 'light'); document.documentElement.removeAttribute('data-theme-pref'); });
    const pages = ['/', '/routers', '/sessions/online', '/subscribers', '/sales', '/expenses', '/cards/monitor', '/cards/accounts', '/hotspot', '/power', '/outages', '/maintenance', '/inventory', '/usage', '/tunnels', '/cables', '/distributors', '/reports', '/sms/log', '/shortener'];
    const scan = () => {
      const lum = (c) => { const m = c.match(/[\d.]+/g).map(Number); const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(m[0]) + 0.7152 * f(m[1]) + 0.0722 * f(m[2]); };
      const cards = [...document.querySelectorAll('.stat')].filter((s) => s.getClientRects().length);
      const outside = cards.filter((s) => !s.closest('.stats, .kpi-row')).length;
      let darkest = 1, what = '';
      cards.forEach((s) => s.querySelectorAll('*').forEach((el) => {
        if (![...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim())) { return; }
        const l = lum(getComputedStyle(el).color);
        if (l < darkest) { darkest = l; what = el.className + ' ' + el.textContent.trim().slice(0, 20); }
      }));
      return { n: cards.length, outside, darkest, what };
    };
    let total = 0, bad = [];
    for (const u of pages) {
      const res = await d.page.goto(BASE + '/' + TENANT + u, { waitUntil: 'load' });
      if (!res || res.status() !== 200) { continue; }
      const r2 = await d.page.evaluate(scan);
      if (!r2.n) { continue; }
      total += r2.n;
      if (r2.outside || r2.darkest < 0.35) { bad.push(u + ' (' + r2.outside + ' outside, darkest ' + r2.darkest.toFixed(2) + ' ' + r2.what + ')'); }
    }
    ok(total > 20 && bad.length === 0, 'number cards on ' + pages.length + ' pages: all on the stage with light text (' + total + ' cards)' + (bad.length ? ': ' + bad.join(' | ') : ''));
    await d.ctx.close();
    // لوحة المنصة بالشرط نفسه
    total = 0; bad = [];
    const adminPages = ['/admin', '/admin/payments', '/admin/insights', '/admin/ai', '/admin/mail', '/admin/demo'];
    for (const u of adminPages) {
      const res = await ap.goto(BASE + u, { waitUntil: 'load' });
      if (!res || res.status() !== 200) { continue; }
      const r2 = await ap.evaluate(scan);
      if (!r2.n) { continue; }
      total += r2.n;
      if (r2.outside || r2.darkest < 0.35) { bad.push(u + ' (' + r2.outside + ' outside, darkest ' + r2.darkest.toFixed(2) + ' ' + r2.what + ')'); }
    }
    ok(total > 5 && bad.length === 0, 'platform admin: number cards on the stage with light text (' + total + ' cards)' + (bad.length ? ': ' + bad.join(' | ') : ''));

    // الجوال: اللمس يرفع البطاقة ثم تعود، وفتح القائمة يحرّك الأيقونات بالتتابع
    console.log('phone');
    const m = await owner(browser, { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    // البطاقة رابط غالبًا، فيُكتفى بلمسة بلا رفع إصبع حتى لا تُفتح صفحتها
    const mc = m.page.locator('.stats .stat, .kpi-row .stat').first();
    await mc.scrollIntoViewIfNeeded();
    const mb = await mc.boundingBox();
    await mc.dispatchEvent('pointerdown', { pointerType: 'touch', isPrimary: true, bubbles: true, clientX: mb.x + mb.width / 2, clientY: mb.y + mb.height / 2 });
    await m.page.waitForTimeout(80);
    const tapped = await mc.evaluate((el) => el.classList.contains('is-lit'));
    await m.page.waitForTimeout(900);
    const after = await mc.evaluate((el) => el.classList.contains('is-lit'));
    ok(tapped && !after, 'touch lights the card, then it settles');
    await m.page.evaluate(() => window.scrollTo(0, 0));
    await m.page.click('[data-sidebar-toggle]');
    await m.page.waitForTimeout(40);
    const open = await m.page.evaluate(() => [...document.querySelectorAll('.sidebar .nav-group-inner > .nav-link .icon > use')].slice(0, 6).map((u) => [getComputedStyle(u).animationName, parseFloat(getComputedStyle(u).animationDelay)]));
    ok(open.length > 2 && open.every((x) => /^ni-/.test(x[0])), 'opening the menu moves the icons (' + open.map((x) => x[0]).join(' ') + ')');
    ok(open.some((x) => x[1] > 0) && open.every((x) => x[1] <= 0.25), 'one after another, short delays');
    ok(await m.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), '390px: no horizontal overflow');
    ok(m.errors.length === 0, 'no JavaScript errors on the phone');
    await m.ctx.close();

    // تقليل الحركة: المسرح ساكن، والبطاقة لا ترتفع، والأيقونات لا تتحرك
    console.log('reduced motion');
    const r = await owner(browser, { viewport: { width: 1280, height: 900 }, reducedMotion: 'reduce' });
    s = await r.page.evaluate(stage);
    ok(s.glow === 'none', 'stage glows still');
    const rc = r.page.locator('.stats .stat, .kpi-row .stat').first();
    await rc.hover();
    await r.page.waitForTimeout(300);
    ok(await rc.evaluate((el) => getComputedStyle(el).transform === 'none'), 'cards do not move');
    await r.page.evaluate(() => document.body.classList.remove('sidebar-hidden'));
    const rl = r.page.locator('.sidebar .nav-link:not(.is-active):visible').first();
    await rl.hover();
    ok(await rl.evaluate((el) => getComputedStyle(el.querySelector('.icon > use')).animationName === 'none'), 'menu icons still');
    ok(r.errors.length === 0, 'no JavaScript errors with reduced motion');
    await r.ctx.close();
  } finally {
    await setGlass(ap, was);
    await adm.close();
  }
  await browser.close();
  console.log(failed ? `\n${failed} failed` : '\nall passed');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
