/**
 * `gdrive-accounts` field: the Google Drive account manager on the gdrive
 * plugin's settings page (Admin2 custom field).
 *
 * Lists the accounts from GET /gdrive/accounts and drives the other
 * /gdrive/accounts endpoints: add (upload or paste the JSON), Test,
 * Connect/Reconnect (OAuth, in a popup) and Remove.
 *
 * Display only: it never dispatches `change`, so it adds nothing to the
 * settings form (the blueprint also sets validate.ignore). Self-contained,
 * because Admin2 imports each field file on its own from a blob: URL.
 */

const TAG = window.__GRAV_FIELD_TAG;
const SCOPE_PREFIX = 'https://www.googleapis.com/auth/';
const TYPES = { service_account: 'Service account', oauth: 'OAuth' };
const NAME_RE = /^[a-z0-9][a-z0-9_-]{0,31}$/;
const MAX_JSON = 65536;

class GdriveAccounts extends HTMLElement {
    constructor() {
        super();
        this.attachShadow({ mode: 'open' });
        this._field = null;
        this._value = null;
        this._data = null;        // {accounts, redirect_uri, wanted}
        this._loadError = null;   // {detail, status}
        this._busy = {};          // name → 'test' | 'connect' | 'remove'
        this._notes = {};         // name → {ok, text, anchor}
        this._adding = false;
        this._popupTimer = null;
        this._onMessage = this._onMessage.bind(this);
    }

    set field(v) { this._field = v; }
    get field() { return this._field; }
    set value(v) { this._value = v; }
    get value() { return this._value; }

    connectedCallback() {
        window.addEventListener('message', this._onMessage);
        this._renderShell();
        this._load();
    }

    disconnectedCallback() {
        window.removeEventListener('message', this._onMessage);
        clearInterval(this._popupTimer);
    }

    // ─── API ────────────────────────────────────────────────────────────

    _url(path) {
        return (window.__GRAV_API_SERVER_URL || '') + (window.__GRAV_API_PREFIX || '/api/v1') + path;
    }

    /** Reads the token at call time (Admin2 refreshes it), retries once on a 401. Throws {detail, anchor, status}. */
    async _call(method, path, body, retried = false) {
        const headers = { Accept: 'application/json' };
        if (window.__GRAV_API_TOKEN) headers['X-API-Token'] = window.__GRAV_API_TOKEN;
        if (window.__GRAV_ENVIRONMENT) headers['X-Grav-Environment'] = headers['X-Config-Environment'] = window.__GRAV_ENVIRONMENT;
        const init = { method, headers };
        if (body !== undefined) {
            headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(body);
        }
        let resp;
        try {
            resp = await fetch(this._url(path), init);
        } catch (e) {
            throw { status: 0, detail: 'The site could not be reached. Check your connection and try again.' };
        }
        if (resp.status === 401 && !retried) {
            await new Promise((r) => setTimeout(r, 400));
            return this._call(method, path, body, true);
        }
        const json = resp.status === 204 ? {} : await resp.json().catch(() => ({}));
        if (!resp.ok) {
            throw { status: resp.status, detail: json.detail || json.title || `HTTP ${resp.status}`, anchor: json.anchor || '', code: json.code || '' };
        }
        return json.data ?? json;
    }

    async _load() {
        try {
            this._data = await this._call('GET', '/gdrive/accounts');
            this._loadError = null;
        } catch (e) {
            this._loadError = e;
        }
        this._renderList();
    }

    async _act(name, action, fn) {
        this._busy[name] = action;
        this._renderList();
        try {
            await fn();
        } catch (e) {
            this._notes[name] = { ok: false, text: e.detail, anchor: e.anchor };
        }
        delete this._busy[name];
        this._renderList();
    }

    _test(name) {
        return this._act(name, 'test', async () => {
            const data = await this._call('POST', `/gdrive/accounts/${encodeURIComponent(name)}/test`);
            this._data = data;
            const r = data.result || {};
            this._notes[name] = r.ok
                ? { ok: true, text: `Test passed${r.email ? `: Drive sees ${r.email}` : ''}.` }
                : { ok: false, text: r.message || 'Test failed.', anchor: r.anchor };
        });
    }

