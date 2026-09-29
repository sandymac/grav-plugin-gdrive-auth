/**
 * `gdrive-guide` field: the Guided setup tab on the gdrive plugin's settings
 * page (Admin2 custom field).
 *
 * Asks which Google account, which kind, and (Workspace only) about Shared
 * Drives and admin rights, then shows GET /gdrive/guide: the one method
 * guide filtered to those answers, rendered server side from docs/setup/*.md.
 * The email only picks the kind here, in the browser; it's never sent.
 * Answers persist per viewer in localStorage (optional: works without it).
 *
 * Display only: it never dispatches `change` (the blueprint also sets
 * validate.ignore). Self-contained, because Admin2 imports each field file on
 * its own from a blob: URL. No form element: Admin2 renders fields inside its own.
 */

const TAG = window.__GRAV_FIELD_TAG;
const KEY = 'gdrive.guide.v1';
const PROJECT_RE = /^[a-z][a-z0-9-]{4,28}[a-z0-9]$/;
// Addresses that can't be Google Workspace. ponytail: a short list; any other domain pre-selects Workspace, and the radio corrects it.
const PERSONAL_RE = /@(gmail|googlemail|outlook|hotmail|live|msn|yahoo|ymail|icloud|me|mac|aol|proton|protonmail|gmx)(\.[a-z]+)+$/i;
const DEFAULTS = { email: '', kind: 'gmail', shared_drive: '', admin: '', method: '', project: '' };
const CHOICES = { kind: ['gmail', 'workspace'], shared_drive: ['', 'yes', 'no', 'unsure'], admin: ['', 'yes', 'no'], method: ['', 'oauth', 'sa'] };

class GdriveGuide extends HTMLElement {
    constructor() {
        super();
        this.attachShadow({ mode: 'open' });
        this._field = null;
        this._value = null;
        this._state = load();
        this._timer = null;
        this._seq = 0;
        this._account = ''; // the server's suggested account name, for the Accounts tab prefill
    }

    set field(v) { this._field = v; }
    get field() { return this._field; }
    set value(v) { this._value = v; }
    get value() { return this._value; }

    connectedCallback() {
        this._renderShell();
        this._sync();
        this._fetch();
    }

    disconnectedCallback() {
        clearTimeout(this._timer);
    }

    // ─── API ────────────────────────────────────────────────────────────

