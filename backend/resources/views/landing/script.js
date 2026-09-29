(function () {
  var cfgEl = document.getElementById('hl-data');
  if (!cfgEl) return;
  var C = JSON.parse(cfgEl.textContent || '{}');
  var api = C.api || '/api/v1/hellom';
  var q = function (s, r) { return (r || document).querySelector(s); };
  var qa = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  function toast(text) {
    var t = q('#hl-toast');
    if (!t) return;
    t.textContent = text;
    t.classList.add('on');
    setTimeout(function () { t.classList.remove('on'); }, 2200);
  }

  // Ad attribution: keep UTM / click ids of the visit that brought the buyer (read by checkout).
  try {
    var params = new URLSearchParams(location.search);
    var keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid', 'gclid', 'ttclid'];
    var found = {};
    keys.forEach(function (k) { var v = params.get(k); if (v) found[k] = v.slice(0, 150); });
    var ref = document.referrer && document.referrer.indexOf(location.host) === -1 ? new URL(document.referrer).host : '';
    if (Object.keys(found).length || (ref && !localStorage.getItem('hl_attr'))) {
      found.referrer = ref;
      found.landing = location.pathname.slice(0, 150);
      found.ts = Date.now();
      localStorage.setItem('hl_attr', JSON.stringify(found));
    }
  } catch (e) {}

  // Light stats (no cookies): one beacon per event.
  function track(metric, extra) {
    if (C.preview) return;
    var body = JSON.stringify(Object.assign({ username: C.username, metric: metric, page_id: C.pageId || null, product_id: C.productId || null,
      source: (function () { try { var a = JSON.parse(localStorage.getItem('hl_attr') || '{}'); return a.utm_source || a.referrer || ''; } catch (e) { return ''; } })() }, extra || {}));
    try { navigator.sendBeacon(api + '/public/landing-events', new Blob([body], { type: 'application/json' })); } catch (e) {}
  }
  track(C.productId ? 'product_view' : 'visit');
  qa('[data-track]').forEach(function (el) {
    el.addEventListener('click', function () {
      track(el.getAttribute('data-track') === 'buy' ? 'checkout_start' : 'click', { dimension: (el.getAttribute('data-label') || el.textContent || '').trim().slice(0, 100), product_id: el.getAttribute('data-product') || C.productId || null });
      if (el.getAttribute('data-track') === 'buy') fire('InitiateCheckout', { content_ids: [el.getAttribute('data-product')], value: Number(el.getAttribute('data-value') || 0), currency: 'IDR' });
    });
  });

  // Share / copy / QR.
  var shareUrl = C.shareUrl || location.href;
  qa('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      var done = function () { toast('Link disalin'); };
      if (navigator.clipboard) navigator.clipboard.writeText(shareUrl).then(done, function () { prompt('Salin link:', shareUrl); });
      else prompt('Salin link:', shareUrl);
    });
  });
  // Our sheet always opens (copy link, WhatsApp, QR); the phone's own share menu is one option in it.
  qa('[data-share]').forEach(function (b) {
    b.addEventListener('click', function () { var d = q('#hl-share'); if (d && d.showModal) d.showModal(); });
  });
  qa('[data-native-share]').forEach(function (b) {
    if (!navigator.share) { b.hidden = true; return; }
    b.addEventListener('click', function () { navigator.share({ title: document.title, url: shareUrl }).catch(function () {}); });
  });
  qa('[data-qr]').forEach(function (b) {
    b.addEventListener('click', function () {
      fetch(api + '/public/landing-qr?url=' + encodeURIComponent(shareUrl)).then(function (r) { return r.text(); }).then(function (svg) {
        var img = new Image();
        img.onload = function () {
          var c = document.createElement('canvas'); c.width = c.height = 1024;
          var x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, 1024, 1024); x.drawImage(img, 32, 32, 960, 960);
          var a = document.createElement('a'); a.download = 'qr-' + (C.username || 'hellom') + '.png'; a.href = c.toDataURL('image/png'); a.click();
        };
        img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
      }).catch(function () { toast('QR belum bisa dibuat'); });
    });
  });
  qa('[data-close]').forEach(function (b) { b.addEventListener('click', function () { var d = b.closest('dialog'); if (d) d.close(); }); });

  // Report.
  var rf = q('#hl-report-form');
  qa('[data-report]').forEach(function (b) { b.addEventListener('click', function () { var d = q('#hl-report'); if (d && d.showModal) d.showModal(); }); });
  if (rf) rf.addEventListener('submit', function (e) {
    e.preventDefault();
    var fd = new FormData(rf);
    fetch(api + '/public/landing-reports', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ organization_slug: C.orgSlug, product_id: C.productId || undefined, landing_page_id: C.pageId || undefined, reason: fd.get('reason'),
        description: fd.get('description') || undefined, reporter_email: fd.get('email') || undefined, page_url: location.href }) })
      .then(function (r) { return r.json(); }).then(function (j) { rf.innerHTML = '<p class="note ' + (j.success ? '' : 'err') + '">' + (j.success ? 'Terima kasih, laporan kamu sudah kami terima.' : 'Laporan belum terkirim. Coba lagi.') + '</p>'; })
      .catch(function () { toast('Laporan belum terkirim'); });
  });

  // Contact forms (leads) → seller dashboard, optionally WhatsApp.
  qa('form[data-lead]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var fields = {};
      new FormData(f).forEach(function (v, k) { fields[k] = String(v); });
      var btn = q('button[type=submit]', f); if (btn) btn.disabled = true;
      fetch(api + '/public/landing/' + f.getAttribute('data-page') + '/customers', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ block_id: f.getAttribute('data-block'), form_title: f.getAttribute('data-title'), fields: fields }) })
        .then(function (r) { if (!r.ok) throw new Error(); return r.json(); })
        .then(function () {
          var wa = f.getAttribute('data-wa');
          f.innerHTML = '<p class="note">' + (f.getAttribute('data-success') || 'Terima kasih, data kamu sudah terkirim.') + '</p>';
          if (wa) window.open('https://wa.me/' + wa + '?text=' + encodeURIComponent(Object.keys(fields).map(function (k) { return k + ': ' + fields[k]; }).join('\n')), '_blank', 'noopener');
          track('click', { dimension: 'form:' + (f.getAttribute('data-title') || '') });
        })
        .catch(function () { if (btn) btn.disabled = false; toast('Belum terkirim. Coba lagi.'); });
    });
  });

  // Countdown.
  qa('[data-countdown]').forEach(function (el) {
    var end = new Date(el.getAttribute('data-countdown')).getTime();
    var tick = function () {
      var s = Math.max(0, Math.floor((end - Date.now()) / 1000));
      var parts = [Math.floor(s / 86400), Math.floor(s % 86400 / 3600), Math.floor(s % 3600 / 60), s % 60];
      qa('b', el).forEach(function (b, i) { b.textContent = String(parts[i]).padStart(2, '0'); });
      if (s === 0) { var done = el.getAttribute('data-expired'); if (done) el.innerHTML = '<p><strong>' + done.replace(/</g, '&lt;') + '</strong></p>'; return; }
      setTimeout(tick, 1000);
    };
    tick();
  });

  // YouTube: thumbnail first, player only on tap (keeps the page light).
  qa('button.yt').forEach(function (b) {
    b.addEventListener('click', function () {
      var f = document.createElement('iframe');
      f.src = 'https://www.youtube-nocookie.com/embed/' + b.getAttribute('data-yt') + '?autoplay=1&rel=0';
      f.allow = 'autoplay; encrypted-media; picture-in-picture'; f.allowFullscreen = true; f.title = b.getAttribute('aria-label') || 'Video';
      b.innerHTML = ''; b.appendChild(f);
    }, { once: true });
  });

  // Ad pixels: loaded after the page is interactive so they never slow the first paint.
  var T = C.tracking || {};
  var queue = [];
  function fire(name, data) {
    queue.push([name, data || {}]);
    if (window.__hlPixels) flush();
  }
  function flush() {
    queue.splice(0).forEach(function (ev) {
      var name = ev[0], d = ev[1];
      if (window.fbq) window.fbq(name === 'PageView' ? 'track' : 'track', name, name === 'PageView' ? undefined : d);
      if (window.gtag) {
        var map = { PageView: 'page_view', ViewContent: 'view_item', InitiateCheckout: 'begin_checkout' };
        window.gtag('event', map[name] || name, d.value ? { value: d.value, currency: 'IDR', items: (d.content_ids || []).map(function (id) { return { item_id: id }; }) } : {});
      }
      if (window.ttq) window.ttq.track(name === 'PageView' ? 'Pageview' : name, d.value ? { value: d.value, currency: 'IDR', content_id: (d.content_ids || [])[0] } : {});
    });
  }
  function loadScript(src) { var s = document.createElement('script'); s.async = true; s.src = src; document.head.appendChild(s); }
  function loadPixels() {
    if (C.preview || window.__hlPixels) return;
    if (T.meta_pixel_id) {
      !function (f, b, e, v, n, t, s) { if (f.fbq) return; n = f.fbq = function () { n.callMethod ? n.callMethod.apply(n, arguments) : n.queue.push(arguments); }; if (!f._fbq) f._fbq = n; n.push = n; n.loaded = !0; n.version = '2.0'; n.queue = []; loadScript('https://connect.facebook.net/en_US/fbevents.js'); }(window, document);
      window.fbq('init', T.meta_pixel_id);
    }
    var gid = T.ga4_id || T.google_ads_id;
    if (gid) {
      loadScript('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(gid));
      window.dataLayer = window.dataLayer || [];
      window.gtag = function () { window.dataLayer.push(arguments); };
      window.gtag('js', new Date());
      if (T.ga4_id) window.gtag('config', T.ga4_id, { send_page_view: false });
      if (T.google_ads_id) window.gtag('config', T.google_ads_id);
    }
    if (T.tiktok_pixel_id) {
      !function (w, d, t) { w.TiktokAnalyticsObject = t; var ttq = w[t] = w[t] || []; ttq.methods = ['page', 'track', 'identify', 'instances', 'debug', 'on', 'off', 'once', 'ready', 'alias', 'group', 'enableCookie', 'disableCookie']; ttq.setAndDefer = function (t, e) { t[e] = function () { t.push([e].concat(Array.prototype.slice.call(arguments, 0))); }; }; for (var i = 0; i < ttq.methods.length; i++) ttq.setAndDefer(ttq, ttq.methods[i]); ttq.load = function (e) { loadScript('https://analytics.tiktok.com/i18n/pixel/events.js?sdkid=' + encodeURIComponent(e) + '&lib=' + t); }; ttq.load(T.tiktok_pixel_id); }(window, document, 'ttq');
    }
    window.__hlPixels = true;
    flush();
  }
  // Pixels only with the visitor's consent (per shop, remembered); stats above are cookie-free.
  var consentKey = 'hl_consent:' + C.username;
  var consent = null;
  try { consent = localStorage.getItem(consentKey); } catch (e) {}
  var banner = q('#hl-consent');
  if (banner && !consent) banner.hidden = false;
  qa('[data-consent]').forEach(function (b) {
    b.addEventListener('click', function () {
      consent = b.getAttribute('data-consent');
      try { localStorage.setItem(consentKey, consent); } catch (e) {}
      if (banner) banner.hidden = true;
      if (consent === 'granted') startPixels();
    });
  });
  function startPixels() {
    if (!(T.meta_pixel_id || T.ga4_id || T.google_ads_id || T.tiktok_pixel_id) || window.__hlStarted) return;
    window.__hlStarted = true;
    fire('PageView');
    if (C.productId) fire('ViewContent', { content_ids: [C.productId], content_name: C.productName, value: C.value || 0, currency: 'IDR', content_type: 'product' });
    var start = function () { ('requestIdleCallback' in window) ? requestIdleCallback(loadPixels, { timeout: 3000 }) : setTimeout(loadPixels, 1500); };
    if (document.readyState === 'complete') start(); else window.addEventListener('load', start);
    ['pointerdown', 'keydown', 'scroll'].forEach(function (e) { window.addEventListener(e, loadPixels, { once: true, passive: true }); });
  }
  if (consent === 'granted') startPixels();
})();