    _connect(name) {
        // Open the window inside the click so pop-up blockers allow it; point it at Google once the URL arrives.
        const popup = window.open('', 'gdrive-connect', 'popup,width=520,height=700');
        return this._act(name, 'connect', async () => {
            let url;
            try {
                url = (await this._call('POST', `/gdrive/accounts/${encodeURIComponent(name)}/connect`)).url;
            } catch (e) {
                popup?.close();
                throw e;
            }
            if (popup && !popup.closed) {
                popup.location.href = url;
                this._notes[name] = { ok: true, text: 'Finish signing in in the Google window. This list updates when you are done.' };
                this._watchPopup(popup);
            } else {
                this._notes[name] = { ok: true, text: 'Your browser blocked the Google window.', link: url };
            }
        });
    }

    /** Refresh once the popup closes, in case its message never arrived (closed early, or blocked). */
    _watchPopup(popup) {
        clearInterval(this._popupTimer);
        this._popupTimer = setInterval(() => {
            if (popup.closed) {
                clearInterval(this._popupTimer);
                this._load();
            }
        }, 1000);
    }

    _onMessage(e) {
        if (e.origin !== window.location.origin || !e.data || e.data.gdrive !== 'connected') return;
        const name = String(e.data.account || '');
        if (name) this._notes[name] = { ok: true, text: 'Connected.' };
        window.__GRAV_TOAST?.success?.('Google account connected');
        this._load();
    }

    async _remove(name) {
        const message = `Remove the Google Drive account "${name}"? Its credential files are deleted from this site${this._account(name)?.type === 'oauth' ? ' and the site\u2019s access is revoked at Google' : ''}. Plugins set to use it stop working until you add it again.`;
        const ok = window.__GRAV_DIALOGS?.confirm
            ? await window.__GRAV_DIALOGS.confirm({ title: 'Remove account', message, confirmLabel: 'Remove', variant: 'destructive' })
            : window.confirm(message);
        if (!ok) return;
        await this._act(name, 'remove', async () => {
            this._data = await this._call('DELETE', `/gdrive/accounts/${encodeURIComponent(name)}`);
            delete this._notes[name];
            window.__GRAV_TOAST?.success?.(`Removed ${name}`);
        });
        this.shadowRoot.querySelector('#add-name')?.focus();
    }

    async _add(ev) {
        ev.preventDefault();
        const root = this.shadowRoot;
        const name = root.querySelector('#add-name').value.trim();
        const type = root.querySelector('#add-type').value;
        const file = root.querySelector('#add-file').files[0];
        const pasted = root.querySelector('#add-paste').value.trim();
        const msg = root.querySelector('#add-msg');
        const say = (text, ok, anchor) => { msg.className = `note ${ok ? 'ok' : 'bad'}`; msg.innerHTML = esc(text) + fixLink(anchor); };

        if (!NAME_RE.test(name)) return say('Account names are 1–32 characters: lowercase letters, digits, "-" and "_", starting with a letter or digit.', false);
        if (!file && !pasted) return say('Choose the JSON file Google gave you, or paste its contents.', false);
        if (file && file.size > MAX_JSON) return say('That file is too large to be a Google credential (the limit is 64 KB).', false);

        this._setAdding(true);
        try {
            const json = file ? await file.text() : pasted;
            this._data = await this._call('POST', '/gdrive/accounts', { name, type, json });
            root.querySelectorAll('.add input, .add textarea').forEach((el) => { el.value = ''; });
            this._syncType();
            say(type === 'oauth' ? `Added ${name}. Now click Connect on it.` : `Added ${name}. Share your folder with its email, then click Test.`, true);
            window.__GRAV_TOAST?.success?.(`Added ${name}`);
            this._renderList();
            this.shadowRoot.querySelector(`[data-key="${cssEsc(`test:${name}`)}"]`)?.focus();
        } catch (e) {
            say(e.detail || 'Could not add the account.', false, e.anchor);
        }
        this._setAdding(false);
    }