    _url(path) {
        return (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1') + path;
    }

    /** Reads the token at call time (Admin2 refreshes it), retries once on a 401. Throws {status, detail}. */
    async _call(path, retried = false) {
        const headers = { Accept: 'application/json' };
        if (window.__GRAV_API_TOKEN) headers['X-API-Token'] = window.__GRAV_API_TOKEN;
        if (window.__GRAV_ENVIRONMENT) headers['X-Grav-Environment'] = headers['X-Config-Environment'] = window.__GRAV_ENVIRONMENT;
        let resp;
        try {
            resp = await fetch(this._url(path), { method: 'GET', headers });
        } catch (e) {
            throw { status: 0, detail: 'The site could not be reached. Check your connection and try again.' };
        }
        if (resp.status === 401 && !retried) {
            await new Promise((r) => setTimeout(r, 400));
            return this._call(path, true);
        }
        const json = await resp.json().catch(() => ({}));
        if (!resp.ok) throw { status: resp.status, detail: json.detail || json.title || `HTTP ${resp.status}` };
        return json.data ?? json;
    }

    /** The profile sent to the server: no email, and the Workspace answers only for Workspace. */
    _query() {
        const s = this._state;
        const ws = s.kind === 'workspace';
        return new URLSearchParams({
            kind: s.kind,
            method: this._method(),
            shared_drive: ws ? s.shared_drive : '',
            admin: ws ? (s.admin || 'no') : '',
            project: PROJECT_RE.test(s.project) ? s.project : '',
        }).toString();
    }

    async _fetch() {
        const seq = ++this._seq;
        const out = this.shadowRoot.querySelector('.out');
        out.setAttribute('aria-busy', 'true');
        let html;
        try {
            const data = await this._call(`/gdrive/guide?${this._query()}`);
            html = data.html || '';
            this._account = String(data.account || '');
        } catch (e) {
            const text = e.status === 404
                ? 'The Google Drive Auth plugin’s API isn’t available. Enable the plugin, save, and reload this page.'
                : e.status === 403 ? 'You need the “Manage Google Drive accounts” permission (api.gdrive.manage) to see the guided setup.' : e.detail;
            html = `<p class="note bad" role="alert">${esc(text)}</p>`;
        }
        if (seq !== this._seq) return; // an answer changed while this was in flight
        out.innerHTML = html; // our own server-rendered markdown (Parsedown safe mode)
        out.querySelectorAll('a[href^="http"]').forEach((a) => { a.target = '_blank'; a.rel = 'noopener noreferrer'; });
        out.removeAttribute('aria-busy');
    }

    // ─── State ──────────────────────────────────────────────────────────

    /** OAuth unless the viewer picked the service account. */
    _method() {
        return this._state.method || 'oauth';
    }

    _set(patch) {
        Object.assign(this._state, patch);
        try { localStorage.setItem(KEY, JSON.stringify(this._state)); } catch { /* private window, blocked storage */ }
        this._sync();
        clearTimeout(this._timer);
        this._timer = setTimeout(() => this._fetch(), 250);
    }

    _onInput(e) {
        const t = e.target;
        if (t.id === 'email') {
            const kind = kindOf(t.value);
            this._set(kind ? { email: t.value, kind } : { email: t.value });
        } else if (t.id === 'project') {
            this._set({ project: t.value.trim() });
        } else if (t.type === 'radio' && t.checked && t.name in CHOICES) {
            this._set({ [t.name]: t.value });
        }
    }

    // ─── Render ─────────────────────────────────────────────────────────

    _renderShell() {
        const radio = (name, value, label) =>
            `<label class="opt"><input type="radio" name="${name}" value="${value}"> <span>${label}</span></label>`;
        this.shadowRoot.innerHTML = `<style>${STYLE}</style>
            <div class="qs">
                <div class="q">
                    <label for="email" class="ql">Which Google account will you use?</label>
                    <input id="email" type="email" autocomplete="email" spellcheck="false" placeholder="you@gmail.com" aria-describedby="email-hint">
                    <span class="hint" id="email-hint">Only used here, in your browser, to guess the kind of account below. It isn’t sent anywhere.</span>
                    <fieldset>
                        <legend class="sr">Kind of Google account</legend>
                        ${radio('kind', 'gmail', 'Personal Google account (Gmail, or a personal account on your own email address)')}
                        ${radio('kind', 'workspace', 'Google Workspace (managed by an organisation)')}
                    </fieldset>
                </div>
                <div class="q ws">
                    <fieldset>
                        <legend class="ql">Can you add members to a Shared Drive?</legend>
                        <div class="row">${radio('shared_drive', 'yes', 'Yes')}${radio('shared_drive', 'no', 'No')}${radio('shared_drive', 'unsure', 'Not sure')}</div>
                    </fieldset>
                    <fieldset>
                        <legend class="ql">Are you a Google Workspace administrator?</legend>
                        <div class="row">${radio('admin', 'yes', 'Yes')}${radio('admin', 'no', 'No')}</div>
                    </fieldset>
                </div>
                <div class="q">
                    <fieldset>
                        <legend class="ql">How should the site sign in to Google?</legend>
                        ${radio('method', 'oauth', 'OAuth: the site acts as your Google account <em>(recommended)</em>')}
                        ${radio('method', 'sa', 'Service account: a Google identity of its own, with a key file <em class="fit">(a good fit: Workspace with a Shared Drive)</em>')}
                    </fieldset>
                    <p class="note warn sa-note" role="note"></p>
                </div>
                <div class="q">
                    <label for="project" class="ql">Google Cloud project ID <span class="muted">(optional)</span></label>
                    <input id="project" type="text" autocomplete="off" spellcheck="false" maxlength="30" placeholder="my-website-123456" aria-describedby="project-hint project-err">
                    <span class="hint" id="project-hint">If you already have one, the console links below open it directly. It’s under the project picker at the top of the Cloud console.</span>
                    <span class="note bad" id="project-err" role="alert"></span>
                </div>
            </div>
            <div class="out" aria-live="polite"><p class="muted">Loading your steps…</p></div>`;
        const root = this.shadowRoot;
        root.addEventListener('input', (e) => { e.stopPropagation(); this._onInput(e); });
        root.addEventListener('change', (e) => { e.stopPropagation(); this._onInput(e); });
        root.addEventListener('keydown', (e) => { // Enter would otherwise submit Admin2's settings form
            if (e.key === 'Enter' && e.target.tagName === 'INPUT') e.preventDefault();
        });
        root.addEventListener('click', (e) => this._onLink(e));
        const s = this._state;
        root.querySelector('#email').value = s.email;
        root.querySelector('#project').value = s.project;
    }

    /** Reflects the state into the controls without rebuilding them (keeps focus and typing). */
    _sync() {
        const root = this.shadowRoot;
        const s = this._state;
        const ws = s.kind === 'workspace';
        const values = { kind: s.kind, shared_drive: s.shared_drive, admin: ws ? (s.admin || 'no') : '', method: this._method() };
        root.querySelectorAll('input[type=radio]').forEach((r) => { r.checked = values[r.name] === r.value; });
        root.querySelector('.ws').hidden = !ws;
        const goodFit = ws && s.shared_drive === 'yes';
        root.querySelector('.fit').hidden = !goodFit;
        const note = root.querySelector('.sa-note');
        note.hidden = this._method() !== 'sa' || goodFit;
        note.textContent = ws
            ? 'Without a Shared Drive, a service account can only read folders you share with it. It can’t write to My Drive, so backups need OAuth or a Shared Drive.'
            : 'With a personal Google account, a service account can only read folders you share with it (fine for a gallery). It can’t write to My Drive, so backups need OAuth.';
        const bad = s.project !== '' && !PROJECT_RE.test(s.project);
        root.querySelector('#project').setAttribute('aria-invalid', bad ? 'true' : 'false');
        root.querySelector('#project-err').textContent = bad
            ? 'That isn’t a project ID: 6–30 lowercase letters, digits and hyphens, starting with a letter. The links below ignore it.'
            : '';
    }

    /** Hands the Accounts tab's Add account form a name and type, both ways: it may mount only after the tab shows. */
    _prefill() {
        const detail = { name: this._account, type: this._method() === 'sa' ? 'service_account' : 'oauth' };
        window.__gdriveAccountPrefill = detail;
        window.dispatchEvent(new CustomEvent('gdrive-account-prefill', { detail }));
    }

    /** Links to #<tab> or #<tab>--<anchor>: switch tab through the hash (Admin2's tabs follow it), then scroll to the entry. */
    _onLink(e) {
        const a = e.composedPath().find((n) => n instanceof HTMLAnchorElement);
        const href = a?.getAttribute('href') || '';
        if (!href.startsWith('#')) return;
        e.preventDefault();
        const [tab, anchor] = href.slice(1).split('--');
        if (tab === 'accounts_tab') this._prefill();
        window.location.hash = tab;
        if (anchor) setTimeout(() => document.getElementById(anchor)?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 250);
    }
}

/** 'gmail' | 'workspace' from a complete-looking address, else null (keep the current choice). */
function kindOf(email) {
    const e = String(email).trim();
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(e)) return null;
    return PERSONAL_RE.test(e) ? 'gmail' : 'workspace';
}

