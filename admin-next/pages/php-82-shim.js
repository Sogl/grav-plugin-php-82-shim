/**
 * PHP 8.2 Shim — Admin Next plugin page (official component contract).
 *
 * Tag assigned via window.__GRAV_PAGE_TAG; API calls go through
 * __GRAV_API_* globals with the X-API-Token header. Toolbar buttons live in
 * the page definition's `actions` (Refresh / Clear log) and arrive here as
 * `page-action` events. Dialogs via __GRAV_DIALOGS, feedback via
 * __GRAV_TOAST — never native confirm()/alert().
 *
 * Render model: the shell (header, status strip, toolbar, table head) is
 * built once; only #rows / #chips / #count are re-rendered on data change,
 * so search input keeps focus and caret while typing.
 */
const TAG = window.__GRAV_PAGE_TAG;

// Severity rails — deliberately only three tones: destructive for real
// failures, accent for ordinary shim activity, neutral for informational.
const FAILURE = new Set(['patch-fail', 'transpile-fail', 'unshimmable']);
const QUIET = new Set(['guarded', 'inert', 'heartbeat']);
const PROBLEM_RE = /-fail$|^unshimmable$/;

class Php82ShimPage extends HTMLElement {
  constructor() {
    super();
    this.attachShadow({ mode: 'open' });
    this._data = null;
    this._error = null;
    this._filter = '';
    this._query = '';
    this._loading = false;
    this._onPageAction = (e) => {
      if (e.detail?.id === 'refresh') this._load();
      if (e.detail?.id === 'clear') this._clear();
    };
    this._onSearch = (e) => { this._query = e.target.value; this._renderRows(); };
  }

  connectedCallback() {
    this.addEventListener('page-action', this._onPageAction);
    if (!this._built) {
      this._buildShell();
      this._built = true;
    }
    this._load();
  }

  disconnectedCallback() {
    this.removeEventListener('page-action', this._onPageAction);
  }

  _apiUrl(path) {
    return (window.__GRAV_API_SERVER_URL || '') +
           (window.__GRAV_API_PREFIX || '/api/v1') + path;
  }

  _headers() {
    const h = {};
    const token = window.__GRAV_API_TOKEN;
    if (token) h['X-API-Token'] = token;
    return h;
  }

  async _load() {
    if (this._loading) return; // no overlapping requests
    this._loading = true;
    this._renderBusy();
    try {
      const res = await fetch(this._apiUrl('/php82shim/log'), { headers: this._headers() });
      if (!res.ok) throw new Error(this._t('REQUEST_FAILED', 'Request failed: %s', res.status));
      const body = await res.json();
      this._data = body.data || body;
      this._error = null;
    } catch (err) {
      // Keep whatever data we had — a failed refresh must not wipe the log.
      this._error = err.message;
    }
    this._loading = false;
    this._renderBusy();
    this._renderStatus();
    this._renderChips();
    this._renderRows();
  }

  async _clear() {
    // Contract: never native confirm()/alert(). If the dialogs API is absent
    // (very old admin2), refuse the destructive action instead of falling
    // back to a browser dialog.
    if (!window.__GRAV_DIALOGS?.confirm) {
      window.__GRAV_TOAST?.warning(this._t('NO_DIALOGS', 'Clear needs a newer Admin2 build (no dialogs API)'));
      return;
    }
    const ok = await window.__GRAV_DIALOGS.confirm({
      title: this._t('CLEAR_TITLE', 'Clear the shim log?'),
      message: this._t('CLEAR_MSG', 'All recorded shim activity will be deleted.'),
      confirmLabel: this._t('CLEAR_CONFIRM', 'Clear log'),
      variant: 'destructive',
    });
    if (!ok) return;
    try {
      const res = await fetch(this._apiUrl('/php82shim/clear'), {
        method: 'POST',
        headers: this._headers(),
      });
      if (!res.ok) throw new Error('Clear failed: ' + res.status);
      this._data && (this._data.entries = []);
      window.__GRAV_TOAST?.success(this._t('CLEARED', 'Shim log cleared'));
    } catch (err) {
      window.__GRAV_TOAST?.error(err.message);
      this._error = err.message;
    }
    this._renderStatus();
    this._renderChips();
    this._renderRows();
  }

  /**
   * Server-translated UI strings (API ships `strings` keyed by these names,
   * resolved to the calling user's language). English fallbacks stay inline
   * so the page still renders if the endpoint predates i18n.
   */
  _t(key, fallback, ...args) {
    let s = (this._data && this._data.strings && this._data.strings[key]) || fallback;
    for (const a of args) s = s.replace('%s', a);
    return s;
  }