    _account(name) {
        return (this._data?.accounts || []).find((a) => a.name === name);
    }

    // ─── Render ─────────────────────────────────────────────────────────

    _renderShell() {
        this.shadowRoot.innerHTML = `<style>${STYLE}</style>
            <div class="list"></div>
            <div class="add" role="group" aria-labelledby="add-h">
                <h3 id="add-h">Add account</h3>
                <p class="hint">Follow the <a href="#guided">Guided setup</a>, or the full <a href="#oauth">OAuth guide</a> or
                    <a href="#service_account">service account guide</a>, first; each ends with the JSON file to upload here.
                    Uploading under an existing name replaces its credential.</p>
                <div class="grid">
                    <label for="add-name">Name
                        <input id="add-name" name="name" type="text" required maxlength="32" autocomplete="off"
                            spellcheck="false" placeholder="personal" aria-describedby="add-name-hint">
                        <span class="hint" id="add-name-hint">Lowercase letters, digits, - and _. Plugins refer to the account by it.</span>
                    </label>
                    <label for="add-type">Type
                        <select id="add-type" name="type">
                            <option value="oauth">OAuth (Web application client JSON)</option>
                            <option value="service_account">Service account (key JSON)</option>
                        </select>
                    </label>
                </div>
                <div class="redirect" hidden>
                    <span>Redirect URI to register on the client:</span>
                    <code class="uri"></code>
                    <button type="button" class="copy">Copy</button>
                </div>
                <label for="add-file">JSON file
                    <input id="add-file" name="file" type="file" accept=".json,application/json">
                </label>
                <label for="add-paste">…or paste its contents
                    <textarea id="add-paste" name="paste" rows="4" spellcheck="false" autocomplete="off" placeholder="{ … }"></textarea>
                </label>
                <div class="row">
                    <button type="button" class="primary add-go">Add account</button>
                    <span id="add-msg" class="note" role="status"></span>
                </div>
            </div>`;
        const root = this.shadowRoot;
        // A div, not a form: Admin2 renders fields inside its own form element, and the parser drops a nested one.
        const add = root.querySelector('.add');
        add.querySelector('.add-go').addEventListener('click', (e) => this._add(e));
        add.addEventListener('keydown', (e) => { // Enter would otherwise submit Admin2's settings form
            if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && e.target.tagName !== 'BUTTON') { e.preventDefault(); this._add(e); }
        });
        root.querySelector('#add-type').addEventListener('change', () => this._syncType());
        root.querySelector('.copy').addEventListener('click', () => this._copy(this._data?.redirect_uri || ''));
        root.addEventListener('click', (e) => this._onLink(e));
        this._renderList();
    }

    _syncType() {
        const oauth = this.shadowRoot.querySelector('#add-type').value === 'oauth';
        const box = this.shadowRoot.querySelector('.redirect');
        box.hidden = !oauth || !this._data?.redirect_uri;
        box.querySelector('.uri').textContent = this._data?.redirect_uri || '';
    }

    _setAdding(on) {
        this._adding = on;
        const btn = this.shadowRoot.querySelector('.add-go');
        btn.disabled = on;
        btn.textContent = on ? 'Adding…' : 'Add account';
    }

    async _copy(text) {
        try {
            await navigator.clipboard.writeText(text);
            window.__GRAV_TOAST?.success?.('Copied');
        } catch {
            window.__GRAV_TOAST?.error?.('Copy failed; select the text and copy it.');
        }
    }

    /** Links to #<tab>--<anchor>: switch tab through the hash (Admin2's tabs follow it), then scroll to the entry. */
    _onLink(e) {
        const a = e.composedPath().find((n) => n instanceof HTMLAnchorElement);
        const href = a?.getAttribute('href') || '';
        const prefill = e.composedPath().find((n) => n instanceof HTMLElement && n.dataset?.prefill);
        if (prefill) {
            const input = this.shadowRoot.querySelector('#add-name');
            input.value = prefill.dataset.prefill;
            input.focus();
            return;
        }
        if (!href.startsWith('#')) return;
        e.preventDefault();
        const [tab, anchor] = href.slice(1).split('--');
        window.location.hash = tab;
        if (anchor) setTimeout(() => document.getElementById(anchor)?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 250);
    }

    _renderList() {
        const list = this.shadowRoot.querySelector('.list');
        if (!list) return;
        const focusKey = this.shadowRoot.activeElement?.dataset?.key;
        list.innerHTML = this._listHtml();
        list.querySelectorAll('button[data-act]').forEach((b) => b.addEventListener('click', () => {
            const { act, name } = b.dataset;
            if (act === 'test') this._test(name);
            else if (act === 'connect') this._connect(name);
            else if (act === 'remove') this._remove(name);
        }));
        if (focusKey) list.querySelector(`[data-key="${cssEsc(focusKey)}"]`)?.focus();
        this._syncType();
    }

    _listHtml() {
        if (this._loadError) {
            const e = this._loadError;
            const text = e.status === 404
                ? 'The Google Drive Library plugin’s API isn’t available. Enable the plugin, save, and reload this page.'
                : e.status === 403 ? 'You need the “Manage Google Drive accounts” permission (api.gdrive.manage) to manage accounts.' : e.detail;
            return `<p class="note bad" role="alert">${esc(text)}</p>`;
        }
        if (!this._data) return '<p class="muted">Loading accounts…</p>';
        const { accounts = [], wanted = [] } = this._data;
        const wantedHtml = wanted.length ? `<p class="note warn">Plugins expect ${wanted.map((w) =>
            `<button type="button" class="linkish" data-prefill="${esc(w)}" aria-label="Add an account named ${esc(w)}"><code>${esc(w)}</code></button>`).join(', ')}, which
            ${wanted.length === 1 ? 'doesn’t' : 'don’t'} exist yet. Add ${wanted.length === 1 ? 'it' : 'them'} below.</p>` : '';
        if (!accounts.length) return `${wantedHtml}<p class="muted">No accounts yet. Add one below.</p>`;
        return wantedHtml + accounts.map((a) => this._rowHtml(a)).join('');
    }

    _rowHtml(a) {
        const busy = this._busy[a.name];
        const oauth = a.type === 'oauth';
        const note = this._notes[a.name];
        const dis = busy ? 'disabled' : '';
        const who = !a.has_credential
            ? `<span class="bad">No ${oauth ? 'client' : 'key'} uploaded</span>`
            : oauth && !a.connected ? '<span class="bad">Not connected</span>' : esc(a.email || '(unknown)');
        const connectLabel = a.connected ? 'Reconnect' : 'Connect';
        return `<article class="acct" aria-labelledby="h-${esc(a.name)}">
            <header>
                <h3 id="h-${esc(a.name)}">${esc(a.name)}</h3>
                <span class="badge">${esc(TYPES[a.type] || a.type)}</span>
            </header>
            <dl>
                <div><dt>${oauth ? 'Connected as' : 'Service account'}</dt><dd>${who}</dd></div>
                <div><dt>Scopes</dt><dd>${this._scopesHtml(a)}</dd></div>
                <div><dt>Last test</dt><dd>${this._testHtml(a.test)}</dd></div>
            </dl>
            ${note ? `<p class="note ${note.ok ? 'ok' : 'bad'}" role="status">${esc(note.text)}${note.link ? ` <a href="${esc(note.link)}" target="_blank" rel="noopener">Open Google sign-in</a>` : ''}${fixLink(note.anchor)}</p>` : ''}
            <div class="row actions">
                <button type="button" data-act="test" data-name="${esc(a.name)}" data-key="test:${esc(a.name)}" ${dis || (!a.has_credential ? 'disabled' : '')}
                    aria-label="Test ${esc(a.name)}">${busy === 'test' ? 'Testing…' : 'Test'}</button>
                ${oauth ? `<button type="button" class="${a.connected && !a.missing.length ? '' : 'primary'}" data-act="connect" data-name="${esc(a.name)}" data-key="connect:${esc(a.name)}"
                    ${dis || (!a.has_credential ? 'disabled' : '')} aria-label="${connectLabel} ${esc(a.name)}">${busy === 'connect' ? 'Opening…' : connectLabel}</button>` : ''}
                <button type="button" class="danger" data-act="remove" data-name="${esc(a.name)}" data-key="remove:${esc(a.name)}" ${dis}
                    aria-label="Remove ${esc(a.name)}">${busy === 'remove' ? 'Removing…' : 'Remove'}</button>
            </div>
        </article>`;
    }

    _scopesHtml(a) {
        const granted = new Set(a.scopes || []);
        const missing = new Set(a.missing || []);
        const chips = [];
        const seen = new Set();
        for (const d of a.declared || []) {
            for (const s of d.scopes) {
                if (seen.has(s)) continue;
                seen.add(s);
                const users = (a.declared || []).filter((x) => x.scopes.includes(s)).map((x) => x.plugin).join(', ');
                const cls = missing.has(s) ? 'chip missing' : (a.type === 'oauth' && !granted.has(s) ? 'chip' : 'chip ok');
                const state = missing.has(s) ? 'not granted: reconnect' : (a.type === 'oauth' && !a.connected ? 'not connected yet' : 'ok');
                chips.push(`<span class="${cls}" title="${esc(`Needed by ${users}: ${state}`)}">${esc(short(s))}${missing.has(s) ? ' <strong>not granted</strong>' : ''}<span class="sr"> (needed by ${esc(users)}, ${esc(state)})</span></span>`);
            }
        }
        for (const s of granted) {
            if (!seen.has(s)) chips.push(`<span class="chip ok" title="Granted, no plugin declares it">${esc(short(s))}</span>`);
        }
        const hint = missing.size ? ` <a href="#troubleshooting--scope-not-granted">Why?</a>` : '';
        return chips.length ? `<span class="chips">${chips.join('')}</span>${hint}` : '<span class="muted">No plugin uses this account yet</span>';
    }

    _testHtml(t) {
        if (!t) return '<span class="muted">Not tested yet</span>';
        const when = t.at ? new Date(t.at).toLocaleString() : '';
        if (t.ok) {
            const q = t.quota && Number(t.quota.limit) > 0 ? ` · ${bytes(t.quota.usage)} of ${bytes(t.quota.limit)} used` : '';
            return `<span class="ok">✔ OK</span> <span class="muted">${esc(when)}${esc(q)}</span>`;
        }
        return `<span class="bad">✘ <code>${esc(t.reason || 'error')}</code></span> <span class="muted">${esc(when)}</span>${fixLink(t.anchor)}`;
    }
}

