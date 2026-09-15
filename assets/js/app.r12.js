/* DoctorFizz Rank Checker
   No framework. The API has already stripped result markup down to b, strong and br with no
   attributes, so those two fields are the only ones written as HTML. Everything else is text. */

(function () {
  'use strict';

  /* The mount path is written into the script tag when the page is built, so the same files
     work at the domain root or in any subfolder without being edited. */
  var tag  = document.querySelector('script[data-df-base]');
  var BASE = (tag && tag.getAttribute('data-df-base')) || './';
  var API  = BASE + 'api/index.php';

  /* Asset filenames carry the release, because query string versioning is stripped by
     LiteSpeed and most CDNs, which is how a stale stylesheet outlived three releases. */
  var REL  = (tag && tag.getAttribute('data-df-rel')) || 'r12';

  var form    = document.getElementById('rank-form');
  var tool    = document.getElementById('tool-card');
  var go      = document.getElementById('go');
  var meter   = document.getElementById('meter');
  var meterT  = document.getElementById('meter-text');
  var notice  = document.getElementById('notice');
  var result  = document.getElementById('result');
  var summary = document.getElementById('summary');
  var ladder  = document.getElementById('ladder');
  var recent  = document.getElementById('recent');
  var presets = document.getElementById('presets');
  var rerun   = document.getElementById('rerun');

  if (!form) { return; }

  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var motion  = null;
  var session = { csrf: '', limit: 5, remaining: null, issued: 0 };
  var history = [];
  var last    = null;

  /* No class is added here on purpose. Nothing on this page is hidden by CSS waiting for
     JavaScript to rescue it, so a failed script load costs the animation and nothing else. */

  var ADVICE = {
    top3:     'You own this query. Keep the page current, and check whether the AI answer above you cites you or somebody else.',
    page1:    'Page one, under the fold. The title and the opening answer usually move this further than more words will.',
    striking: 'Striking distance, and the highest return work on the site. Add internal links from your strongest related pages, then close the gaps against the top five.',
    deep:     'Too far back to tune. Work out whether the page and the query are actually matched before touching the copy.',
    none:     'Not in the range you scanned. Check indexing first, then widen the depth and run it again.'
  };

  /* ---------------------------------------------------------------- helpers */

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text !== undefined && text !== null) { n.textContent = String(text); }
    return n;
  }

  function say(message, kind) {
    if (!message) { notice.hidden = true; notice.textContent = ''; return; }
    notice.className = 'notice' + (kind ? ' is-' + kind : '');
    notice.textContent = message;
    notice.hidden = false;
  }

  function setMeter(remaining, limit) {
    if (typeof remaining !== 'number') { return; }
    session.remaining = remaining;
    meter.setAttribute('data-state', remaining <= 0 ? 'out' : (remaining <= 1 ? 'low' : 'ok'));
    meterT.textContent = remaining <= 0
      ? 'No checks left today'
      : remaining + ' of ' + limit + ' checks left today';
  }

  function setBusy(on) {
    go.disabled = on;
    go.classList.toggle('busy', on);
    tool.classList.toggle('busy', on);
    go.querySelector('.btn-txt').textContent = on ? 'Scanning' : 'Check ranking';
    form.setAttribute('aria-busy', on ? 'true' : 'false');
  }

  function clearResults() {
    [result, summary, ladder].forEach(function (n) { n.hidden = true; n.innerHTML = ''; });
  }

  /* Brings the tool back into view. Routed through the motion layer when it is loaded, so a
     programmatic scroll does not fight Lenis. */
  function goToTool() {
    if (motion && typeof motion.scrollToTool === 'function') { motion.scrollToTool(tool); return; }
    var y = tool.getBoundingClientRect().top + window.pageYOffset - 96;
    window.scrollTo({ top: y, behavior: 'smooth' });
  }

  function hostOf(url) {
    try { return new URL(url).hostname.replace(/^www\./, ''); } catch (e) { return url; }
  }

  /* ---------------------------------------------------------------- country type ahead */

  /* The select stays the source of truth. The text input is an optional convenience layered
     on top, and it is removed entirely if the browser does not support datalist. */
  (function countryCombo() {
    var input = document.getElementById('gl-search');
    var select = document.getElementById('gl');
    var field = input && input.closest('.fld');
    if (!input || !select || !field) { return; }

    var supported = 'list' in document.createElement('input') && !!window.HTMLDataListElement;
    if (!supported) { field.classList.add('no-combo'); return; }

    var byName = {};
    Array.prototype.forEach.call(select.options, function (o) { byName[o.text.toLowerCase()] = o.value; });

    input.value = select.options[select.selectedIndex].text;

    input.addEventListener('input', function () {
      var code = byName[input.value.trim().toLowerCase()];
      if (code) { select.value = code; }
    });
    input.addEventListener('blur', function () {
      // Never leave the box showing something that is not the selected country.
      input.value = select.options[select.selectedIndex].text;
    });
  })();

  /* ---------------------------------------------------------------- presets */

  if (presets) {
    presets.addEventListener('click', function (ev) {
      var b = ev.target.closest('button[data-k]');
      if (!b) { return; }
      form.elements.keyword.value = b.getAttribute('data-k');
      form.elements.domain.value = b.getAttribute('data-d');
      form.elements.keyword.focus();
    });
  }

  /* ---------------------------------------------------------------- session */

  var SESSION_ERRORS = {
    setup:  'The checker is not configured yet. Whoever installed it needs to create app/config.php.',
    server: 'The checker cannot reach its database. If this is your site, check that app/data is writable and that app_key is set.',
    origin: 'This page was loaded from an unexpected address, so the checker refused to start.'
  };

  function loadSession() {
    var status = 0;
    return fetch(API + '?a=session', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (r) {
        status = r.status;
        return r.json().catch(function () {
          throw new Error('The checker endpoint did not return JSON. The most likely cause is a wrong site_url, so the page is calling the wrong path.');
        });
      })
      .then(function (d) {
        if (!d || !d.ok) {
          throw new Error(SESSION_ERRORS[d && d.code] || (d && d.error) || 'The checker could not start (HTTP ' + status + ').');
        }
        session.csrf = d.csrf;
        session.limit = d.limit;
        session.issued = d.issued;
        setMeter(d.remaining, d.limit);
        if (d.maintenance) { say(d.note, 'warn'); go.disabled = true; }
      })
      .catch(function (err) {
        meter.setAttribute('data-state', 'out');
        meterT.textContent = 'Checker unavailable';
        go.disabled = true;

        var msg = err && err.message ? err.message : 'The checker could not start.';
        if (/failed to fetch|networkerror|load failed/i.test(msg)) {
          // A network level failure, so the request never reached PHP. Say what to check.
          msg = 'The checker could not reach its API at ' + API + '. The request never arrived, '
              + 'which usually means that path returns an error or is blocked by the server. '
              + 'Open that address directly in a browser tab to see what it says.';
        }
        say(msg, 'warn');
        if (window.console) { console.error('DoctorFizz session failed:', API, err); }
      });
  }

  /* ---------------------------------------------------------------- render */

  function countTo(node, target) {
    if (reduced || target <= 3) { node.textContent = String(target); return; }
    var start = performance.now(), dur = Math.min(180 + target * 20, 1100);
    (function step(now) {
      var p = Math.min((now - start) / dur, 1);
      node.textContent = String(Math.max(1, Math.round((1 - Math.pow(1 - p, 3)) * target)));
      if (p < 1) { requestAnimationFrame(step); }
    })(start);
  }

  function renderResult(d) {
    result.innerHTML = '';

    var fig = el('div', 'rd-fig');
    if (d.found) {
      fig.appendChild(el('span', 'rd-hash', '#'));
      var num = el('span', 'rd-num', '1');
      fig.appendChild(num);
      countTo(num, d.position);
    } else {
      fig.appendChild(el('span', 'rd-num miss', 'Not in the top ' + (d.depth * 10)));
    }

    var side = el('div');
    var band = d.band || { key: 'none', label: 'Not in range', tone: 'warn' };
    side.appendChild(el('span', 'chip ' + band.tone, band.label));

    var meta = el('p', 'rd-meta');
    meta.appendChild(document.createTextNode('Position of '));
    meta.appendChild(el('strong', null, d.domain));
    meta.appendChild(document.createTextNode(' for '));
    meta.appendChild(el('strong', null, '"' + d.keyword + '"'));
    meta.appendChild(document.createTextNode(' in ' + d.country + ', ' + d.language + '. ' + d.scanned + ' results scanned.'));
    if (d.free) { meta.appendChild(document.createTextNode(' Served from cache, so it cost you nothing.')); }
    side.appendChild(meta);
    side.appendChild(el('p', 'rd-advice', ADVICE[band.key] || ''));

    result.appendChild(fig);
    result.appendChild(side);
    result.hidden = false;
  }

  /* The three facts a person would otherwise have to work out by counting rows. */
  function renderSummary(d) {
    summary.innerHTML = '';
    var rows = [];

    var mine = null;
    (d.results || []).forEach(function (r) { if (r.match && !mine) { mine = r; } });

    rows.push(['Your ranking URL', mine ? mine.link : 'None found in this range', mine ? mine.link : null]);
    rows.push(['Pages above you', d.found ? String(d.position - 1) : 'At least ' + d.scanned, null]);

    var counts = {}, top = '', best = 0;
    (d.results || []).forEach(function (r) {
      if (r.match) { return; }
      var h = hostOf(r.link);
      counts[h] = (counts[h] || 0) + 1;
      if (counts[h] > best) { best = counts[h]; top = h; }
    });
    rows.push(['Most listed competitor', top ? top + ' (' + best + ')' : 'None', null]);

    rows.forEach(function (row) {
      var cell = el('div', 'sm');
      cell.appendChild(el('span', null, row[0]));
      if (row[2]) {
        var a = el('a', null, row[1]);
        a.href = row[2];
        a.rel = 'noopener nofollow';
        a.target = '_blank';
        var b = el('b');
        b.appendChild(a);
        cell.appendChild(b);
      } else {
        cell.appendChild(el('b', null, row[1]));
      }
      summary.appendChild(cell);
    });
    summary.hidden = false;
  }

  function plainText(d) {
    var lines = [
      'Keyword: ' + d.keyword,
      'Domain: ' + d.domain,
      'Market: ' + d.country + ', ' + d.language,
      'Position: ' + (d.found ? '#' + d.position : 'not in top ' + (d.depth * 10)),
      'Scanned: ' + d.scanned + ' results',
      ''
    ];
    (d.results || []).forEach(function (r) {
      lines.push((r.match ? '>> ' : '   ') + r.rank + '. ' + hostOf(r.link) + '  ' + r.link);
    });
    lines.push('', 'Checked with the DoctorFizz free rank checker by Itzfizz Digital.');
    return lines.join('\n');
  }

  function renderLadder(d) {
    ladder.innerHTML = '';
    if (!d.results || !d.results.length) { return; }

    var head = el('li', 'ladder-h');
    head.appendChild(el('span', null, 'The results standing in front of you'));

    var copy = el('button', 'copy-btn', 'Copy result');
    copy.type = 'button';
    copy.addEventListener('click', function () {
      var text = plainText(d);
      var done = function () { copy.textContent = 'Copied'; setTimeout(function () { copy.textContent = 'Copy result'; }, 1800); };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () { copy.textContent = 'Copy failed'; });
      } else {
        copy.textContent = 'Copy failed';
      }
    });
    head.appendChild(copy);
    ladder.appendChild(head);

    d.results.slice(0, 50).forEach(function (r) {
      var row = el('li', 'rung' + (r.match ? ' hit' : ''));
      row.appendChild(el('div', 'rung-n', r.rank));

      var main = el('div');
      var t = el('p', 'rung-t');
      t.innerHTML = r.title || '(no title)';
      main.appendChild(t);
      main.appendChild(el('p', 'rung-u', r.link));
      if (r.snippet) {
        var s = el('p', 'rung-s');
        s.innerHTML = r.snippet;
        main.appendChild(s);
      }
      row.appendChild(main);
      ladder.appendChild(row);
    });

    if (d.found && d.results.length >= 10) {
      ladder.appendChild(el('li', 'ladder-more', 'The scan stopped at your position, so nothing below it was fetched.'));
    }
    ladder.hidden = false;

    if (window.DFHero && typeof window.DFHero.mark === 'function') {
      window.DFHero.mark(d.found ? d.position : 0);
    }
  }

  /* Session only, held in memory. Nothing is written to storage. */
  function pushHistory(d) {
    history = history.filter(function (h) { return !(h.keyword === d.keyword && h.domain === d.domain); });
    history.unshift({ keyword: d.keyword, domain: d.domain, gl: d.glCode, hl: d.hlCode, depth: d.depth,
                      position: d.position, found: d.found });
    history = history.slice(0, 6);
    renderHistory();
  }

  function renderHistory() {
    if (!recent || history.length < 2) { if (recent) { recent.hidden = true; } return; }
    recent.innerHTML = '';
    recent.appendChild(el('h3', null, 'Checked this session'));
    var ul = el('ul');
    history.forEach(function (h) {
      var b = el('button');
      b.type = 'button';
      var i = el('i', h.found ? '' : 'miss', h.found ? '#' + h.position : '--');
      b.appendChild(i);
      b.appendChild(el('span', null, h.keyword + ' / ' + h.domain));
      b.addEventListener('click', function () {
        form.elements.keyword.value = h.keyword;
        form.elements.domain.value = h.domain;
        if (h.gl) { form.elements.gl.value = h.gl; }
        if (h.hl) { form.elements.hl.value = h.hl; }
        if (h.depth) { form.elements.depth.value = h.depth; }
        goToTool();
        form.elements.keyword.focus();
      });
      var li = el('li');
      li.appendChild(b);
      ul.appendChild(li);
    });
    recent.appendChild(ul);
    recent.hidden = false;
  }

  /* ---------------------------------------------------------------- sticky re-run bar */

  function initRerun() {
    if (!rerun) { return; }
    document.getElementById('rerun-btn').addEventListener('click', function () {
      goToTool();
      form.elements.keyword.select();
    });

    if (!('IntersectionObserver' in window)) { return; }
    new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        var show = !en.isIntersecting && last !== null;
        rerun.hidden = !show;
        rerun.classList.toggle('on', show);
      });
    }, { threshold: 0 }).observe(tool);
  }

  function updateRerun(d) {
    last = d;
    var pos = document.getElementById('rerun-pos');
    var meta = document.getElementById('rerun-meta');
    pos.textContent = d.found ? '#' + d.position : '--';
    meta.textContent = d.keyword + ' / ' + d.domain;
  }

  /* ---------------------------------------------------------------- submit */

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (go.disabled) { return; }

    var kw = form.elements.keyword;
    var dm = form.elements.domain;
    kw.removeAttribute('aria-invalid');
    dm.removeAttribute('aria-invalid');

    var body = {
      csrf:    session.csrf,
      issued:  session.issued,
      company: form.elements.company.value,
      keyword: kw.value.trim(),
      domain:  dm.value.trim(),
      gl:      form.elements.gl.value,
      hl:      form.elements.hl.value,
      depth:   form.elements.depth.value
    };

    if (!body.keyword) {
      kw.setAttribute('aria-invalid', 'true'); kw.focus();
      say('Enter the keyword you want to check.'); return;
    }
    if (!body.domain) {
      dm.setAttribute('aria-invalid', 'true'); dm.focus();
      say('Enter the domain you want to find, for example itzfizz.com.'); return;
    }

    clearResults();
    setBusy(true);
    say('Scanning the results. This usually takes a few seconds.', 'info');

    fetch(API + '?a=check', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body)
    })
      .then(function (r) {
        return r.json().catch(function () {
          throw new Error('The server sent something we could not read. Try again in a moment.');
        });
      })
      .then(function (d) {
        setBusy(false);
        if (typeof d.remaining === 'number') { setMeter(d.remaining, session.limit); }

        if (!d.ok) {
          say(d.error || 'That did not work. Try again.', d.code === 'provider' ? 'warn' : undefined);
          if (d.code === 'csrf') { loadSession(); }
          if (d.code === 'input') { kw.focus(); }
          return;
        }

        say(d.notice || '', d.notice ? 'warn' : undefined);
        d.glCode = body.gl;
        d.hlCode = body.hl;

        renderResult(d);
        renderSummary(d);
        renderLadder(d);
        pushHistory(d);
        updateRerun(d);

        if (motion && typeof motion.revealResult === 'function') {
          motion.revealResult(result, summary, ladder);
        }
        result.setAttribute('tabindex', '-1');
        result.focus({ preventScroll: true });
      })
      .catch(function (err) {
        setBusy(false);
        say(err && err.message ? err.message : 'The check could not be completed. Check your connection and try again.');
      });
  });

  /* ---------------------------------------------------------------- motion and hero */

  function loadScript(src) {
    return new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = src;
      s.async = true;
      s.onload = resolve;
      s.onerror = function () { reject(new Error('failed ' + src)); };
      document.head.appendChild(s);
    });
  }

  function heroCapable() {
    if (reduced) { return false; }
    if (navigator.deviceMemory && navigator.deviceMemory < 4) { return false; }
    if (navigator.connection && navigator.connection.saveData) { return false; }
    try {
      var c = document.createElement('canvas');
      return !!(c.getContext('webgl2') || c.getContext('webgl'));
    } catch (e) { return false; }
  }

  function startMotion() {
    if (reduced) { return; }

    loadScript(BASE + 'assets/vendor/gsap.min.js')
      .then(function () { return loadScript(BASE + 'assets/vendor/ScrollTrigger.min.js'); })
      // Lenis is optional. If it fails the motion layer runs on native scrolling instead.
      .then(function () { return loadScript(BASE + 'assets/vendor/lenis.min.js').catch(function () {}); })
      .then(function () { return import(BASE + 'assets/js/motion.' + REL + '.js'); })
      .then(function (m) {
        document.documentElement.classList.add('gsap-ready');
        motion = m.init();
      })
      .catch(function (err) {
        // Nothing to undo. The page was never hidden, so it just stays static.
        if (window.console) { console.warn('DoctorFizz: motion layer unavailable.', err); }
      });
  }

  function startHero() {
    // r11 looked for a .stage element that the template never rendered, so the WebGL field
    // never started and the hero sat flat. The hero section itself is the flag.
    var stage = document.querySelector('.hero');
    if (!stage) { return; }
    if (!heroCapable()) { stage.classList.add('no-gl'); return; }

    // Loaded only after first paint, so the WebGL bundle never delays the text or the form.
    import(BASE + 'assets/js/hero.' + REL + '.js')
      .then(function (m) { return m.init(document.getElementById('hero-canvas')); })
      .catch(function () { stage.classList.add('no-gl'); });
  }

  /* ---------------------------------------------------------------- upgrade modal */

  var upgrade = (function upgradeModal() {
    var modal = document.getElementById('upgrade');
    if (!modal) { return { auto: function () {} }; }

    var card   = modal.querySelector('.modal-card');
    var opener = null;
    var seen   = false;

    // Remembered for this browsing session only, so it does not reappear on every page view.
    // Session storage is cleared when the tab closes and holds nothing identifying.
    try {
      seen = window.sessionStorage.getItem('df_upgrade_seen') === '1';
    } catch (e) { /* storage blocked, fall back to once per page load */ }

    function remember() {
      seen = true;
      try { window.sessionStorage.setItem('df_upgrade_seen', '1'); } catch (e) {}
    }

    function isOpen() { return !modal.hidden; }

    function open(fromEl) {
      if (isOpen()) { return; }
      opener = fromEl || null;
      modal.hidden = false;
      document.documentElement.style.overflow = 'hidden';
      var first = modal.querySelector('.modal-x');
      if (first) { first.focus(); }
      if (motion && typeof motion.openModal === 'function') { motion.openModal(card); }
      remember();
    }

    function close() {
      if (!isOpen()) { return; }
      modal.hidden = true;
      document.documentElement.style.overflow = '';
      if (opener && opener.focus) { opener.focus(); }
      opener = null;
    }

    document.addEventListener('click', function (ev) {
      var target = ev.target;
      if (!target || typeof target.closest !== 'function') { return; }

      var openBtn = target.closest('[data-open-upgrade]');
      if (openBtn) { ev.preventDefault(); open(openBtn); return; }

      // The backdrop and the X both carry data-close-upgrade.
      if (target.closest('[data-close-upgrade]')) { ev.preventDefault(); close(); }
    });

    document.addEventListener('keydown', function (ev) {
      if (!isOpen()) { return; }

      if (ev.key === 'Escape' || ev.key === 'Esc') { ev.preventDefault(); close(); return; }
      if (ev.key !== 'Tab') { return; }

      // Keep focus inside the dialog while it is open.
      var items = Array.prototype.slice.call(
        modal.querySelectorAll('a[href], button:not([disabled])')
      ).filter(function (n) { return n.offsetParent !== null; });
      if (!items.length) { return; }

      var first = items[0], lastItem = items[items.length - 1];
      if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); lastItem.focus(); }
      else if (!ev.shiftKey && document.activeElement === lastItem) { ev.preventDefault(); first.focus(); }
    });

    /* Offered once, 60 seconds in, and only when it will not interrupt anything. If the person
       is typing, has the tool focused, or a check is running, it waits and asks again later
       rather than landing on top of the thing they came for. */
    function auto() {
      var DELAY = 60000;
      var RETRY = 20000;

      function busy() {
        var active = document.activeElement;
        if (active && tool && tool.contains(active)) { return true; }
        if (tool && tool.classList.contains('busy')) { return true; }
        if (document.hidden) { return true; }
        return false;
      }

      function attempt() {
        if (seen || isOpen()) { return; }
        if (busy()) { window.setTimeout(attempt, RETRY); return; }
        open(null);
      }

      window.setTimeout(attempt, DELAY);
    }

    return { auto: auto, open: open, close: close };
  })();

  initRerun();
  loadSession();
  upgrade.auto();

  /* Motion starts as soon as the main thread is free, rather than waiting on the full load
     event, because a reveal that fires after the reader has already scrolled past is worse
     than no reveal at all. The WebGL hero still waits for idle. */
  startMotion();

  if ('requestIdleCallback' in window) {
    requestIdleCallback(function () { startHero(); }, { timeout: 2000 });
  } else {
    window.addEventListener('load', function () { setTimeout(startHero, 300); });
  }
})();