  _esc(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  /** Escape first, then wrap query matches in <mark> on the safe text. */
  _hl(s) {
    const esc = this._esc(s);
    const q = this._query.trim();
    if (!q) return esc;
    const eq = this._esc(q).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    try {
      return esc.replace(new RegExp('(' + eq + ')', 'gi'), '<mark>$1</mark>');
    } catch {
      return esc;
    }
  }

  _buildShell() {
    this.shadowRoot.innerHTML = `
      <style>
        :host { display:block; font-family:inherit; padding:1.5rem; color:var(--foreground,#223); }
        h1 { font-size:1.25rem; margin:0 0 .2rem; font-weight:600; }
        .sub { color:var(--muted-foreground,#667); font-size:.85rem; margin-bottom:1rem; }
        code { font-family:ui-monospace,Menlo,monospace; font-size:.8em; }

        /* Compact health summary — banners only appear on real problems */
        #health { display:flex; gap:1.2rem; flex-wrap:wrap; font-size:.85rem;
                  color:var(--muted-foreground,#667); margin-bottom:.9rem; }
        #health b { color:var(--foreground,#223); font-weight:600; }
        #health .good { color:var(--primary,#3b82f6); }
        #health .sick { color:var(--destructive,#dc2626); font-weight:600; }
        .alert { background:color-mix(in srgb, var(--destructive,#dc2626) 8%, transparent);
                 border:1px solid var(--destructive,#dc2626); padding:.6rem .9rem;
                 border-radius:.375rem; margin-bottom:.75rem; font-size:.85rem; }
        .notice { border:1px solid var(--border,#d4dbe4); color:var(--muted-foreground,#667);
                  padding:.45rem .9rem; border-radius:.375rem; margin-bottom:.75rem; font-size:.82rem; }

        .bar { display:flex; gap:.45rem; align-items:center; margin-bottom:.9rem; flex-wrap:wrap; }
        .chip { padding:.28rem .75rem; border:1px solid var(--border,#d4dbe4); border-radius:999px;
                background:transparent; cursor:pointer; font-size:.78rem; font-family:inherit;
                color:var(--muted-foreground,#667); }
        .chip:hover { border-color:var(--primary,#3b82f6); color:var(--primary,#3b82f6); }
        .chip.on { color:var(--primary,#3b82f6); border-color:var(--primary,#3b82f6);
                   background:color-mix(in srgb, var(--primary,#3b82f6) 12%, transparent); }
        .chip.prob.on { color:var(--destructive,#dc2626); border-color:var(--destructive,#dc2626);
                        background:color-mix(in srgb, var(--destructive,#dc2626) 10%, transparent); }
        .chip em { font-style:normal; opacity:.65; }
        .spacer { flex:1; }
        #search { padding:.4rem .8rem; border:1px solid var(--border,#d4dbe4); border-radius:.375rem;
                  background:var(--card,#fff); color:var(--foreground,#223); font-family:inherit;
                  font-size:.82rem; min-width:16rem; }
        #search:focus { outline:2px solid color-mix(in srgb, var(--primary,#3b82f6) 45%, transparent);
                        outline-offset:1px; border-color:var(--primary,#3b82f6); }
        #count { font-size:.78rem; color:var(--muted-foreground,#889); white-space:nowrap; }

        table { width:100%; border-collapse:collapse; background:var(--card,#fff);
                border:1px solid var(--border,#eef0f3); border-radius:.5rem; font-size:.85rem; }
        thead th { text-align:left; font-size:.72rem; text-transform:uppercase; letter-spacing:.05em;
                   color:var(--muted-foreground,#889); font-weight:600; padding:.5rem .7rem;
                   border-bottom:1px solid var(--border,#d4dbe4); }
        td { padding:.4rem .7rem; border-bottom:1px solid var(--border,#eef0f3); vertical-align:top; }
        td.ts { white-space:nowrap; color:var(--muted-foreground,#889); width:1%;
                font-family:ui-monospace,Menlo,monospace; font-size:.78rem; }
        td.ev { white-space:nowrap; width:1%; }
        td.detail { white-space:pre-wrap; overflow-wrap:anywhere; }
        tr.fail td:first-child { box-shadow:inset 3px 0 0 var(--destructive,#dc2626); }
        tr.act  td:first-child { box-shadow:inset 3px 0 0 var(--primary,#3b82f6); }
        .badge { display:inline-block; padding:.1rem .55rem; border-radius:999px; font-size:.72rem;
                 font-family:ui-monospace,Menlo,monospace; border:1px solid var(--border,#d4dbe4);
                 color:var(--muted-foreground,#667); }
        tr.fail .badge { color:var(--destructive,#dc2626); border-color:var(--destructive,#dc2626); }
        tr.act  .badge { color:var(--primary,#3b82f6); border-color:var(--primary,#3b82f6); }
        tr.empty td { color:var(--muted-foreground,#889); text-align:center; padding:1.5rem;
                      box-shadow:none; }
        mark { background:color-mix(in srgb, var(--primary,#3b82f6) 25%, transparent);
               color:inherit; border-radius:2px; padding:0 1px; }
        .spin { display:inline-block; animation:shim-spin 1s linear infinite; }
        @keyframes shim-spin { to { transform:rotate(360deg); } }
      </style>
      <h1 id="title">PHP 8.2 Shim — activity log</h1>
      <div class="sub"><span id="subtitle">What the shim caught &amp; rewrote (newest first)</span><span id="logpath"></span></div>
      <div id="health"></div>
      <div id="alerts"></div>
      <div class="bar">
        <span id="chips"></span>
        <span class="spacer"></span>
        <input id="search" type="search" placeholder="Search event or detail…" autocomplete="off">
        <span id="count"></span>
      </div>
      <table>
        <thead><tr><th>Time</th><th>Event</th><th>Detail</th></tr></thead>
        <tbody id="rows"><tr class="empty"><td colspan="3">Loading…</td></tr></tbody>
      </table>`;
    this.shadowRoot.getElementById('search').addEventListener('input', this._onSearch);
  }

  _renderBusy() {
    const c = this.shadowRoot.getElementById('count');
    if (this._loading) {
      c.innerHTML = '<span class="spin">◌</span> ' + this._esc(this._t('REFRESHING', 'refreshing…'));
    }
    // when not loading, #count is owned by _renderRows
  }

  _renderStatus() {
    const health = this.shadowRoot.getElementById('health');
    const alerts = this.shadowRoot.getElementById('alerts');
    if (!this._data) {
      health.innerHTML = '';
      alerts.innerHTML = this._error ? '<div class="alert">⚠ ' + this._esc(this._error) + '</div>' : '';
      return;
    }
    const d = this._data;
    // Translated shell strings arrive with the payload.
    this.shadowRoot.getElementById('title').textContent = this._t('LOG_TITLE', 'PHP 8.2 Shim — activity log');
    this.shadowRoot.getElementById('subtitle').textContent = this._t('LOG_SUBTITLE', 'What the shim caught & rewrote (newest first)');
    this.shadowRoot.getElementById('search').placeholder = this._t('SEARCH_PLACEHOLDER', 'Search event or detail…');
    const failed = d.failed || [];
    const hb = d.heartbeat_age;
    const native = !!d.native;
    // On real PHP 8.3+ the shim stands down — no heartbeat is expected.
    const hbDead = !native && (hb === null || hb === undefined || hb > 600);
    const parts = [
      '<span>' + this._esc(this._t('PATCHES_LABEL', 'Patches')) +
        ' <b class="' + (failed.length ? 'sick' : 'good') + '">' +
        this._esc(failed.length
          ? failed.length + ' ' + this._t('PATCHES_FAIL', 'failed')
          : this._t('PATCHES_OK', 'all in place')) + '</b></span>',
      '<span>' + this._esc(this._t('HEARTBEAT_LABEL', 'Heartbeat')) +
        ' <b class="' + (hbDead ? 'sick' : 'good') + '">' +
        this._esc(hb === null || hb === undefined ? this._t('HEARTBEAT_NEVER', 'never') :
          hb < 90 ? Math.max(1, Math.round(hb)) + 's ago' : Math.floor(hb / 60) + 'm ago') + '</b></span>',
      '<span>' + this._esc(this._t('PHP_LABEL', 'PHP')) + ' <b>' + this._esc(d.php_version || '?') +
        (native ? '</b></span>' : ' → 8.3</b></span>'),
      '<span>' + this._esc(this._t('ENTRIES', 'Entries')) + ' <b>' + (d.entries || []).length + '</b></span>',
    ];
    if (d.log_path) {
      this.shadowRoot.getElementById('logpath').innerHTML =
        ' · <code>' + this._esc(d.log_path) + '</code>';
    }
    health.innerHTML = parts.join('');

    // Banners only when something is actually wrong.
    let a = '';
    if (native) {
      a += '<div class="notice">' + this._esc(this._t('NATIVE_NOTICE',
        'Runtime is native PHP 8.3+ — the shim is standing down and can be disabled.')) + '</div>';
    }
    if (failed.length) {
      a += '<div class="alert">⚠ ' + this._esc(this._t('FAILED_ANCHORS',
        'Failed anchors: %s — upstream changed, rules need updating.', failed.join(', '))) + '</div>';
    }
    if (hbDead) {
      a += '<div class="alert">⚠ ' + this._esc(this._t('HEARTBEAT_ALERT',
        'Early-prepend heartbeat %s — .user.ini may not be live on this SAPI.',
        hb === null || hb === undefined ? this._t('HEARTBEAT_NEVER', 'never') : this._t('HEARTBEAT_STALE', 'stale'))) + '</div>';
    }
    if (this._error) {
      a += '<div class="notice">⚠ ' + this._esc(this._t('REFRESH_FAILED',
        'Last refresh failed (%s) — showing cached entries.', this._error)) + '</div>';
    }
    alerts.innerHTML = a;
  }

  _renderChips() {
    const entries = (this._data && this._data.entries) || [];
    const counts = {};
    let problems = 0;
    for (const e of entries) {
      counts[e.event] = (counts[e.event] || 0) + 1;
      if (PROBLEM_RE.test(e.event || '')) problems++;
    }
    const types = Object.keys(counts).sort();
    const chip = (key, label, n, cls) =>
      '<button class="chip ' + (cls || '') + (this._filter === key ? ' on' : '') +
      '" data-f="' + this._esc(key) + '">' + this._esc(label) +
      ' <em>' + n + '</em></button>';
    const evName = (e) => this._t('EVENT_' + String(e).toUpperCase().replace(/-/g, '_'), e);
    let html = chip('', this._t('ALL', 'all'), entries.length);
    html += chip('!problems', this._t('PROBLEMS', 'problems'), problems, 'prob');
    for (const t of types) html += chip(t, evName(t), counts[t]);
    this.shadowRoot.getElementById('chips').innerHTML = html;
    this.shadowRoot.querySelectorAll('.chip').forEach((c) =>
      c.addEventListener('click', () => {
        this._filter = c.dataset.f || '';
        this._renderChips();
        this._renderRows();
      })
    );
  }

  _matches(e) {
    if (this._filter === '!problems' && !PROBLEM_RE.test(e.event || '')) return false;
    if (this._filter && this._filter !== '!problems' && e.event !== this._filter) return false;
    const q = this._query.trim().toLowerCase();
    if (q && !((e.ts || '') + ' ' + (e.event || '') + ' ' + (e.detail || '')).toLowerCase().includes(q)) {
      return false;
    }
    return true;
  }

  _renderRows() {
    const tbody = this.shadowRoot.getElementById('rows');
    const count = this.shadowRoot.getElementById('count');
    if (this._loading && !this._data) return; // keep "Loading…"
    const all = (this._data && this._data.entries) || [];
    const entries = all.filter((e) => this._matches(e));

    count.textContent = entries.length === all.length
      ? all.length + ' ' + this._t('ENTRIES', 'entries')
      : entries.length + ' ' + this._t('OF', 'of') + ' ' + all.length;
    if (this._loading) {
      count.innerHTML = '<span class="spin">◌</span> ' + this._esc(count.textContent);
    }

    if (!all.length) {
      tbody.innerHTML = '<tr class="empty"><td colspan="3">' + this._esc(this._t('LOG_EMPTY', 'Log is empty — nothing shimmed yet.')) + '</td></tr>';
      return;
    }
    if (!entries.length) {
      const q = this._query.trim();
      const what = q ? this._t('MATCHING', 'matching “%s”', q) :
        this._filter === '!problems' ? this._t('WITH_PROBLEMS', 'with problems')
          : this._t('OF_TYPE', 'of type “%s”', this._filter);
      tbody.innerHTML = '<tr class="empty"><td colspan="3">' + this._esc(this._t('NO_ENTRIES', 'No entries %s.', what)) + '</td></tr>';
      return;
    }

    tbody.innerHTML = entries.map((e) => {
      const cls = FAILURE.has(e.event) ? 'fail' : (QUIET.has(e.event) ? '' : 'act');
      return '<tr class="' + cls + '"><td class="ts">' + this._esc(e.ts) + '</td>' +
        '<td class="ev"><span class="badge">' + this._esc(e.event) + '</span></td>' +
        '<td class="detail">' + this._hl(e.detail) + '</td></tr>';
    }).join('');
  }
}

if (TAG && !customElements.get(TAG)) {
  customElements.define(TAG, Php82ShimPage);
}
