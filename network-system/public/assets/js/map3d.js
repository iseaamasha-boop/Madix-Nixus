/* ==========================================================================
   البند 92: الخريطة ثلاثية الأبعاد. تُحمَّل عند اختيارها فقط بعد MapLibre GL المحلي (vendor/maplibre)،
   بمصدر الخريطة نفسه (بلاط OSM)، والأبراج أعمدة ضوء ارتفاعها حسب المتصلين على أجهزة MikroTik فيها
   ولونها حالة البرج. الروابط خطوط، والأجهزة والراوترات ذات الموقع الخاص نقاط. التحديث من التحديث المجمّع نفسه.
   ========================================================================== */
(function (window, document) {
  'use strict';

  var M = window.SNMap, SN = window.SN, gl = window.maplibregl;
  if (!M || !SN || !gl) { return; }

  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var COLOR = { 'is-up': '#22c55e', 'is-down': '#ef4444', 'is-idle': '#94a3b8' };
  var LINK = { fiber: '#f97316', wireless: '#3b82f6', cable: '#94a3b8', other: '#a855f7' };
  var box = null, map3 = null, observer = null;

  function dark() { return document.documentElement.getAttribute('data-theme') === 'dark'; }
  // الوضع الليلي: تعتيم البلاط نفسه (لا مرشّح على اللوحة فلا تتأثر الأعمدة)
  function tilePaint() {
    return dark()
      ? { 'raster-brightness-max': 0.36, 'raster-saturation': -0.75, 'raster-contrast': 0.15 }
      : { 'raster-brightness-max': 1, 'raster-saturation': 0, 'raster-contrast': 0 };
  }

  /** مربع صغير حول الموقع (m متر من المركز) */
  function square(lng, lat, m) {
    var dLat = m / 111320, dLng = m / (111320 * Math.cos(lat * Math.PI / 180));
    return [[[lng - dLng, lat - dLat], [lng + dLng, lat - dLat], [lng + dLng, lat + dLat], [lng - dLng, lat + dLat], [lng - dLng, lat - dLat]]];
  }

  /** أمتار البكسل الواحد عند التكبير الحالي: العمود بحجم ثابت على الشاشة في كل تكبير (يُعاد بناؤه بعد التكبير) */
  function mpp(lat) {
    var z = map3 ? map3.getZoom() : 12;
    return 40075016.686 * Math.cos(lat * Math.PI / 180) / (512 * Math.pow(2, z));
  }
  function columns() {
    var d = M.data(), clients = {};
    d.routers.forEach(function (r) { if (r.site_id) { clients[r.site_id] = (clients[r.site_id] || 0) + (r.clients || 0); } });
    return {
      type: 'FeatureCollection',
      features: d.sites.map(function (s) {
        var c = clients[s.id] || 0, m = mpp(s.lat);
        // العرض 10 بكسل، والطول 30 بكسل للبرج بلا متصلين ويزيد بالجذر التربيعي للمتصلين حتى 220
        return {
          type: 'Feature', id: s.id,
          properties: { kind: 'site', id: s.id, name: s.name, clients: c, h: (30 + Math.min(190, Math.sqrt(c) * 12)) * m, color: COLOR[M.siteState(s.id)] || COLOR['is-idle'] },
          geometry: { type: 'Polygon', coordinates: square(s.lng, s.lat, 5 * m) }
        };
      })
    };
  }

  function lines() {
    return {
      type: 'FeatureCollection',
      features: M.data().links.map(function (l) {
        return { type: 'Feature', properties: { color: LINK[l.type] || LINK.other }, geometry: { type: 'LineString', coordinates: [[l.a.lng, l.a.lat], [l.b.lng, l.b.lat]] } };
      })
    };
  }

  function points() {
    var d = M.data(), out = [];
    d.routers.forEach(function (r) {
      if (r.own) { out.push({ type: 'Feature', properties: { kind: 'router', name: r.name, clients: r.clients || 0, color: r.status === 'online' ? '#16a34a' : (r.status === 'offline' ? '#dc2626' : '#94a3b8') }, geometry: { type: 'Point', coordinates: [r.lng, r.lat] } }); }
    });
    (d.nodes || []).forEach(function (n) {
      out.push({ type: 'Feature', properties: { kind: 'node', name: n.name, color: '#0d9488' }, geometry: { type: 'Point', coordinates: [n.lng, n.lat] } });
    });
    return { type: 'FeatureCollection', features: out };
  }

  function refresh() {
    if (!map3 || !map3.getSource('sn-cols')) { return; }
    map3.getSource('sn-cols').setData(columns());
    map3.getSource('sn-links').setData(lines());
    map3.getSource('sn-points').setData(points());
  }

  function popup(e) {
    var p = e.features && e.features[0] && e.features[0].properties;
    if (!p) { return; }
    var stats = p.clients !== undefined ? '<div class="pop-stats"><span>' + SN.icon('users') + SN.fmtNum(+p.clients) + '</span></div>' : '';
    new gl.Popup({ closeButton: false, maxWidth: '240px', className: 'map-3d-pop' })
      .setLngLat(e.lngLat)
      .setHTML('<div class="pop"><div class="pop-title">' + SN.esc(p.name) + '</div>' + stats + '</div>')
      .addTo(map3);
  }

  function layers() {
    map3.addSource('sn-links', { type: 'geojson', data: lines() });
    map3.addSource('sn-cols', { type: 'geojson', data: columns() });
    map3.addSource('sn-points', { type: 'geojson', data: points() });
    map3.addLayer({ id: 'sn-links', type: 'line', source: 'sn-links', paint: { 'line-color': ['get', 'color'], 'line-width': 3, 'line-opacity': 0.85 } });
    // عمود الضوء: قاعدة شفافة قليلاً وارتفاع حسب المتصلين
    map3.addLayer({ id: 'sn-cols', type: 'fill-extrusion', source: 'sn-cols', paint: {
      'fill-extrusion-color': ['get', 'color'], 'fill-extrusion-height': ['get', 'h'], 'fill-extrusion-base': 0,
      'fill-extrusion-opacity': 0.88, 'fill-extrusion-vertical-gradient': true
    } });
    map3.addLayer({ id: 'sn-points', type: 'circle', source: 'sn-points', paint: {
      'circle-radius': 6, 'circle-color': ['get', 'color'], 'circle-stroke-width': 2, 'circle-stroke-color': '#ffffff'
    } });
    ['sn-cols', 'sn-points'].forEach(function (id) {
      map3.on('click', id, popup);
      map3.on('mouseenter', id, function () { map3.getCanvas().style.cursor = 'pointer'; });
      map3.on('mouseleave', id, function () { map3.getCanvas().style.cursor = ''; });
    });
  }

  function open() {
    if (map3) { return true; }
    var lm = M.map, c = lm.getCenter(), wrap = lm.getContainer().parentNode;
    box = document.createElement('div');
    box.className = 'map-3d';
    box.innerHTML = '<div class="map-3d-canvas"></div><button type="button" class="btn btn-sm map-3d-close" data-map-3d-close>' + SN.icon('x') + '2D</button>';
    wrap.appendChild(box);
    try {
      map3 = new gl.Map({
        container: box.firstChild,
        style: {
          version: 8,
          sources: { osm: { type: 'raster', tiles: ['https://a.tile.openstreetmap.org/{z}/{x}/{y}.png', 'https://b.tile.openstreetmap.org/{z}/{x}/{y}.png', 'https://c.tile.openstreetmap.org/{z}/{x}/{y}.png'], tileSize: 256, maxzoom: 19, attribution: '&copy; OpenStreetMap' } },
          layers: [{ id: 'osm', type: 'raster', source: 'osm', paint: tilePaint() }]
        },
        // تكبير Leaflet (بلاط 256) = تكبير MapLibre + 1
        center: [c.lng, c.lat], zoom: Math.max(0, lm.getZoom() - 1),
        pitch: reduce ? 60 : 0, bearing: reduce ? -20 : 0, maxPitch: 75,
        attributionControl: { compact: true }
      });
    } catch (err) {
      // بلا WebGL: تبقى الخريطة العادية ويختفي الزر
      box.remove();
      box = null;
      map3 = null;
      return false;
    }
    map3.addControl(new gl.NavigationControl({ visualizePitch: true }), 'top-right');
    map3.on('load', function () {
      layers();
      if (!reduce) { map3.easeTo({ pitch: 60, bearing: -20, duration: 300 }); }
    });
    map3.on('zoomend', function () { if (map3.getSource('sn-cols')) { map3.getSource('sn-cols').setData(columns()); } });
    box.querySelector('[data-map-3d-close]').addEventListener('click', function () { close(); if (window.SNMap3D.onClose) { window.SNMap3D.onClose(); } });
    // تبديل الوضع الداكن أثناء الفتح
    observer = new MutationObserver(function () {
      if (!map3 || !map3.getLayer('osm')) { return; }
      var p = tilePaint();
      Object.keys(p).forEach(function (k) { map3.setPaintProperty('osm', k, p[k]); });
    });
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    return true;
  }

  function close() {
    if (!map3) { return; }
    var c = map3.getCenter();
    M.map.setView([c.lat, c.lng], Math.round(map3.getZoom() + 1), { animate: false });
    if (observer) { observer.disconnect(); observer = null; }
    map3.remove();
    map3 = null;
    if (box) { box.remove(); box = null; }
  }

  SN.live.on('map', function () { if (map3) { refresh(); } });

  window.SNMap3D = { open: open, close: close, refresh: refresh, map: function () { return map3; } };
})(window, document);