function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function cssEsc(s) {
    return window.CSS?.escape ? CSS.escape(s) : String(s).replace(/["\\]/g, '\\$&');
}

function fixLink(anchor) {
    return anchor && /^[a-z0-9-]+$/.test(anchor) ? ` <a href="#troubleshooting--${anchor}">How to fix this</a>` : '';
}

function short(scope) {
    return String(scope).startsWith(SCOPE_PREFIX) ? String(scope).slice(SCOPE_PREFIX.length) : String(scope);
}

function bytes(n) {
    const v = Number(n) || 0;
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = v > 0 ? Math.min(units.length - 1, Math.floor(Math.log(v) / Math.log(1024))) : 0;
    return `${(v / 1024 ** i).toFixed(i ? 1 : 0)} ${units[i]}`;
}

const STYLE = `
    :host { display: block; font-family: inherit; color: var(--foreground, #0f172a); }
    h3 { margin: 0; font-size: 1rem; font-weight: 600; }
    .list { display: grid; gap: 12px; margin-bottom: 20px; }
    .acct, .add {
        border: 1px solid var(--border, #e2e8f0); border-radius: var(--radius, 8px);
        background: var(--background, #fff); padding: 14px 16px;
    }
    .acct header { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 8px; }
    .badge { font-size: 12px; font-weight: 500; padding: 2px 8px; border-radius: 999px; background: var(--muted, #f1f5f9); color: var(--muted-foreground, #475569); }
    dl { margin: 0 0 10px; display: grid; gap: 6px; }
    dl > div { display: grid; grid-template-columns: 9rem 1fr; gap: 8px; align-items: baseline; }
    dt { font-size: 13px; color: var(--muted-foreground, #64748b); }
    dd { margin: 0; font-size: 14px; min-width: 0; overflow-wrap: anywhere; }
    .chips { display: inline-flex; flex-wrap: wrap; gap: 4px; }
    .chip { font-size: 12px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; padding: 2px 7px; border-radius: 6px; border: 1px solid var(--border, #e2e8f0); }
    .chip.ok { background: color-mix(in srgb, var(--primary, #6366f1) 12%, transparent); border-color: transparent; }
    .chip.missing { background: color-mix(in srgb, var(--destructive, #dc2626) 14%, transparent); color: var(--destructive, #b91c1c); border-color: var(--destructive, #dc2626); }
    .row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    button {
        font: inherit; font-size: 0.875rem; font-weight: 500; cursor: pointer; min-height: 36px;
        padding: 0.4rem 0.9rem; border-radius: var(--radius, 0.375rem);
        border: 1px solid var(--border, #e2e8f0); background: var(--background, #fff); color: var(--foreground, #0f172a);
    }
    button:hover:not(:disabled) { background: var(--accent, #f1f5f9); }
    button.primary { background: var(--primary, #6366f1); color: var(--primary-foreground, #fff); border-color: var(--primary, #6366f1); }
    button.primary:hover:not(:disabled) { background: color-mix(in srgb, var(--primary, #6366f1) 88%, #000); }
    button.danger { color: var(--destructive, #b91c1c); }
    button:disabled { opacity: 0.55; cursor: default; }
    button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible, a:focus-visible {
        outline: 2px solid var(--ring, var(--primary, #6366f1)); outline-offset: 2px;
    }
    button.linkish { min-height: 0; padding: 0; border: 0; background: none; color: var(--primary, #4f46e5); text-decoration: underline; }
    a { color: var(--primary, #4f46e5); }
    .add { display: grid; gap: 12px; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    label { display: grid; gap: 4px; font-size: 13px; font-weight: 500; }
    input[type=text], select, textarea {
        font: inherit; font-size: 14px; font-weight: 400; padding: 8px 10px; min-width: 0;
        border: 1px solid var(--border, #e2e8f0); border-radius: var(--radius, 6px);
        background: var(--muted, #f8fafc); color: var(--foreground, #0f172a);
    }
    textarea { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; resize: vertical; }
    input[type=file] { font-size: 13px; font-weight: 400; max-width: 100%; }
    .redirect { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; font-size: 13px; }
    .redirect[hidden] { display: none; }
    code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; overflow-wrap: anywhere; }
    .redirect code { padding: 4px 8px; border-radius: 6px; background: var(--muted, #f1f5f9); }
    .hint, .muted { font-size: 13px; font-weight: 400; color: var(--muted-foreground, #64748b); margin: 0; }
    .note { font-size: 13px; margin: 0 0 10px; }
    .row .note { margin: 0; }
    .ok { color: var(--success, #15803d); }
    .bad { color: var(--destructive, #b91c1c); }
    .note.warn { padding: 8px 10px; border-radius: 6px; background: color-mix(in srgb, #f59e0b 14%, transparent); }
    .sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
    @media (max-width: 560px) {
        .grid { grid-template-columns: 1fr; }
        dl > div { grid-template-columns: 1fr; gap: 2px; }
        .actions button { flex: 1 1 auto; }
    }
`;

customElements.define(TAG, GdriveAccounts);
