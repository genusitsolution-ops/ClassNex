/* ClassNex web core: API client, router and shared UI components used by every panel. */
(() => {
  'use strict';
  const BOOT = window.CNX_BOOT || {};
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // ------------------------------------------------------------------ API
  class ApiError extends Error {
    constructor(status, json) { super(json?.message || 'Request failed'); this.status = status; this.json = json || {}; this.errors = json?.errors || {}; this.code = json?.code; }
  }
  async function api(method, path, body = null, opts = {}) {
    const headers = { 'X-CSRF-Token': BOOT.csrf, Accept: 'application/json' };
    let payload = null;
    if (body instanceof FormData) payload = body;
    else if (body !== null) { headers['Content-Type'] = 'application/json'; payload = JSON.stringify(body); }
    if (opts.idempotency) headers['Idempotency-Key'] = opts.idempotency;
    let res;
    try {
      res = await fetch(BOOT.api + path, { method, headers, body: payload, credentials: 'same-origin' });
    } catch (e) {
      throw new ApiError(0, { message: 'No internet connection. Please check your network and try again.' });
    }
    const ct = res.headers.get('content-type') || '';
    if (!ct.includes('application/json')) {
      if (!res.ok) throw new ApiError(res.status, { message: 'Server error (' + res.status + ')' });
      return res;
    }
    const json = await res.json();
    if (res.status === 401) { location.href = BOOT.root + '/login.php?next=' + encodeURIComponent(location.pathname + location.hash); throw new ApiError(401, json); }
    if (!res.ok || json.success === false) throw new ApiError(res.status, json);
    return json;
  }
  const get = (p) => api('GET', p);
  const post = (p, b, o) => api('POST', p, b ?? {}, o);
  const put = (p, b) => api('PUT', p, b ?? {});
  const del = (p) => api('DELETE', p);
  const qs = (o) => { const u = new URLSearchParams(); Object.entries(o || {}).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined) u.set(k, v); }); const s = u.toString(); return s ? '?' + s : ''; };
  const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'id-' + Date.now() + '-' + Math.random().toString(16).slice(2));

  // ------------------------------------------------------------ formatting
  const money = (p, dec = false) => {
    const n = Number(p || 0) / 100;
    return '₹' + n.toLocaleString('en-IN', { minimumFractionDigits: dec || n % 1 ? 2 : 0, maximumFractionDigits: 2 });
  };
  const rupees = (p) => (Number(p || 0) / 100).toFixed(2).replace(/\.00$/, '');
  const date = (d) => { if (!d) return '–'; const x = new Date(String(d).replace(' ', 'T')); return isNaN(x) ? d : x.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }); };
  const dateTime = (d) => { if (!d) return '–'; const x = new Date(String(d).replace(' ', 'T')); return isNaN(x) ? d : x.toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }); };
  const time = (t) => { if (!t) return ''; const [h, m] = t.split(':'); const hh = +h; return `${((hh + 11) % 12) + 1}:${m} ${hh < 12 ? 'AM' : 'PM'}`; };
  const today = () => { const d = new Date(); d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); return d.toISOString().slice(0, 10); };
  const month = () => today().slice(0, 7);
  const label = (s) => String(s || '').replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  const WEEK = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  const STATUS = {
    active: 'b-success', paid: 'b-success', success: 'b-success', sent: 'b-info', delivered: 'b-success', read: 'b-success', present: 'b-success', approved: 'b-success', valid: 'b-success', resolved: 'b-success', converted: 'b-success',
    trial: 'b-info', partial: 'b-warning', pending: 'b-warning', processing: 'b-info', grace: 'b-warning', late: 'b-warning', in_progress: 'b-info', open: 'b-warning', new: 'b-info', invited: 'b-purple', contacted: 'b-info', paused: 'b-muted', leave: 'b-purple',
    unpaid: 'b-danger', failed: 'b-danger', expired: 'b-danger', suspended: 'b-danger', absent: 'b-danger', reversed: 'b-danger', rejected: 'b-danger', cancelled: 'b-muted', overdue: 'b-danger',
    inactive: 'b-muted', left: 'b-muted', closed: 'b-muted', waived: 'b-muted', completed: 'b-muted', stopped: 'b-muted', transferred: 'b-muted', holiday: 'b-purple',
  };
  const badge = (s, text) => `<span class="badge ${STATUS[s] || 'b-muted'}">${esc(text || label(s))}</span>`;
  const photo = (url, name, cls = 'thumb') => url ? `<img class="${cls}" src="${esc(url)}" alt="" loading="lazy">` : `<span class="avatar ${cls === 'thumb' ? '' : cls}">${esc((name || '?').trim().charAt(0).toUpperCase())}</span>`;
  const person = (name, sub, url) => `<div class="person">${photo(url, name)}<div class="min-w-0"><div class="n">${esc(name)}</div>${sub ? `<div class="m">${sub}</div>` : ''}</div></div>`;
  const wa = (cc, num) => num ? `<a href="https://wa.me/${esc(String(cc || '+91').replace('+', '') + num)}" target="_blank" rel="noopener" class="text-success text-decoration-none"><i class="bi bi-whatsapp"></i> ${esc(num)}</a>` : '–';

  // ------------------------------------------------------------- feedback
  function toast(msg, type = 'success') {
    const wrap = $('#toasts');
    const el = document.createElement('div');
    const icon = { success: 'bi-check-circle-fill text-success', danger: 'bi-exclamation-octagon-fill text-danger', warning: 'bi-exclamation-triangle-fill text-warning', info: 'bi-info-circle-fill text-primary' }[type] || '';
    el.className = 'toast align-items-center border-0 shadow';
    el.innerHTML = `<div class="d-flex"><div class="toast-body d-flex gap-2 align-items-start"><i class="bi ${icon} fs-5"></i><div>${esc(msg)}</div></div><button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    wrap.appendChild(el);
    const t = new bootstrap.Toast(el, { delay: type === 'danger' ? 7000 : 3500 });
    t.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
  }
  function fail(e) {
    console.error(e);
    if (e?.code === 'limit_reached' && BOOT.me?.role === 'owner') {
      const m = modal({ title: 'Package limit reached', body: `<div class="d-flex gap-3"><i class="bi bi-people-fill fs-1 text-warning"></i><p class="mb-0">${esc(e.message)}</p></div>`, footer: '<button class="btn btn-light" data-bs-dismiss="modal">Not now</button><a class="btn btn-primary" href="#/subscription" id="upg"><i class="bi bi-arrow-up-circle"></i> Upgrade Package</a>' });
      $('#upg', m.el).onclick = () => { document.querySelectorAll('.modal.show').forEach((x) => bootstrap.Modal.getInstance(x)?.hide()); };
      return;
    }
    if (e?.code === 'feature_locked' || e?.code === 'limit_reached' || e?.code === 'subscription_expired') {
      toast(e.message, 'warning');
      return;
    }
    toast(e?.message || 'Something went wrong.', 'danger');
  }

  function modal({ title, body = '', size = '', footer = null, static: isStatic = false, onClose }) {
    const el = document.createElement('div');
    el.className = 'modal fade';
    el.tabIndex = -1;
    el.innerHTML = `<div class="modal-dialog modal-dialog-scrollable ${size ? 'modal-' + size : ''} modal-fullscreen-sm-down"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title fw-bold">${esc(title)}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">${body}</div>${footer === false ? '' : `<div class="modal-footer">${footer ?? '<button class="btn btn-light" data-bs-dismiss="modal">Close</button>'}</div>`}</div></div>`;
    document.body.appendChild(el);
    const m = new bootstrap.Modal(el, isStatic ? { backdrop: 'static' } : {});
    el.addEventListener('hidden.bs.modal', () => { onClose && onClose(); el.remove(); });
    m.show();
    return { el, body: $('.modal-body', el), close: () => m.hide(), footer: $('.modal-footer', el) };
  }
  function confirm(message, { title = 'Please confirm', ok = 'Yes, continue', danger = false, input = null } = {}) {
    return new Promise((resolve) => {
      let done = false;
      const m = modal({
        title, size: 'sm',
        body: `<p class="mb-${input ? 3 : 0}">${esc(message)}</p>${input ? `<input class="form-control" id="cfIn" placeholder="${esc(input)}">` : ''}`,
        footer: `<button class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn ${danger ? 'btn-danger' : 'btn-primary'}" id="cfOk">${esc(ok)}</button>`,
        onClose: () => !done && resolve(false),
      });
      $('#cfOk', m.el).onclick = () => {
        const v = input ? $('#cfIn', m.el).value.trim() : true;
        if (input && v.length < 3) { $('#cfIn', m.el).classList.add('is-invalid'); return; }
        done = true; m.close(); resolve(v);
      };
    });
  }

  // ----------------------------------------------------------------- forms
  /**
   * fields: [{name, label, type, required, options:[[v,l]], col, help, placeholder, min, step, attrs}]
   * types: text, email, phone, money, number, date, time, select, textarea, checkbox, switch, password, file, multicheck, hidden, html
   */
  function fieldHtml(f, v) {
    const id = 'f_' + f.name + '_' + Math.random().toString(36).slice(2, 7);
    const req = f.required ? '<span class="text-danger">*</span>' : '';
    const val = v ?? f.value ?? '';
    const common = `name="${esc(f.name)}" id="${id}" ${f.required ? 'required' : ''} ${f.attrs || ''}`;
    const col = f.col || 'col-12';
    const help = f.help ? `<div class="form-text">${f.help}</div>` : '';
    const lbl = f.label ? `<label class="form-label" for="${id}">${esc(f.label)} ${req}</label>` : '';
    let input;
    switch (f.type) {
      case 'hidden': return `<input type="hidden" ${common} value="${esc(val)}">`;
      case 'html': return `<div class="${col}" data-field="${esc(f.name || '')}">${f.html}</div>`;
      case 'textarea': input = `<textarea class="form-control" rows="${f.rows || 3}" ${common} placeholder="${esc(f.placeholder || '')}">${esc(val)}</textarea>`; break;
      case 'select': {
        const opts = (f.options || []).map(([ov, ol]) => `<option value="${esc(ov)}" ${String(ov) === String(val) ? 'selected' : ''}>${esc(ol)}</option>`).join('');
        input = `<select class="form-select" ${common}>${f.placeholder !== false ? `<option value="">${esc(f.placeholder || 'Select…')}</option>` : ''}${opts}</select>`;
        break;
      }
      case 'checkbox': case 'switch':
        return `<div class="${col}" data-field="${esc(f.name)}"><div class="form-check ${f.type === 'switch' ? 'form-switch' : ''}"><input class="form-check-input" type="checkbox" value="1" ${common} ${val && val !== '0' ? 'checked' : ''}><label class="form-check-label" for="${id}">${esc(f.label)}</label><div class="invalid-feedback"></div>${help}</div></div>`;
      case 'multicheck': {
        const sel = (Array.isArray(val) ? val : []).map(String);
        input = `<div class="d-flex flex-wrap gap-2">${(f.options || []).map(([ov, ol]) => `<label class="btn btn-sm btn-outline-secondary ${sel.includes(String(ov)) ? 'active' : ''}"><input type="checkbox" class="d-none" data-multi="${esc(f.name)}" value="${esc(ov)}" ${sel.includes(String(ov)) ? 'checked' : ''}> ${esc(ol)}</label>`).join('')}</div>`;
        break;
      }
      case 'money': input = `<div class="input-group"><span class="input-group-text">₹</span><input type="text" inputmode="decimal" class="form-control" ${common} value="${esc(val)}" placeholder="${esc(f.placeholder || '0')}"></div>`; break;
      case 'phone': input = `<div class="input-group"><select class="form-select flex-grow-0" style="width:90px" name="${esc(f.ccName || 'country_code')}">${['+91', '+1', '+44', '+971', '+65', '+61', '+977', '+880', '+94'].map((c) => `<option ${c === (f.cc || '+91') ? 'selected' : ''}>${c}</option>`).join('')}</select><input type="tel" inputmode="numeric" class="form-control" ${common} value="${esc(val)}" placeholder="${esc(f.placeholder || '10-digit mobile number')}" maxlength="15"></div>`; break;
      case 'file': input = `<input type="file" class="form-control" ${common} ${f.accept ? `accept="${esc(f.accept)}"` : ''}>`; break;
      default: {
        const type = { number: 'number', email: 'email', date: 'date', time: 'time', password: 'password', month: 'month' }[f.type] || 'text';
        input = `<input type="${type}" class="form-control" ${common} value="${esc(type === 'time' ? String(val).slice(0, 5) : val)}" placeholder="${esc(f.placeholder || '')}" ${f.min !== undefined ? `min="${f.min}"` : ''} ${f.max !== undefined ? `max="${f.max}"` : ''} ${f.step ? `step="${f.step}"` : ''}>`;
      }
    }
    return `<div class="${col}" data-field="${esc(f.name)}">${lbl}${input}<div class="invalid-feedback"></div>${help}</div>`;
  }
  function form(fields, values = {}) {
    return `<div class="row g-3">${fields.map((f) => fieldHtml(f, values[f.name])).join('')}</div>`;
  }
  function readForm(el) {
    const out = {};
    $$('input, select, textarea', el).forEach((i) => {
      if (!i.name && !i.dataset.multi) return;
      if (i.dataset.multi) { (out[i.dataset.multi] ||= []); if (i.checked) out[i.dataset.multi].push(i.value); return; }
      if (i.type === 'file') return;
      if (i.type === 'checkbox') out[i.name] = i.checked ? 1 : 0;
      else out[i.name] = i.value.trim();
    });
    return out;
  }
  function showErrors(el, errors = {}) {
    $$('.is-invalid', el).forEach((i) => i.classList.remove('is-invalid'));
    let first = null;
    Object.entries(errors).forEach(([k, msg]) => {
      const box = $(`[data-field="${CSS.escape(k)}"]`, el);
      if (!box) return;
      const inp = $('input:not([type=hidden]), select, textarea', box);
      inp?.classList.add('is-invalid');
      const fb = $('.invalid-feedback', box);
      if (fb) { fb.textContent = msg; fb.style.display = 'block'; }
      first ||= inp;
    });
    first?.focus();
  }
  function bindMulti(el) {
    $$('[data-multi]', el).forEach((c) => c.addEventListener('change', () => c.closest('label').classList.toggle('active', c.checked)));
  }
  /** Modal with a form. submit(data, el) must return a promise; errors are shown on the fields. */
  function formModal({ title, fields, values = {}, submit, size = '', okText = 'Save', intro = '' }) {
    const m = modal({ title, size, body: `${intro}<form novalidate>${form(fields, values)}</form>`, footer: `<button class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary px-4" data-ok>${esc(okText)}</button>` });
    bindMulti(m.el);
    const btn = $('[data-ok]', m.el);
    const go = async (ev) => {
      ev?.preventDefault();
      btn.disabled = true;
      const old = btn.innerHTML;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
      try {
        const res = await submit(readForm($('form', m.el)), m.el);
        if (res !== false) { m.close(); }
      } catch (e) {
        if (e.errors && Object.keys(e.errors).length) { showErrors(m.el, e.errors); toast(e.message, 'danger'); } else fail(e);
      } finally { btn.disabled = false; btn.innerHTML = old; }
    };
    btn.onclick = go;
    $('form', m.el).onsubmit = go;
    return m;
  }

  // ---------------------------------------------------------------- tables
  function table(columns, rows, { empty = 'Nothing here yet.', onRow = null, emptyIcon = 'bi-inbox' } = {}) {
    if (!rows.length) return `<div class="table-wrap"><div class="empty"><i class="bi ${emptyIcon}"></i>${esc(empty)}</div></div>`;
    const head = columns.map((c) => `<th class="${c.cls || ''}">${esc(c.label)}</th>`).join('');
    const body = rows.map((r, i) => `<tr ${onRow ? `class="row-link" data-i="${i}"` : ''}>${columns.map((c) => `<td class="${c.cls || ''}">${c.render ? c.render(r) : esc(r[c.key] ?? '')}</td>`).join('')}</tr>`).join('');
    return `<div class="table-wrap"><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></div></div>`;
  }
  function bindRows(el, rows, onRow) {
    $$('tr.row-link', el).forEach((tr) => tr.addEventListener('click', (e) => { if (e.target.closest('a,button,input,select,label')) return; onRow(rows[+tr.dataset.i]); }));
  }

  /**
   * Server-paginated list with search & filters.
   * cfg: {el, endpoint, columns, filters:[{name,type,options,placeholder}], search:true, onRow, empty, params, after(json)}
   */
  function listView(cfg) {
    const state = { page: 1, ...(cfg.params || {}) };
    const filtersHtml = (cfg.search !== false ? `<input class="form-control search" type="search" name="q" value="${esc(state.q || '')}" placeholder="${esc(cfg.searchPlaceholder || 'Search…')}">` : '') +
      (cfg.filters || []).map((f) => f.type === 'date'
        ? `<input class="form-control" type="date" name="${f.name}" value="${esc(state[f.name] ?? '')}" title="${esc(f.placeholder || '')}">`
        : `<select class="form-select" name="${f.name}">${f.all !== false ? `<option value="">${esc(f.placeholder || 'All')}</option>` : ''}${f.options.map(([v, l]) => `<option value="${esc(v)}" ${String(state[f.name] ?? '') === String(v) ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`).join('');
    cfg.el.innerHTML = `<div class="filters">${filtersHtml}${cfg.extraFilters || ''}</div><div data-list><div class="loading-block"><div class="spinner-border text-primary"></div></div></div>`;
    const listEl = $('[data-list]', cfg.el);
    const load = async () => {
      try {
        const json = await get(cfg.endpoint + qs({ ...state, per_page: cfg.perPage || 25 }));
        const rows = json.data || [];
        const meta = json.meta || {};
        listEl.innerHTML = table(cfg.columns, rows, { empty: cfg.empty, onRow: cfg.onRow, emptyIcon: cfg.emptyIcon }) +
          (meta.pages > 1 ? `<div class="pager"><span>${meta.total} records · page ${meta.page} of ${meta.pages}</span><span class="btn-group"><button class="btn btn-sm btn-light" data-p="-1" ${meta.page <= 1 ? 'disabled' : ''}><i class="bi bi-chevron-left"></i></button><button class="btn btn-sm btn-light" data-p="1" ${meta.page >= meta.pages ? 'disabled' : ''}><i class="bi bi-chevron-right"></i></button></span></div>` : (rows.length ? `<div class="small text-muted mt-2 ms-1">${meta.total ?? rows.length} record(s)</div>` : ''));
        if (cfg.onRow) bindRows(listEl, rows, cfg.onRow);
        $$('[data-p]', listEl).forEach((b) => b.onclick = () => { state.page += +b.dataset.p; load(); });
        cfg.after && cfg.after(json, listEl);
      } catch (e) { listEl.innerHTML = `<div class="alert alert-danger">${esc(e.message)}</div>`; }
    };
    let t;
    $$('.filters input, .filters select', cfg.el).forEach((i) => i.addEventListener(i.type === 'search' ? 'input' : 'change', () => {
      clearTimeout(t);
      t = setTimeout(() => { state[i.name] = i.value; state.page = 1; load(); }, i.type === 'search' ? 300 : 0);
    }));
    load();
    return { reload: load, state };
  }

  // ---------------------------------------------------------------- router
  const routes = [];
  function route(pattern, handler, title) {
    const keys = [];
    const rx = new RegExp('^' + pattern.replace(/:(\w+)/g, (_, k) => { keys.push(k); return '([^/]+)'; }) + '/?$');
    routes.push({ rx, keys, handler, title, pattern });
  }
  async function dispatch() {
    const hash = (location.hash || '#/').slice(1).split('?')[0] || '/';
    const params = Object.fromEntries(new URLSearchParams(location.hash.split('?')[1] || ''));
    document.body.classList.remove('menu-open');
    $$('.sidebar .nav-link').forEach((a) => {
      const r = a.dataset.route.slice(1);
      a.classList.toggle('active', r === '/' ? hash === '/' : hash === r || hash.startsWith(r + '/'));
    });
    const view = $('#view');
    for (const r of routes) {
      const m = hash.match(r.rx);
      if (!m) continue;
      r.keys.forEach((k, i) => (params[k] = decodeURIComponent(m[i + 1])));
      if (r.title) setTitle(r.title);
      view.innerHTML = '<div class="loading-block"><div class="spinner-border text-primary"></div></div>';
      window.scrollTo(0, 0);
      try { await r.handler(view, params); } catch (e) { view.innerHTML = `<div class="alert alert-danger m-3">${esc(e.message || 'Could not load this page.')}</div>`; if (e.status !== 403 && e.status !== 402) console.error(e); }
      return;
    }
    view.innerHTML = '<div class="empty"><i class="bi bi-signpost-split"></i>Page not found. <a href="#/">Go to dashboard</a></div>';
  }
  function setTitle(t) { $('#pageTitle').textContent = t; document.title = t + ' · ClassNex'; }
  function go(h) { if (location.hash === h) dispatch(); else location.hash = h; }
  function head(title, sub = '', actions = '') {
    return `<div class="page-head"><div><h1>${esc(title)}</h1>${sub ? `<div class="sub">${sub}</div>` : ''}</div><div class="actions">${actions}</div></div>`;
  }

  // ----------------------------------------------------------- camera/ocr
  /** Opens the camera; resolves with a JPEG Blob or null. Falls back to the file picker (which opens the phone camera). */
  function capturePhoto({ facing = 'user', title = 'Take photo' } = {}) {
    return new Promise(async (resolve) => {
      if (!navigator.mediaDevices?.getUserMedia) { resolve(await pickFile('image/*', facing === 'user' ? 'user' : 'environment')); return; }
      let stream;
      try { stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: facing, width: { ideal: 1280 } }, audio: false }); } catch (e) { toast('Camera not available. Please choose a photo instead.', 'warning'); resolve(await pickFile('image/*')); return; }
      let result = null;
      const m = modal({
        title, size: 'lg',
        body: `<div class="camera-box"><video autoplay playsinline muted></video><canvas class="d-none"></canvas></div>`,
        footer: `<button class="btn btn-light me-auto" data-flip><i class="bi bi-arrow-repeat"></i> Switch camera</button><button class="btn btn-light d-none" data-retake>Retake</button><button class="btn btn-primary px-4" data-snap><i class="bi bi-camera"></i> Capture</button><button class="btn btn-success px-4 d-none" data-use>Use photo</button>`,
        onClose: () => { stream?.getTracks().forEach((t) => t.stop()); resolve(result); },
      });
      const video = $('video', m.el), canvas = $('canvas', m.el);
      video.srcObject = stream;
      $('[data-snap]', m.el).onclick = () => {
        canvas.width = video.videoWidth; canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0);
        video.classList.add('d-none'); canvas.classList.remove('d-none');
        ['[data-snap]', '[data-flip]'].forEach((s) => $(s, m.el).classList.add('d-none'));
        ['[data-retake]', '[data-use]'].forEach((s) => $(s, m.el).classList.remove('d-none'));
      };
      $('[data-retake]', m.el).onclick = () => {
        video.classList.remove('d-none'); canvas.classList.add('d-none');
        ['[data-snap]', '[data-flip]'].forEach((s) => $(s, m.el).classList.remove('d-none'));
        ['[data-retake]', '[data-use]'].forEach((s) => $(s, m.el).classList.add('d-none'));
      };
      $('[data-flip]', m.el).onclick = async () => {
        stream.getTracks().forEach((t) => t.stop());
        facing = facing === 'user' ? 'environment' : 'user';
        try { stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: facing } }); video.srcObject = stream; } catch { toast('Could not switch camera', 'warning'); }
      };
      $('[data-use]', m.el).onclick = () => canvas.toBlob((b) => { result = b; m.close(); }, 'image/jpeg', 0.9);
    });
  }
  function pickFile(accept = 'image/*', capture = null) {
    return new Promise((resolve) => {
      const i = document.createElement('input');
      i.type = 'file'; i.accept = accept;
      if (capture) i.setAttribute('capture', capture);
      i.onchange = () => resolve(i.files[0] || null);
      i.click();
    });
  }
  /** Client-side compression before upload (max side, JPEG). PDFs pass through. */
  async function compress(file, max = 1280, q = 0.85) {
    if (!file || !file.type?.startsWith('image/')) return file;
    const img = await createImageBitmap(file).catch(() => null);
    if (!img) return file;
    const s = Math.min(1, max / Math.max(img.width, img.height));
    const c = document.createElement('canvas');
    c.width = Math.round(img.width * s); c.height = Math.round(img.height * s);
    c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
    return new Promise((r) => c.toBlob((b) => r(b || file), 'image/jpeg', q));
  }
  let tesseractLoading = null;
  /** Reads text from a document image in the browser (Tesseract.js), then asks the API to suggest fields. */
  async function ocr(file, onProgress) {
    if (!file?.type?.startsWith('image/')) throw new Error('OCR works with photos (JPG/PNG). For PDFs, type the details.');
    tesseractLoading ||= new Promise((res, rej) => { const s = document.createElement('script'); s.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js'; s.onload = res; s.onerror = () => rej(new Error('Could not load the OCR engine. Check your internet connection.')); document.head.appendChild(s); });
    await tesseractLoading;
    const small = await compress(file, 2000, 0.92);
    const { data } = await window.Tesseract.recognize(small, 'eng', { logger: (m) => m.status === 'recognizing text' && onProgress && onProgress(Math.round(m.progress * 100)) });
    const parsed = await post('/ocr/parse', { text: data.text || '' });
    return parsed.data;
  }

  // ------------------------------------------------------- online payment
  let rzpLoading = null;
  async function razorpay(order, onPaid) {
    rzpLoading ||= new Promise((res, rej) => { const s = document.createElement('script'); s.src = 'https://checkout.razorpay.com/v1/checkout.js'; s.onload = res; s.onerror = () => rej(new Error('Could not load the payment page.')); document.head.appendChild(s); });
    await rzpLoading;
    const rzp = new window.Razorpay({
      key: order.key_id, amount: order.amount, currency: order.currency, name: order.name, description: order.description, image: order.image || undefined, order_id: order.order_id, prefill: order.prefill || {},
      theme: { color: '#2563eb' },
      handler: async (r) => {
        try { const res = await post('/payments/verify', r); toast(res.message); onPaid && onPaid(res.data); } catch (e) { fail(e); }
      },
    });
    rzp.on('payment.failed', (r) => toast(r.error?.description || 'Payment failed', 'danger'));
    rzp.open();
  }

  // ------------------------------------------------------------ downloads
  async function download(path, filename) {
    try {
      const res = await fetch(BOOT.api + path, { credentials: 'same-origin' });
      if (!res.ok) { let m = 'Download failed'; try { m = (await res.json()).message; } catch {} throw new Error(m); }
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url; a.download = filename || 'download'; document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 4000);
    } catch (e) { fail(e); }
  }
  function openPdf(path) { window.open(BOOT.api + path, '_blank'); }

  // --------------------------------------------------------------- shell
  function shell() {
    $('#menuToggle')?.addEventListener('click', () => document.body.classList.toggle('menu-open'));
    $('#sidebarBackdrop')?.addEventListener('click', () => document.body.classList.remove('menu-open'));
    window.addEventListener('hashchange', dispatch);
    const me = BOOT.me || {};
    const sub = me.subscription;
    if (sub && ['owner', 'teacher'].includes(me.role)) {
      let msg = '';
      if (sub.status === 'expired') msg = `<strong>Your subscription has expired.</strong> Your data is safe, but changes are disabled. ${me.role === 'owner' ? '<a href="#/subscription" class="alert-link">Renew now</a>' : 'Please ask the academy owner to renew.'}`;
      else if (sub.status === 'suspended') msg = '<strong>This account is suspended.</strong> Please contact ClassNex support.';
      else if (sub.status === 'grace') msg = `Your plan expired on ${date(sub.ends_on)}. You are in the grace period — ${me.role === 'owner' ? '<a href="#/subscription" class="alert-link">renew now</a>' : 'please inform the owner'} to avoid interruption.`;
      else if (sub.status === 'trial' && sub.days_left !== null && sub.days_left <= 5 && me.role === 'owner') msg = `Your free trial ends in ${sub.days_left} day(s). <a href="#/subscription" class="alert-link">Choose a plan</a>`;
      if (msg) $('#banner').innerHTML = `<div class="alert ${['expired', 'suspended'].includes(sub.status) ? 'alert-danger' : 'alert-warning'} banner mb-0">${msg}</div>`;
    }
    const bell = async () => {
      try {
        const r = await get('/notifications?unread=1&per_page=5');
        const n = r.meta?.unread || 0;
        const b = $('#bellCount');
        b.hidden = !n; b.textContent = n > 99 ? '99+' : n;
      } catch {}
    };
    bell();
    setInterval(bell, 60000);
    route('/notifications', notificationsView, 'Notifications');
    route('/profile', profileView, 'My Profile');
  }
  async function notificationsView(v) {
    v.innerHTML = head('Notifications', '', '<button class="btn btn-light" id="markAll"><i class="bi bi-check2-all"></i> Mark all read</button>') + '<div id="nl"></div>';
    const lv = listView({
      el: $('#nl', v), endpoint: '/notifications', search: false, empty: 'No notifications yet.', emptyIcon: 'bi-bell',
      columns: [{ label: '', render: (n) => `<div class="d-flex gap-2"><i class="bi ${n.read_at ? 'bi-envelope-open text-muted' : 'bi-envelope-fill text-primary'}"></i><div><div class="fw-bold">${esc(n.title)}</div><div class="text-muted small">${esc(n.body)}</div></div></div>` }, { label: '', cls: 'text-end text-muted small', render: (n) => dateTime(n.created_at) }],
    });
    $('#markAll', v).onclick = async () => { await post('/notifications/read', {}); $('#bellCount').hidden = true; lv.reload(); };
    post('/notifications/read', {}).then(() => ($('#bellCount').hidden = true)).catch(() => {});
  }
  async function profileView(v) {
    const me = (await get('/auth/me')).data;
    v.innerHTML = head('My Profile') + `<div class="row g-3"><div class="col-lg-6"><div class="card"><div class="card-header">Details</div><div class="card-body"><form id="pf">${form([
      { name: 'name', label: 'Name', required: true }, { name: 'email', label: 'Email', type: 'email' }, { name: 'm', label: 'Mobile (login)', attrs: 'disabled', value: (me.country_code || '') + ' ' + (me.mobile || '') },
    ], me)}<button class="btn btn-primary mt-3">Save</button></form></div></div></div>
    <div class="col-lg-6"><div class="card"><div class="card-header">Change password</div><div class="card-body"><form id="pw">${form([
      { name: 'current_password', label: 'Current password', type: 'password', required: true }, { name: 'new_password', label: 'New password', type: 'password', required: true, help: 'At least 8 characters with letters and numbers.' },
    ])}<button class="btn btn-primary mt-3">Change password</button></form></div></div></div></div>`;
    $('#pf', v).onsubmit = async (e) => { e.preventDefault(); try { const r = await put('/auth/profile', readForm(e.target)); toast(r.message); } catch (er) { showErrors(e.target, er.errors); fail(er); } };
    $('#pw', v).onsubmit = async (e) => { e.preventDefault(); try { const r = await post('/auth/change-password', readForm(e.target)); toast(r.message); e.target.reset(); } catch (er) { showErrors(e.target, er.errors); fail(er); } };
  }

  // shared support centre (used by every panel)
  async function supportView(v, p) {
    if (p.id) {
      const t = (await get('/support/' + p.id)).data;
      v.innerHTML = head(t.subject, badge(t.status) + ' · ' + dateTime(t.created_at), '<a class="btn btn-light" href="#/support">Back</a>') +
        `<div class="card mb-3"><div class="card-body">${t.replies.map((r) => `<div class="list-tile align-items-start"><span class="avatar">${esc((r.name || '?')[0])}</span><div><div class="fw-bold">${esc(r.name || 'User')} <span class="text-muted small fw-normal">${dateTime(r.created_at)}</span></div><div style="white-space:pre-wrap">${esc(r.message)}</div></div></div>`).join('')}</div></div>
        <form class="card" id="rf"><div class="card-body">${form([{ name: 'message', label: 'Reply', type: 'textarea', required: true }, ...(BOOT.me.role === 'super_admin' || (BOOT.me.role === 'owner' && t.scope === 'academy') ? [{ name: 'status', label: 'Status', type: 'select', placeholder: false, options: [['in_progress', 'In progress'], ['resolved', 'Resolved'], ['closed', 'Closed'], ['open', 'Open']], col: 'col-md-4' }] : [])])}<button class="btn btn-primary mt-3">Send reply</button></div></form>`;
      $('#rf', v).onsubmit = async (e) => { e.preventDefault(); try { await post(`/support/${t.id}/reply`, readForm(e.target)); toast('Reply sent'); dispatch(); } catch (er) { fail(er); } };
      return;
    }
    v.innerHTML = head('Help & Support', BOOT.me.role === 'owner' ? 'Requests to the ClassNex team, and questions from your parents and students.' : 'Send a question to your academy.', '<button class="btn btn-primary" id="nt"><i class="bi bi-plus-lg"></i> New request</button>') + '<div id="tl"></div>';
    const rows = (await get('/support')).data;
    $('#tl', v).innerHTML = table([
      { label: 'Subject', render: (t) => `<div class="fw-bold">${esc(t.subject)}</div><div class="small text-muted">${esc(t.user_name || '')} ${t.scope === 'platform' ? '· to ClassNex' : ''}</div>` },
      { label: 'Status', render: (t) => badge(t.status) }, { label: 'Updated', render: (t) => dateTime(t.updated_at) },
    ], rows, { empty: 'No support requests yet.', onRow: (t) => go('#/support/' + t.id), emptyIcon: 'bi-life-preserver' });
    bindRows($('#tl', v), rows, (t) => go('#/support/' + t.id));
    $('#nt', v).onclick = () => formModal({ title: 'New support request', fields: [{ name: 'subject', label: 'Subject', required: true }, { name: 'message', label: 'How can we help?', type: 'textarea', rows: 5, required: true }], okText: 'Submit', submit: async (d) => { const r = await post('/support', d); toast(r.message); dispatch(); } });
  }

  function start() { shell(); dispatch(); }

  window.CNX = {
    BOOT, $, $$, esc, api, get, post, put, del, qs, uuid, ApiError,
    money, rupees, date, dateTime, time, today, month, label, WEEK, badge, photo, person, wa,
    toast, fail, modal, confirm, form, fieldHtml, readForm, showErrors, bindMulti, formModal,
    table, bindRows, listView, route, dispatch, go, head, setTitle,
    capturePhoto, pickFile, compress, ocr, razorpay, download, openPdf, supportView, start,
  };
})();
