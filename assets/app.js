(function () {
  'use strict';

  function attach(input) {
    var box = document.createElement('div');
    box.className = 'ac-list';
    box.setAttribute('role', 'listbox');
    input.parentNode.style.position = 'relative';
    input.parentNode.appendChild(box);

    var items = [], active = -1, timer = null, lastQuery = '';

    function close() {
      box.innerHTML = '';
      box.style.display = 'none';
      active = -1;
      input.setAttribute('aria-expanded', 'false');
    }

    function choose(i) {
      if (i < 0 || i >= items.length) return;
      input.value = items[i].canonical;
      close();
      input.dispatchEvent(new Event('change'));
    }

    function paint() {
      box.innerHTML = '';
      if (!items.length) { close(); return; }
      items.forEach(function (loc, i) {
        var row = document.createElement('div');
        row.className = 'ac-item' + (i === active ? ' is-active' : '');
        row.setAttribute('role', 'option');
        row.innerHTML =
          '<span class="ac-name"></span><span class="ac-type"></span>';
        row.querySelector('.ac-name').textContent = loc.canonical;
        row.querySelector('.ac-type').textContent = loc.type;
        row.addEventListener('mousedown', function (e) {
          e.preventDefault();
          choose(i);
        });
        box.appendChild(row);
      });
      box.style.display = 'block';
      input.setAttribute('aria-expanded', 'true');
    }

    function query() {
      var q = input.value.trim();
      if (q.length < 2) { close(); return; }
      if (q === lastQuery) return;
      lastQuery = q;
      fetch('locations.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : []; })
        .then(function (data) {
          items = Array.isArray(data) ? data : [];
          active = -1;
          paint();
        })
        .catch(function () { close(); });
    }

    input.setAttribute('autocomplete', 'off');
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');

    input.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(query, 180);
    });

    input.addEventListener('keydown', function (e) {
      if (box.style.display !== 'block') return;
      if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, items.length - 1); paint(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); paint(); }
      else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); choose(active); }
      else if (e.key === 'Escape') { close(); }
    });

    input.addEventListener('blur', function () { setTimeout(close, 120); });
    input.addEventListener('focus', function () { lastQuery = ''; query(); });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var inputs = document.querySelectorAll('[data-autocomplete="location"]');
    for (var i = 0; i < inputs.length; i++) attach(inputs[i]);
  });
})();

/* Live status polling. Any element carrying data-live-tracker gets refreshed
   while a check is in progress, and the page reloads once it completes. */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var nodes = document.querySelectorAll('[data-live-tracker]');
    if (!nodes.length) return;

    var ids = [];
    for (var i = 0; i < nodes.length; i++) {
      ids.push(nodes[i].getAttribute('data-live-tracker'));
    }

    var wasRunning = {};
    var ticks = 0;

    function paint(t) {
      var el = document.querySelector('[data-live-tracker="' + t.id + '"]');
      if (!el) return;

      if (t.running) {
        wasRunning[t.id] = true;
        el.className = 'pill pill-idle is-live';
        el.textContent = 'Checking now, ' + t.running_for + 's';
        return;
      }

      // A check that was in flight has finished. Reload so every panel on the
      // page reflects the new snapshot rather than patching pieces of it.
      if (wasRunning[t.id]) {
        window.location.reload();
        return;
      }

      el.className = 'pill ' + (t.last_status === 'failed' ? 'pill-err'
                    : (t.has_ads ? 'pill-run' : 'pill-idle'));
      el.textContent = t.label;
    }

    function poll() {
      fetch('status.php?ids=' + ids.join(','), { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (!d || !d.trackers) return;
          var anyRunning = false;
          d.trackers.forEach(function (t) {
            paint(t);
            if (t.running) anyRunning = true;
          });
          ticks++;
          // Poll briskly while something is running, then back off, and stop
          // after roughly five idle minutes so an open tab is not a load.
          var next = anyRunning ? 3000 : (ticks > 100 ? 0 : 15000);
          if (next) setTimeout(poll, next);
        })
        .catch(function () { setTimeout(poll, 30000); });
    }

    poll();
  });
})();

