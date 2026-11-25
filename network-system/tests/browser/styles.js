#!/usr/bin/env node
/*
 * بصمة الشكل: الخصائص المحسوبة لكل عنصر في صفحات النظام، لإثبات أن إعادة تنظيم CSS لا تغيّر الشكل.
 *   node tests/browser/styles.js http://127.0.0.1:8090 snapshot before.json
 *   (تعديل CSS)
 *   node tests/browser/styles.js http://127.0.0.1:8090 snapshot after.json
 *   node tests/browser/styles.js compare before.json after.json
 * الصفحات: روابط القائمة الجانبية للشبكة ولوحة المسؤول وصفحات الدخول والهبوط، بالوضعين الفاتح والداكن، بعرض 390 و1280.
 */
'use strict';

const fs = require('fs');

// أبعاد العنصر والأعمدة تتبع البيانات، فلا تُقارن؛ المقارنة لما يأتي من CSS
const PROPS = ['display', 'position', 'min-height', 'max-width', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'padding-top', 'padding-right',
  'padding-bottom', 'padding-left', 'color', 'background-color', 'background-image', 'border-top-width', 'border-top-color', 'border-top-style',
  'border-right-width', 'border-bottom-width', 'border-left-width', 'border-top-left-radius', 'border-bottom-right-radius', 'box-shadow',
  'font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'text-align', 'opacity', 'transform', 'gap', 'flex-direction',
  'justify-content', 'align-items', 'overflow-x', 'overflow-y', 'z-index', 'visibility', 'white-space', 'cursor',
  'transition-duration', 'transition-property', 'animation-name', 'backdrop-filter', 'outline-style', 'text-decoration-line', 'fill', 'stroke'];

async function snapshot(base, out) {
  const { chromium } = require('playwright');
  const browser = await chromium.launch({ args: ['--ignore-certificate-errors', '--no-proxy-server'] });
  const T = process.env.MNX_TENANT || 'demo';
  const result = {};
  for (const theme of ['light', 'dark']) {
    for (const width of [390, 1280]) {
      const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width, height: 900 }, reducedMotion: 'reduce' });
      const page = await ctx.newPage();
      await page.goto(base + '/' + T + '/login');
      await page.fill('input[name=username]', process.env.MNX_USER || 'owner');
      await page.fill('input[name=password]', process.env.MNX_PASS || 'OwnerPass123');
      await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
      const adm = await ctx.newPage();
      await adm.goto(base + '/admin/login');
      await adm.fill('input[name=username]', process.env.MNX_ADMIN || 'admin');
      await adm.fill('input[name=password]', process.env.MNX_ADMIN_PASS || 'AdminPass123');
      await Promise.all([adm.waitForNavigation(), adm.click('button[type=submit]')]);
      const links = await page.$$eval('.sidebar a.nav-link[href]', (as) => as.map((a) => a.getAttribute('href')));
      const admLinks = (await adm.$$eval('.sidebar a[href]', (as) => as.map((a) => a.getAttribute('href')))).filter((h) => h.startsWith('/admin'));
      // صفحات النماذج التي فيها منتقي الموقع على الخريطة
      const forms = ['/' + T + '/subscribers/create', '/' + T + '/routers/create', '/' + T + '/sites'];
      const urls = [...new Set(links.concat(admLinks, forms, ['/', '/admin/login', '/' + T + '/login']))].filter((h) => !/logout|export|download|print|lang/.test(h));
      for (const u of urls) {
        const p = u.startsWith('/admin') ? adm : page;
        try {
          await p.goto(base + u, { waitUntil: 'load', timeout: 30000 });
        } catch (e) { continue; }
        await p.evaluate((t) => { document.documentElement.setAttribute('data-theme', t); document.documentElement.setAttribute('data-theme-pref', t); }, theme);
        // الخطوط والحركات تستقر
        await p.evaluate(() => (document.fonts ? document.fonts.ready : null));
        // انتهاء انتقالات الألوان بعد تبديل الوضع، وتثبيت الحركات المستمرة (مؤشرات التحميل) على بدايتها
        await p.waitForTimeout(700);
        await p.evaluate(() => { if (document.getAnimations) { document.getAnimations().forEach((a) => { try { a.pause(); a.currentTime = 0; } catch (e) {} }); } });
        const styles = await p.evaluate((props) => {
          // لكل نوع عنصر (الوسم والأصناف): مجموعة أشكاله المختلفة، فلا يؤثر عدد الصفوف المتغير مع البيانات
          const out = {};
          document.querySelectorAll('body *').forEach((el) => {
            if (el.closest('svg') && el.tagName !== 'svg') { return; }
            const cs = getComputedStyle(el);
            const key = el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/).sort().join('.') : '');
            const v = props.map((pr) => cs.getPropertyValue(pr)).join('|');
            (out[key] = out[key] || {})[v] = 1;
          });
          Object.keys(out).forEach((k) => { out[k] = Object.keys(out[k]).sort(); });
          return out;
        }, PROPS);
        result[theme + ' ' + width + ' ' + u] = styles;
      }
      await ctx.close();
    }
  }
  await browser.close();
  fs.writeFileSync(out, JSON.stringify(result));
  console.log('snapshot: ' + Object.keys(result).length + ' page views → ' + out);
}

function compare(a, b) {
  const A = JSON.parse(fs.readFileSync(a, 'utf8'));
  const B = JSON.parse(fs.readFileSync(b, 'utf8'));
  let diffs = 0;
  let shown = 0;
  for (const view of Object.keys(A)) {
    if (!B[view]) { console.log('missing view in second snapshot: ' + view); diffs++; continue; }
    for (const el of Object.keys(A[view])) {
      const x = A[view][el];
      const y = B[view][el];
      if (!y) { continue; }
      const gone = x.filter((v) => !y.includes(v));
      const added = y.filter((v) => !x.includes(v));
      if (!gone.length && !added.length) { continue; }
      diffs++;
      if (shown++ < 25) {
        const xa = gone[0] ? gone[0].split('|') : [];
        const ya = added[0] ? added[0].split('|') : [];
        const props = PROPS.map((p, i) => [p, xa[i], ya[i]]).filter((t) => t[1] !== t[2]).map((t) => t[0] + ': ' + t[1] + ' → ' + t[2]);
        console.log(view + '  ' + el + '\n    ' + props.slice(0, 6).join('\n    '));
      }
    }
  }
  console.log(diffs ? diffs + ' element types differ' : 'identical: ' + Object.keys(A).length + ' page views');
  process.exit(diffs ? 1 : 0);
}

const [base, cmd, f1, f2] = process.argv.slice(2);
if (base === 'compare') {
  compare(cmd, f1);
} else {
  snapshot(base, f1).catch((e) => { console.error(e); process.exit(2); });
}