function load() {
    let saved = {};
    try { saved = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch { /* none, or unreadable */ }
    const s = { ...DEFAULTS };
    for (const k of Object.keys(DEFAULTS)) {
        const v = saved[k];
        if (typeof v === 'string' && (!CHOICES[k] || CHOICES[k].includes(v))) s[k] = v.slice(0, 254);
    }
    return s;
}

function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

const STYLE = `
    :host { display: block; font-family: inherit; color: var(--foreground, #0f172a); }
    .qs {
        display: grid; gap: 16px; margin-bottom: 20px; padding: 14px 16px;
        border: 1px solid var(--border, #e2e8f0); border-radius: var(--radius, 8px); background: var(--background, #fff);
    }
    .q { display: grid; gap: 6px; }
    .q[hidden], [hidden] { display: none !important; }
    .ws { gap: 12px; }
    fieldset { border: 0; margin: 0; padding: 0; display: grid; gap: 4px; min-width: 0; }
    .ql { font-size: 14px; font-weight: 600; padding: 0; margin-bottom: 2px; }
    .opt { display: flex; align-items: baseline; gap: 8px; font-size: 14px; cursor: pointer; }
    .opt input { margin: 0; flex: none; }
    .opt em { font-style: normal; color: var(--muted-foreground, #64748b); }
    .row { display: flex; flex-wrap: wrap; gap: 6px 18px; }
    input[type=email], input[type=text] {
        font: inherit; font-size: 14px; padding: 8px 10px; max-width: 28rem; width: 100%; box-sizing: border-box;
        border: 1px solid var(--border, #e2e8f0); border-radius: var(--radius, 6px);
        background: var(--muted, #f8fafc); color: var(--foreground, #0f172a);
    }
    input[aria-invalid=true] { border-color: var(--destructive, #dc2626); }
    input:focus-visible, a:focus-visible { outline: 2px solid var(--ring, var(--primary, #6366f1)); outline-offset: 2px; }
    .hint, .muted { font-size: 13px; color: var(--muted-foreground, #64748b); margin: 0; }
    .note { font-size: 13px; margin: 0; }
    .note:empty { display: none; }
    .bad { color: var(--destructive, #b91c1c); }
    .note.warn { padding: 8px 10px; border-radius: 6px; background: color-mix(in srgb, #f59e0b 14%, transparent); }
    .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
    .out { font-size: 14px; line-height: 1.6; overflow-wrap: anywhere; }
    .out[aria-busy=true] { opacity: 0.6; transition: opacity 0.2s; }
    .out h2 { font-size: 1.25rem; margin: 1.2em 0 0.4em; }
    .out h3 { font-size: 1.05rem; margin: 1.2em 0 0.4em; }
    .out p, .out ul, .out ol, .out pre, .out blockquote, .out table { margin: 0 0 0.8em; }
    .out ul, .out ol { padding-left: 1.5em; }
    .out li { margin: 0.2em 0; }
    .out li > p { margin: 0.2em 0; }
    .out a { color: var(--primary, #4f46e5); }
    .out code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.85em; padding: 1px 5px; border-radius: 4px; background: var(--muted, #f1f5f9); }
    .out pre { padding: 10px 12px; border-radius: 6px; background: var(--muted, #f1f5f9); overflow-x: auto; }
    .out pre code { padding: 0; background: none; }
    .out blockquote { margin-left: 0; padding: 8px 12px; border-left: 3px solid #f59e0b; background: color-mix(in srgb, #f59e0b 10%, transparent); border-radius: 0 6px 6px 0; }
    .out blockquote p:last-child { margin-bottom: 0; }
    .out table { border-collapse: collapse; }
    .out th, .out td { padding: 6px 14px; text-align: left; vertical-align: top; border-bottom: 1px solid var(--border, #e2e8f0); }
    .out th { font-weight: 600; }
`;

customElements.define(TAG, GdriveGuide);
