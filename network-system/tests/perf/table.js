#!/usr/bin/env node
/*
 * جدول السرعة (Markdown) من نتيجة قياس واحدة أو نتيجتين (قبل ← بعد).
 *   node tests/perf/table.js before.json [after.json]
 */
'use strict';

const fs = require('fs');
const [a, b] = process.argv.slice(2).map((f) => JSON.parse(fs.readFileSync(f, 'utf8')));
if (!a) {
  console.error('usage: table.js before.json [after.json]');
  process.exit(1);
}

const v = (x, d = 0) => (x === null || x === undefined || Number.isNaN(x) ? '—' : (d ? Number(x).toFixed(d) : String(Math.round(x))));
const pair = (x, y, d = 0) => (b ? v(x, d) + ' → ' + v(y, d) : v(x, d));
const byKey = (r) => Object.fromEntries(r.pages.map((p) => [p.key, p]));
const A = byKey(a);
const B = b ? byKey(b) : {};

const head = ['الصفحة', 'TTFB ms', 'SQL (عدد/ms)', 'LCP ms (نت عادي)', 'LCP ms (Lighthouse جوال)', 'Lighthouse', 'CLS', 'INP ms', 'JS KB', 'CSS KB', 'JS+CSS KB', 'الطلبات', 'التنقل ms'];
const rows = [head, head.map(() => '---')];
for (const key of Object.keys(A)) {
  const x = A[key];
  const y = B[key] || {};
  const sql = (p) => (p && p.sqlN !== null && p.sqlN !== undefined ? p.sqlN + '/' + v(p.sqlMs, 1) : '—');
  const lh = (p, k) => (p && p.lh && !p.lh.error ? p.lh[k] : null);
  const total = (p) => (p && p.jsKB !== undefined ? p.jsKB + p.cssKB + (p.inlineCssKB || 0) + (p.inlineJsKB || 0) : null);
  rows.push([
    x.name,
    pair(x.ttfb, y.ttfb, 1),
    b ? sql(x) + ' → ' + sql(y) : sql(x),
    pair(x.lcp, y.lcp),
    pair(lh(x, 'lcp'), lh(y, 'lcp')),
    pair(lh(x, 'score'), lh(y, 'score')),
    pair(x.cls, y.cls, 3),
    pair(x.inp, y.inp),
    pair(x.jsKB, y.jsKB, 1),
    pair(x.cssKB, y.cssKB, 1),
    pair(total(x), total(y), 1),
    pair(x.requests, y.requests),
    x.navClick !== undefined ? pair(x.navClick, y.navClick) : '—',
  ]);
}
console.log(rows.map((r) => '| ' + r.join(' | ') + ' |').join('\n'));

const hs = (r) => r.hotspot || {};
const H = hs(a);
const HB = b ? hs(b) : {};
console.log('\n| واجهة صفحة الهوتسبوت | TTFB ms | SQL (عدد/ms) | الحجم بايت |\n| --- | --- | --- | --- |');
for (const k of ['info', 'status', 'config', 'sdk']) {
  if (!H[k]) { continue; }
  const y = HB[k] || {};
  console.log('| ' + k + ' | ' + pair(H[k].ttfb, y.ttfb, 1) + ' | ' + (b ? H[k].sqlN + '/' + H[k].sqlMs + ' → ' + (y.sqlN ?? '—') + '/' + (y.sqlMs ?? '—') : H[k].sqlN + '/' + H[k].sqlMs) + ' | ' + pair(H[k].bytes, y.bytes) + ' |');
}