/* Bulk selection on the client page. Plain functions on window so the markup
   can call them directly without wiring listeners to every row. */
(function () {
  'use strict';

  function rows()   { return Array.prototype.slice.call(document.querySelectorAll('.selrow')); }
  function chosen() { return rows().filter(function (r) { return r.checked; }); }

  window.syncSel = function () {
    var n = chosen().length;
    var bar = document.getElementById('bulkbar');
    var cnt = document.getElementById('bulkn');
    if (cnt) cnt.textContent = String(n);
    if (bar) bar.hidden = n === 0;

    var all = document.getElementById('selall');
    if (all) {
      all.checked = n > 0 && n === rows().length;
      all.indeterminate = n > 0 && n < rows().length;
    }

    // A group checkbox reflects only its own rows.
    Array.prototype.forEach.call(document.querySelectorAll('.group'), function (g) {
      var box = g.querySelector('.selgroup');
      if (!box) return;
      var mine = Array.prototype.slice.call(g.querySelectorAll('.selrow'));
      var hit  = mine.filter(function (m) { return m.checked; }).length;
      box.checked = hit > 0 && hit === mine.length;
      box.indeterminate = hit > 0 && hit < mine.length;
    });
  };

  window.toggleAll = function (el) {
    rows().forEach(function (r) { r.checked = el.checked; });
    window.syncSel();
  };

  window.toggleGroup = function (el) {
    var g = el.closest('.group');
    if (!g) return;
    Array.prototype.forEach.call(g.querySelectorAll('.selrow'), function (r) { r.checked = el.checked; });
    window.syncSel();
  };

  window.clearSel = function () {
    rows().forEach(function (r) { r.checked = false; });
    window.syncSel();
  };

  window.runBulk = function (op) {
    var n = chosen().length;
    if (!n) return;

    var ask = {
      'delete': 'Delete ' + n + ' keyword(s) and everything they have recorded? This cannot be undone.',
      'check' : 'Check ' + n + ' keyword(s) right now? Each one uses a search from your plan.',
      'stop'  : null,
      'start' : null
    }[op];
    if (ask && !window.confirm(ask)) return;

    document.getElementById('bulkop').value = op;
    document.getElementById('bulkform').submit();
  };

  document.addEventListener('DOMContentLoaded', function () {
    if (document.querySelector('.selrow')) window.syncSel();
  });
})();

/* Picking a location from the finder appends it to the locations box.
   Skipped when the Adrecon builder is present (it uses chips instead). */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    if (document.getElementById('builderForm')) return;
    var finder = document.getElementById('loclookup');
    var target = document.getElementById('locs');
    if (!finder || !target) return;

    finder.addEventListener('change', function () {
      var v = finder.value.trim();
      if (!v) return;
      var lines = target.value.split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
      if (lines.indexOf(v) === -1) lines.push(v);
      target.value = lines.join('\n') + '\n';
      finder.value = '';
      target.focus();
    });
  });
})();

/* Moving a selection onto one of the client's websites. */
window.moveToSite = function (el) {
  var v = el.value;
  if (v === '') return;
  var boxes = document.querySelectorAll('.selrow:checked');
  if (!boxes.length) { el.value = ''; return; }
  var label = el.options[el.selectedIndex].text;
  if (!window.confirm('File ' + boxes.length + ' keyword(s) under ' + label + '?')) {
    el.value = '';
    return;
  }
  document.getElementById('bulksite').value = v;
  document.getElementById('bulkop').value = 'move';
  document.getElementById('bulkform').submit();
};

/* Changing the interval of a selection. */
window.changeInterval = function (el) {
  var v = el.value;
  if (v === '') return;
  var boxes = document.querySelectorAll('.selrow:checked');
  if (!boxes.length) { el.value = ''; return; }
  var label = el.options[el.selectedIndex].text;
  if (!window.confirm('Check ' + boxes.length + ' keyword(s) ' + label.toLowerCase() + ' from now on?')) {
    el.value = '';
    return;
  }
  document.getElementById('bulkinterval').value = v;
  document.getElementById('bulkop').value = 'interval';
  document.getElementById('bulkform').submit();
};

/* Changing how long a selection runs for. */
window.changeDuration = function (el) {
  var v = el.value;
  if (v === '') return;
  var boxes = document.querySelectorAll('.selrow:checked');
  if (!boxes.length) { el.value = ''; return; }
  var label = el.options[el.selectedIndex].text;
  if (!window.confirm('Run ' + boxes.length + ' keyword(s) for ' + label +
      '? Anything already running gets a fresh end date starting today.')) {
    el.value = '';
    return;
  }
  document.getElementById('bulkduration').value = v;
  document.getElementById('bulkop').value = 'duration';
  document.getElementById('bulkform').submit();
};

/* ---------- Adrecon Phase 1: keyword builder ---------- */
(function () {
  'use strict';

  var selected = []; // {term, cluster}
  var locations = [];

  function findForm() {
    return document.getElementById('builderForm');
  }

  window.kwMode = function (mode) {
    var lib = document.getElementById('kwLib');
    var own = document.getElementById('kwOwn');
    var segLib = document.getElementById('segLib');
    var segOwn = document.getElementById('segOwn');
    if (!lib || !own) return;
    var isLib = mode === 'lib';
    lib.classList.toggle('hidden', !isLib);
    own.classList.toggle('hidden', isLib);
    if (segLib) segLib.classList.toggle('active', isLib);
    if (segOwn) segOwn.classList.toggle('active', !isLib);
  };

  window.filterLib = function (btn) {
    var cluster = btn.getAttribute('data-cluster');
    document.querySelectorAll('#libClusters .lib-cl').forEach(function (b) {
      b.classList.toggle('active', b === btn);
    });
    document.querySelectorAll('#libList .libitem').forEach(function (row) {
      var show = cluster === '__all' || row.getAttribute('data-cluster') === cluster;
      row.style.display = show ? '' : 'none';
    });
  };

  function paintSelected() {
    var box = document.getElementById('selKw');
    var cnt = document.getElementById('kwCount');
    if (!box) return;
    box.innerHTML = '';
    selected.forEach(function (item, i) {
      var chip = document.createElement('span');
      chip.className = 'kwchip';
      chip.innerHTML = '<span></span>' +
        (item.cluster ? '<small></small>' : '') +
        '<b title="Remove">×</b>';
      chip.querySelector('span').textContent = item.term;
      if (item.cluster) chip.querySelector('small').textContent = item.cluster;
      chip.querySelector('b').addEventListener('click', function () {
        selected.splice(i, 1);
        syncLibChecks();
        paintSelected();
        renderPreview();
      });
      box.appendChild(chip);
    });
    if (cnt) cnt.textContent = String(selected.length);
    if (!selected.length) {
      box.innerHTML = '<span class="muted-note" style="color:var(--muted);font-size:12.5px">No keywords selected yet.</span>';
    }
  }

  function paintLocations() {
    var box = document.getElementById('selLoc');
    if (!box) return;
    box.innerHTML = '';
    locations.forEach(function (loc, i) {
      var chip = document.createElement('span');
      chip.className = 'locchip';
      chip.innerHTML = '<span></span><b title="Remove">×</b>';
      chip.querySelector('span').textContent = loc;
      chip.querySelector('b').addEventListener('click', function () {
        locations.splice(i, 1);
        paintLocations();
        renderPreview();
      });
      box.appendChild(chip);
    });
    if (!locations.length) {
      box.innerHTML = '<span style="color:var(--muted);font-size:12.5px">No locations yet.</span>';
    }
  }

  function syncLibChecks() {
    var terms = {};
    selected.forEach(function (s) { terms[s.term.toLowerCase()] = true; });
    document.querySelectorAll('#libList input[type=checkbox]').forEach(function (cb) {
      var t = (cb.getAttribute('data-term') || '').toLowerCase();
      var on = !!terms[t];
      cb.checked = on;
      var mark = cb.parentElement && cb.parentElement.querySelector('.added');
      if (mark) mark.hidden = !on;
    });
  }

  window.toggleLib = function (cb) {
    var term = cb.getAttribute('data-term') || '';
    var cluster = cb.getAttribute('data-cluster') || '';
    var key = term.toLowerCase();
    var idx = selected.findIndex(function (s) { return s.term.toLowerCase() === key; });
    if (cb.checked) {
      if (idx === -1) selected.push({ term: term, cluster: cluster });
    } else if (idx !== -1) {
      selected.splice(idx, 1);
    }
    syncLibChecks();
    paintSelected();
    renderPreview();
  };

  window.addOwn = function () {
    var termEl = document.getElementById('ownTerm');
    var clEl = document.getElementById('ownCluster');
    if (!termEl) return;
    var term = termEl.value.trim();
    if (!term) return;
    var cluster = clEl ? clEl.value : '';
    if (cluster === '__new') {
      var name = window.prompt('New cluster name');
      if (!name || !name.trim()) return;
      cluster = name.trim();
      var opt = document.createElement('option');
      opt.value = cluster;
      opt.textContent = cluster;
      clEl.insertBefore(opt, clEl.querySelector('option[value="__new"]'));
      clEl.value = cluster;
    }
    if (cluster === 'No cluster') cluster = '';
    var key = term.toLowerCase();
    if (selected.some(function (s) { return s.term.toLowerCase() === key; })) {
      termEl.value = '';
      return;
    }
    selected.push({ term: term, cluster: cluster });
    termEl.value = '';
    syncLibChecks();
    paintSelected();
    renderPreview();
  };

  window.clearKw = function () {
    selected = [];
    syncLibChecks();
    paintSelected();
    renderPreview();
  };

  function addLocation(v) {
    v = (v || '').trim();
    if (!v) return;
    if (locations.some(function (l) { return l.toLowerCase() === v.toLowerCase(); })) return;
    locations.push(v);
    paintLocations();
    renderPreview();
  }

  window.addLocFromLookup = function () {
    var finder = document.getElementById('loclookup');
    if (!finder) return;
    addLocation(finder.value);
    finder.value = '';
    finder.focus();
  };

  window.addNearby = function () {
    var finder = document.getElementById('loclookup');
    var anchor = (finder && finder.value.trim()) || locations[0] || '';
    // Prototype helper towns (Florida pack) — used when the lookup returns little.
    var NEARBY = [
      'Lakeland, Florida, United States',
      'Winter Haven, Florida, United States',
      'Auburndale, Florida, United States',
      'Lake Wales, Florida, United States'
    ];
    function applyList(list) {
      var added = 0;
      (list || []).forEach(function (c) {
        if (added >= 4) return;
        if (typeof c === 'object') c = c.canonical || c.name || '';
        if (!c) return;
        var dup = locations.some(function (l) { return l.toLowerCase() === c.toLowerCase(); });
        var same = anchor && c.toLowerCase() === anchor.toLowerCase();
        if (!dup && !same) {
          addLocation(c);
          added++;
        }
      });
      return added;
    }
    if (!anchor) {
      applyList(NEARBY);
      if (!locations.length) {
        window.alert('Add a location first, then we can suggest nearby towns.');
      }
      return;
    }
    var seed = anchor.split(',')[0].trim();
    fetch('locations.php?q=' + encodeURIComponent(seed), { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : []; })
      .then(function (list) {
        var added = applyList(list);
        if (!added) added = applyList(NEARBY);
        if (!added) {
          window.alert('No nearby towns found for "' + seed + '". Try a city name with state.');
        }
      })
      .catch(function () {
        applyList(NEARBY);
      });
  };

  function spySetNote(text, kind) {
    var note = document.getElementById('spyNote');
    if (!note) return;
    note.hidden = !text;
    note.textContent = text || '';
    note.classList.remove('is-ok', 'is-err');
    if (kind) note.classList.add(kind);
  }

  function parseSpyTarget(raw) {
    raw = (raw || '').trim();
    var domain = null;
    var brand = raw;
    var urlTry = raw.replace(/^https?:\/\//i, '').replace(/^www\./i, '');
    var hostMatch = urlTry.match(/^([a-z0-9-]+(?:\.[a-z0-9-]+)+)(?:[\/?#]|$)/i);
    if (hostMatch) {
      domain = hostMatch[1].toLowerCase();
      brand = domain.split('.')[0].replace(/[-_]+/g, ' ');
    }
    brand = brand.replace(/\s+/g, ' ').trim();
    var words = brand.toLowerCase().replace(/[^a-z0-9\s]/g, ' ').split(/\s+/).filter(function (w) {
      return w.length >= 3;
    });
    return { raw: raw, domain: domain, brand: brand, words: words };
  }

  function ensureSelected(term, cluster) {
    term = (term || '').trim();
    if (!term) return false;
    var key = term.toLowerCase();
    if (selected.some(function (s) { return s.term.toLowerCase() === key; })) return false;
    selected.push({ term: term, cluster: cluster || '' });
    return true;
  }

  /** One-Click Spy: draft keywords from a business name / website into the builder. */
  window.runOneClickSpy = function () {
    if (!findForm()) return;
    var spy = document.getElementById('spyInput');
    var raw = spy ? spy.value.trim() : '';
    if (!raw) {
      spySetNote('Enter a business name or website first.', 'is-err');
      if (spy) spy.focus();
      return;
    }

    var target = parseSpyTarget(raw);
    var brand = target.brand || raw;
    var added = 0;
    var fromLib = 0;

    // 1) Match library terms to brand words / industry hints.
    var hints = target.words.slice();
    ['polaris', 'rzr', 'ranger', 'atv', 'utv', 'rv', 'airstream', 'dealer'].forEach(function (h) {
      if (target.raw.toLowerCase().indexOf(h) !== -1 && hints.indexOf(h) === -1) hints.push(h);
    });

    document.querySelectorAll('#libList .libitem input[type="checkbox"]').forEach(function (cb) {
      var term = (cb.getAttribute('data-term') || '').toLowerCase();
      var cluster = cb.getAttribute('data-cluster') || '';
      var hit = hints.some(function (w) { return term.indexOf(w) !== -1; });
      if (hit) {
        if (ensureSelected(cb.getAttribute('data-term'), cluster)) {
          added++;
          fromLib++;
        }
        cb.checked = true;
      }
    });

    // 2) Always draft brand-intent keywords (core of One-Click Spy without LLM).
    var brandTerms = [
      { term: brand + ' dealer', cluster: 'Dealer intent' },
      { term: brand + ' dealer near me', cluster: 'Dealer intent' },
      { term: brand + ' for sale', cluster: 'Purchase intent' },
      { term: brand + ' near me', cluster: 'Dealer intent' }
    ];
    brandTerms.forEach(function (item) {
      if (ensureSelected(item.term, item.cluster)) added++;
    });

    // 3) If nothing from library matched, pull the Dealer intent starter pack.
    if (fromLib === 0) {
      document.querySelectorAll('#libList .libitem input[type="checkbox"]').forEach(function (cb) {
        if ((cb.getAttribute('data-cluster') || '') !== 'Dealer intent') return;
        if (ensureSelected(cb.getAttribute('data-term'), 'Dealer intent')) {
          added++;
          fromLib++;
        }
        cb.checked = true;
      });
    }

    // 4) Mark their domain in the report highlighter when we got a website.
    if (target.domain) {
      var watch = document.querySelector('#builderForm input[name="watch_domains"]');
      if (watch && !watch.value.trim()) watch.value = target.domain;
      var siteSel = document.getElementById('sid');
      if (siteSel) {
        for (var i = 0; i < siteSel.options.length; i++) {
          if ((siteSel.options[i].text || '').toLowerCase().indexOf(target.domain) !== -1) {
            siteSel.selectedIndex = i;
            break;
          }
        }
      }
    }

    kwMode('lib');
    syncLibChecks();
    paintSelected();
    renderPreview();

    spySetNote(
      'Drafted ' + selected.length + ' keyword' + (selected.length === 1 ? '' : 's') +
        ' for “' + brand + '”. Add locations below, review the list, then Add to tracking.',
      'is-ok'
    );

    var sel = document.getElementById('selKw');
    if (sel) sel.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };

  // Back-compat for any leftover onclick handlers.
  window.revealSpyManual = window.runOneClickSpy;

  window.renderPreview = function () {
    if (!findForm()) return;
    var kwN = selected.length;
    var locN = locations.length;
    var deviceEl = document.getElementById('dev');
    var ivEl = document.getElementById('iv');
    var deviceMul = deviceEl && deviceEl.value === 'both' ? 2 : 1;
    var checks = kwN * locN * deviceMul;
    var hours = ivEl ? parseInt(ivEl.value, 10) : 6;
    var perMonth = hours > 0 ? Math.round(checks * (730 / hours)) : checks;

    var set = function (id, text) {
      var el = document.getElementById(id);
      if (el) el.textContent = text;
    };
    set('sumChecks', String(checks));
    set('sumKw', String(kwN));
    set('sumLoc', String(locN));
    set('sumMo', perMonth.toLocaleString());
    set('sumEq', checks
      ? 'checks per run — ' + kwN + ' × ' + locN + (deviceMul > 1 ? ' × 2 devices' : '')
      : 'checks per run — pick keywords & locations');
    if (ivEl) {
      set('sumFreq', ivEl.options[ivEl.selectedIndex].text);
    }
    var fit = document.getElementById('sumFit');
    if (fit) {
      fit.textContent = checks
        ? ('About ' + perMonth.toLocaleString() + ' searches/month at this cadence (before the run window ends).')
        : 'Pick some keywords and locations to see the plan.';
    }
  };

  window.prepareBuilderSubmit = function () {
    if (!selected.length || !locations.length) {
      window.alert('Add at least one keyword and one location.');
      return false;
    }
    document.getElementById('kws').value = selected.map(function (s) { return s.term; }).join('\n');
    document.getElementById('kwClusters').value = selected.map(function (s) { return s.cluster || ''; }).join('\n');
    document.getElementById('locs').value = locations.join('\n');
    return true;
  };

  document.addEventListener('DOMContentLoaded', function () {
    if (!findForm()) return;
    paintSelected();
    paintLocations();
    renderPreview();

    // Location autocomplete: choosing a result adds a chip (builder mode).
    var finder = document.getElementById('loclookup');
    if (finder) {
      finder.addEventListener('change', function () {
        var v = finder.value.trim();
        if (!v) return;
        addLocation(v);
        finder.value = '';
      });
    }

    var spyBtn = document.getElementById('spyBtn');
    var spyInput = document.getElementById('spyInput');
    if (spyBtn) {
      spyBtn.addEventListener('click', function (e) {
        e.preventDefault();
        window.runOneClickSpy();
      });
    }
    if (spyInput) {
      spyInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          window.runOneClickSpy();
        }
      });
    }
  });
})();

/* ---------- Agency → Client (dealer) context switcher ---------- */
(function () {
  'use strict';
  document.addEventListener('DOMContentLoaded', function () {
    var wrap = document.getElementById('ctxWrap');
    var toggle = document.getElementById('ctxToggle');
    var menu = document.getElementById('ctxMenu');
    if (!wrap || !toggle || !menu) return;

    var agencyPane = document.getElementById('ctxAgencies');

    function showPane(el) {
      menu.querySelectorAll('.ctx-pane').forEach(function (p) { p.hidden = true; });
      if (el) el.hidden = false;
    }

    function openMenu() {
      menu.hidden = false;
      toggle.setAttribute('aria-expanded', 'true');
      var onClient = menu.querySelector('.ctx-clients a.ctx-item.on');
      if (onClient) {
        var pane = onClient.closest('.ctx-clients');
        if (pane) { showPane(pane); return; }
      }
      showPane(agencyPane);
    }

    function closeMenu() {
      menu.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
      showPane(agencyPane);
    }

    toggle.addEventListener('click', function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (menu.hidden) openMenu();
      else closeMenu();
    });

    menu.addEventListener('click', function (e) {
      e.stopPropagation();
      var back = e.target.closest('[data-back]');
      if (back) {
        e.preventDefault();
        showPane(agencyPane);
        return;
      }
      var agencyBtn = e.target.closest('.ctx-agency-btn');
      if (agencyBtn) {
        e.preventDefault();
        var id = agencyBtn.getAttribute('data-agency');
        var pane = document.getElementById('ctxClients-' + id);
        if (pane) showPane(pane);
      }
    });

    document.addEventListener('click', function () { closeMenu(); });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeMenu();
    });
  });
})();
