/* ClassNex Super Admin panel */
(() => {
  'use strict';
  const C = window.CNX;
  const { $, $$, esc, get, post, put, del, qs, money, rupees, date, dateTime, badge, toast, fail, modal, confirm, form, readForm, showErrors, formModal, table, bindRows, listView, route, go, head, label } = C;

  let PK = null;
  const packages = async (force) => (PK && !force ? PK : (PK = (await get('/admin/packages')).data));

  // ============================================================ DASHBOARD
  route('/', async (v) => {
    const d = (await get('/admin/dashboard')).data;
    const st = (href, ic, cls, val, lbl, sub = '') => `<div class="col-6 col-xl-3"><a class="stat" href="${href}"><div class="ic ${cls}"><i class="bi ${ic}"></i></div><div><div class="val">${val}</div><div class="lbl">${lbl}</div>${sub ? `<div class="small mt-1">${sub}</div>` : ''}</div></a></div>`;
    const ns = {};
    d.notification_stats.forEach((r) => { (ns[r.channel] ||= {})[r.status] = +r.total; });
    const max = Math.max(1, ...d.revenue_trend.map((r) => +r.total));
    v.innerHTML = head('Platform overview', date(C.today()), '<a class="btn btn-primary" href="#/owners?new=1"><i class="bi bi-person-plus"></i> Add owner</a>') + `
      <div class="row g-3 mb-3">
        ${st('#/owners', 'bi-building', 'ic-blue', d.total_academies, 'Academies', `${d.active_owners} active · ${d.inactive_owners} inactive owners`)}
        ${st('#/owners', 'bi-people', 'ic-purple', d.total_students.toLocaleString('en-IN'), 'Active students')}
        ${st('#/owners?subscription=active', 'bi-gem', 'ic-green', d.active_subscriptions, 'Active subscriptions', `${d.trial_subscriptions} on trial · <span class="text-danger">${d.expired_subscriptions} expired</span>`)}
        ${st('#/sub-payments', 'bi-currency-rupee', 'ic-orange', money(d.monthly_revenue_paise), 'Revenue this month', `${d.pending_subscription_payments} pending online payments`)}
      </div>
      <div class="row g-3">
        <div class="col-lg-7"><div class="card h-100"><div class="card-header">Subscription revenue</div><div class="card-body">${d.revenue_trend.length ? `<div class="chart-bars">${d.revenue_trend.map((r) => `<div class="bar"><div class="small fw-bold text-dark">${money(r.total)}</div><span style="height:${Math.max(3, (r.total / max) * 100)}%"></span>${r.month}</div>`).join('')}</div>` : '<div class="empty py-4">No revenue recorded yet</div>'}</div></div></div>
        <div class="col-lg-5"><div class="card h-100"><div class="card-header">Packages</div><div class="card-body py-1">${d.package_stats.map((p) => `<div class="list-tile"><span class="flex-grow-1 fw-bold">${esc(p.name)}</span><span>${p.academies} academies</span></div>`).join('')}</div></div></div>
        <div class="col-lg-6"><div class="card h-100"><div class="card-header"><i class="bi bi-alarm text-warning"></i> Expiring in 7 days</div><div class="card-body py-1">${d.expiring_7_days.length ? d.expiring_7_days.map((x) => `<a class="list-tile text-decoration-none text-reset" href="#/owners/${x.academy_id}"><span class="flex-grow-1"><strong>${esc(x.name)}</strong> <span class="small text-muted">${esc(x.package)}</span></span>${badge(x.status)} <span class="small">${date(x.ends_on)}</span></a>`).join('') : '<div class="text-muted py-3">Nothing expiring this week.</div>'}</div></div></div>
        <div class="col-lg-6"><div class="card h-100"><div class="card-header"><i class="bi bi-send text-primary"></i> Notifications this month</div><div class="card-body">
          <div class="d-flex flex-wrap gap-3 mb-2"><div><div class="small text-muted">WhatsApp</div><div class="fs-4 fw-bold">${d.whatsapp_month.toLocaleString('en-IN')}</div></div>${Object.entries(ns).map(([ch, s]) => `<div><div class="small text-muted">${label(ch)}</div><div class="small">${Object.entries(s).map(([k, n]) => `${badge(k, label(k) + ' ' + n)}`).join(' ')}</div></div>`).join('')}</div>
          <div class="small">Last cron run: ${d.last_cron ? `${esc(d.last_cron.task)} · ${dateTime(d.last_cron.started_at)} ${badge(d.last_cron.status === 'ok' ? 'success' : d.last_cron.status)}` : '<span class="text-danger fw-bold">never – set up the cron job</span>'}</div></div></div></div>
        <div class="col-lg-6"><div class="card"><div class="card-header">New academies</div><div class="card-body py-1">${d.recent_academies.map((a) => `<a class="list-tile text-decoration-none text-reset" href="#/owners/${a.id}"><span class="flex-grow-1"><strong>${esc(a.name)}</strong> <span class="small text-muted">${esc(a.owner || '')} ${a.city ? '· ' + esc(a.city) : ''}</span></span><span class="small">${date(a.created_at)}</span></a>`).join('') || '<div class="text-muted py-3">None yet</div>'}</div></div></div>
        <div class="col-lg-6"><div class="card"><div class="card-body d-flex gap-3"><a href="#/enquiries" class="btn btn-light flex-fill"><div class="fs-4 fw-bold">${d.new_enquiries}</div>New enquiries</a><a href="#/support" class="btn btn-light flex-fill"><div class="fs-4 fw-bold">${d.open_tickets}</div>Open tickets</a></div></div></div>
      </div>`;
  }, 'Dashboard');

  // =============================================================== OWNERS
  route('/owners', async (v, p) => {
    const pk = await packages();
    v.innerHTML = head('Owners & Academies', '', '<button class="btn btn-primary" id="add"><i class="bi bi-person-plus"></i> Add owner</button>') + '<div id="ol"></div>';
    listView({
      el: $('#ol', v), endpoint: '/admin/owners', params: { subscription: p.subscription || '' }, searchPlaceholder: 'Academy, owner, mobile, email…',
      filters: [{ name: 'status', placeholder: 'Any academy status', options: [['active', 'Active'], ['inactive', 'Inactive'], ['suspended', 'Suspended']] }, { name: 'subscription', placeholder: 'Any subscription', options: ['trial', 'active', 'grace', 'expired', 'suspended'].map((s) => [s, label(s)]) }],
      columns: [
        { label: 'Academy', render: (r) => `<div class="fw-bold">${esc(r.academy_name)}</div><div class="small text-muted">${esc(r.code)} ${r.city ? '· ' + esc(r.city) : ''}</div>` },
        { label: 'Owner', render: (r) => `${esc(r.owner_name || '–')}<div class="small text-muted">${esc(r.country_code || '')} ${esc(r.mobile || '')}</div>` },
        { label: 'Plan', render: (r) => `${esc(r.package_name || '–')} ${r.sub_status ? badge(r.sub_status) : ''}<div class="small text-muted">${r.ends_on ? 'till ' + date(r.ends_on) : ''}</div>` },
        { label: 'Students', render: (r) => r.students }, { label: 'Status', render: (r) => badge(r.academy_status) + ' ' + (r.owner_status !== 'active' ? badge(r.owner_status) : '') },
      ], onRow: (r) => go('#/owners/' + r.academy_id), empty: 'No academies yet.', emptyIcon: 'bi-building',
    });
    const add = () => formModal({
      title: 'Add Class Owner', size: 'lg',
      intro: '<p class="small text-muted">Creates the owner login, their academy and a subscription in one step. Leave the password empty to send an activation link on WhatsApp / email.</p>',
      fields: [
        { name: 'owner_name', label: 'Owner name', required: true, col: 'col-md-6' }, { name: 'mobile', label: 'Mobile (login)', type: 'phone', required: true, col: 'col-md-6' },
        { name: 'email', label: 'Email', type: 'email', col: 'col-md-6' }, { name: 'password', label: 'Password (optional)', type: 'password', col: 'col-md-6' },
        { name: 'academy_name', label: 'Academy name', required: true, col: 'col-md-6' }, { name: 'city', label: 'City', col: 'col-md-6' },
        { name: 'category', label: 'Category', type: 'select', options: ['Cricket', 'Football', 'Yoga', 'Karate / Martial arts', 'Dance', 'Music / Singing', 'Swimming', 'Art', 'Gym / Fitness', 'Coaching / Tuition', 'Languages', 'Other'].map((x) => [x, x]), col: 'col-md-6' },
        { name: 'package_id', label: 'Package', type: 'select', required: true, options: pk.packages.filter((x) => x.status === 'active').map((x) => [x.id, `${x.name} – ${money(x.price_paise)}/${label(x.billing_cycle)}`]), col: 'col-md-6' },
        { name: 'subscription_status', label: 'Start as', type: 'select', placeholder: false, options: [['trial', 'Free trial'], ['active', 'Paid / active']], col: 'col-md-6' },
        { name: 'ends_on', label: 'Valid till (blank = by package)', type: 'date', col: 'col-md-6' },
      ],
      submit: async (d) => { const r = await post('/admin/owners', d); toast(r.message); if (r.data.invite?.link) modal({ title: 'Activation link', body: `<p>Share this link with the owner:</p><input class="form-control" readonly value="${esc(r.data.invite.link)}">${r.data.invite.whatsapp_link ? `<a class="btn btn-success mt-2" target="_blank" href="${esc(r.data.invite.whatsapp_link)}"><i class="bi bi-whatsapp"></i> Send on WhatsApp</a>` : ''}` }); go('#/owners/' + r.data.academy_id); },
    });
    $('#add', v).onclick = add;
    if (p.new) add();
  }, 'Owners');

  route('/owners/:id', async (v, p) => {
    const a = (await get('/admin/academies/' + p.id)).data;
    const pk = await packages();
    const s = a.subscription;
    v.innerHTML = head(a.name, `${esc(a.code)} · created ${date(a.created_at)} · ${badge(a.status)}`, '<a class="btn btn-light" href="#/owners">Back</a>') + `
      <div class="row g-3"><div class="col-lg-5">
        <div class="card mb-3"><div class="card-header">Owner</div><div class="card-body"><dl class="kv">${[['Name', esc(a.owner_name || '–')], ['Mobile', esc(a.owner_mobile || '–')], ['Email', esc(a.owner_email || '–')], ['Login', badge(a.owner_status || 'invited')], ['City', esc(a.city || '–')], ['Admission link', `<a target="_blank" href="${C.BOOT.root}/admission.php?a=${esc(a.slug)}">open</a>`]].map(([k, x]) => `<dt>${k}</dt><dd>${x}</dd>`).join('')}</dl>
          <div class="d-flex flex-wrap gap-2 mt-3"><button class="btn btn-sm btn-light" id="edit"><i class="bi bi-pencil"></i> Edit</button><button class="btn btn-sm btn-light" id="inv"><i class="bi bi-key"></i> Send login / reset link</button>
          ${a.status === 'active' ? '<button class="btn btn-sm btn-outline-danger" data-st="suspended">Suspend academy</button>' : '<button class="btn btn-sm btn-outline-success" data-st="active">Activate academy</button>'}</div></div></div>
        <div class="card"><div class="card-header">Usage</div><div class="card-body">${Object.entries(a.usage.usage).map(([k, u]) => `<div class="d-flex small mb-1"><span>${label(k)}</span><span class="ms-auto">${u.used} / ${u.limit ?? '∞'}</span></div>`).join('')}<div class="small mt-2">Teachers: ${a.counts.teachers} · Batches: ${a.counts.batches} · Fees collected this month: ${money(a.counts.payments_month_paise)}</div></div></div>
      </div><div class="col-lg-7">
        <div class="card mb-3"><div class="card-header">Subscription ${badge(s.status)}</div><div class="card-body">
          <div class="fs-4 fw-bold">${esc(s.package_name || 'No package')}</div><div class="text-muted">${s.ends_on ? `${date(s.starts_on)} → ${date(s.ends_on)} (${s.days_left} days)` : 'Lifetime'}</div>
          <div class="d-flex flex-wrap gap-2 mt-3"><button class="btn btn-primary" data-sa="assign">Assign / change package</button><button class="btn btn-light" data-sa="renew">Renew</button><button class="btn btn-light" data-sa="extend">Extend days</button><button class="btn btn-light" data-sa="trial">Start trial</button>
          ${s.status === 'suspended' ? '<button class="btn btn-outline-success" data-sa="unsuspend">Restore</button>' : '<button class="btn btn-outline-danger" data-sa="suspend">Suspend subscription</button>'}<button class="btn btn-light" data-sa="pay">Record payment</button></div></div></div>
        <h6 class="fw-bold">History</h6>${table([{ label: 'Package', render: (h) => esc(h.package_name) }, { label: 'Status', render: (h) => badge(h.status) + (+h.is_current ? ' <span class="badge b-info">current</span>' : '') }, { label: 'Period', render: (h) => `${date(h.starts_on)} → ${h.ends_on ? date(h.ends_on) : '∞'}` }, { label: 'Price', render: (h) => money(h.price_paise) }, { label: 'Note', render: (h) => `<span class="small">${esc(h.notes || '')}</span>` }], a.history)}
        <h6 class="fw-bold mt-3">Payments</h6>${table([{ label: 'Date', render: (x) => dateTime(x.paid_at || x.created_at) }, { label: 'Package', render: (x) => esc(x.package_name) }, { label: 'Amount', render: (x) => money(x.amount_paise) }, { label: 'Method', render: (x) => esc(x.method) + (x.reference ? ' · ' + esc(x.reference) : '') }, { label: 'Status', render: (x) => badge(x.status) }], a.payments, { empty: 'No payments recorded.' })}
      </div></div>`;
    const pkOpts = pk.packages.map((x) => [x.id, `${x.name} – ${money(x.price_paise)}/${label(x.billing_cycle)}`]);
    const sub = async (d, msg) => { const r = await post(`/admin/academies/${a.id}/subscription`, d); toast(r.message || msg); C.dispatch(); };
    $$('[data-sa]', v).forEach((b) => b.onclick = async () => {
      const act = b.dataset.sa;
      try {
        if (act === 'assign' || act === 'trial') return formModal({ title: act === 'trial' ? 'Start trial' : 'Assign package', fields: [
          { name: 'package_id', label: 'Package', type: 'select', required: true, options: pkOpts, value: s.package_id }, { name: 'starts_on', label: 'Starts', type: 'date', value: C.today(), col: 'col-6' }, { name: 'ends_on', label: 'Ends (blank = by package)', type: 'date', col: 'col-6' },
          ...(act === 'assign' ? [{ name: 'price', label: 'Price (blank = package price)', type: 'money', col: 'col-6' }, { type: 'html', name: 'cl', html: '<div class="small fw-bold mt-2">Custom limits for this owner (optional)</div>' }, ...['students', 'teachers', 'batches', 'branches'].map((k) => ({ name: 'lim_' + k, label: label(k), type: 'number', col: 'col-3' }))] : []),
          { name: 'notes', label: 'Note' },
        ], submit: async (d) => { const custom = {}; ['students', 'teachers', 'batches', 'branches'].forEach((k) => { if (d['lim_' + k]) custom[k] = +d['lim_' + k]; delete d['lim_' + k]; }); await sub({ ...d, action: act, custom_limits: Object.keys(custom).length ? custom : undefined }); } });
        if (act === 'renew') return formModal({ title: 'Renew subscription', fields: [{ name: 'package_id', label: 'Package', type: 'select', options: pkOpts, value: s.package_id }, { name: 'price', label: 'Amount (blank = package price)', type: 'money' }, { name: 'record_payment', label: 'Record a payment for this renewal', type: 'switch', value: 1 }, { name: 'method', label: 'Payment method', type: 'select', placeholder: false, options: [['bank_transfer', 'Bank transfer'], ['upi', 'UPI'], ['cash', 'Cash'], ['cheque', 'Cheque'], ['manual', 'Other']], col: 'col-6' }, { name: 'reference', label: 'Reference', col: 'col-6' }], submit: async (d) => sub({ ...d, action: 'renew' }) });
        if (act === 'extend') return formModal({ title: 'Extend validity', fields: [{ name: 'days', label: 'Days to add', type: 'number', required: true, value: 30 }], submit: async (d) => sub({ ...d, action: 'extend' }) });
        if (act === 'pay') return formModal({ title: 'Record subscription payment', fields: [{ name: 'package_id', label: 'Package', type: 'select', required: true, options: pkOpts, value: s.package_id }, { name: 'amount', label: 'Amount', type: 'money', required: true, col: 'col-6' }, { name: 'paid_on', label: 'Paid on', type: 'date', value: C.today(), col: 'col-6' }, { name: 'method', label: 'Method', col: 'col-6' }, { name: 'reference', label: 'Reference', col: 'col-6' }, { name: 'period_from', label: 'Period from', type: 'date', col: 'col-6' }, { name: 'period_to', label: 'Period to', type: 'date', col: 'col-6' }], submit: async (d) => { const r = await post('/admin/subscription-payments', { ...d, academy_id: a.id }); toast(r.message); C.dispatch(); } });
        if (!(await confirm(act === 'suspend' ? 'Suspend this subscription? The academy becomes read-only. No data is deleted.' : 'Restore this subscription?', { danger: act === 'suspend' }))) return;
        await sub({ action: act });
      } catch (e) { fail(e); }
    });
    $('#edit', v).onclick = () => formModal({ title: 'Edit academy & owner', values: { ...a, owner_name: a.owner_name, owner_email: a.owner_email, owner_mobile: a.owner_mobile }, fields: [{ name: 'name', label: 'Academy name', required: true }, { name: 'city', label: 'City', col: 'col-6' }, { name: 'mobile', label: 'Academy phone', col: 'col-6' }, { name: 'owner_name', label: 'Owner name', col: 'col-6' }, { name: 'owner_mobile', label: 'Owner mobile (login)', col: 'col-6' }, { name: 'owner_email', label: 'Owner email', type: 'email', col: 'col-6' }, { name: 'owner_status', label: 'Owner login', type: 'select', placeholder: false, options: [['active', 'Active'], ['inactive', 'Inactive'], ['suspended', 'Suspended']], value: a.owner_status, col: 'col-6' }], submit: async (d) => { const r = await put('/admin/academies/' + a.id, d); toast(r.message); C.dispatch(); } });
    $('#inv', v).onclick = async () => { try { const r = await post(`/admin/academies/${a.id}/invite`); modal({ title: 'Link sent', body: `<p>${esc(r.message)}</p><input class="form-control" readonly value="${esc(r.data.link || '')}">` }); } catch (e) { fail(e); } };
    $$('[data-st]', v).forEach((b) => b.onclick = async () => { if (!(await confirm(b.dataset.st === 'suspended' ? 'Suspend this academy? All its users will be logged out. Data is kept.' : 'Activate this academy?', { danger: b.dataset.st === 'suspended' }))) return; try { const r = await put('/admin/academies/' + a.id, { status: b.dataset.st }); toast(r.message); C.dispatch(); } catch (e) { fail(e); } });
  }, 'Academy');

  // ============================================================= PACKAGES
  route('/packages', async (v) => {
    const pk = await packages(true);
    v.innerHTML = head('Packages', 'Limits and features are enforced by the backend for web and Android.', '<button class="btn btn-primary" id="add"><i class="bi bi-plus-lg"></i> New package</button>') + `<div class="row g-3">${pk.packages.map((p) => `<div class="col-md-6 col-xl-4"><div class="card h-100 ${+p.is_featured ? 'border-primary' : ''}" role="button" data-id="${p.id}"><div class="card-body"><div class="d-flex"><div class="fw-bold fs-5">${esc(p.name)}</div><div class="ms-auto">${badge(p.status)} ${+p.is_public ? '' : '<span class="badge b-muted">Hidden</span>'}</div></div>
      <div class="fs-3 fw-bold">${money(p.price_paise)}<span class="fs-6 text-muted fw-normal">/${label(p.billing_cycle)}</span></div><div class="small text-muted mb-2">${p.trial_days} days trial · ${p.academies} academies</div>
      <div class="small mb-2">Students ${p.student_limit ?? '∞'} · Teachers ${p.teacher_limit ?? '∞'} · Batches ${p.batch_limit ?? '∞'} · Branches ${p.branch_limit ?? '∞'} · WhatsApp/mo ${p.monthly_whatsapp_limit ?? '∞'}</div>
      <div class="d-flex flex-wrap gap-1">${p.features.map((f) => `<span class="badge b-success">${esc(pk.feature_labels[f] || f)}</span>`).join('')}</div></div></div></div>`).join('')}</div>`;
    const edit = (p = {}) => formModal({
      title: p.id ? 'Edit ' + p.name : 'New package', size: 'lg', values: { ...p, price: p.id ? rupees(p.price_paise) : '', features: p.features || ['attendance', 'fees', 'inapp', 'push', 'manual_reminders', 'parent_access'], is_public: p.id ? p.is_public : 1 },
      fields: [
        { name: 'name', label: 'Package name', required: true, col: 'col-md-6' }, { name: 'price', label: 'Price', type: 'money', required: true, col: 'col-md-3' },
        { name: 'billing_cycle', label: 'Billing', type: 'select', required: true, placeholder: false, options: [['monthly', 'Monthly'], ['quarterly', 'Quarterly'], ['half_yearly', 'Half-yearly'], ['yearly', 'Yearly'], ['lifetime', 'Lifetime']], col: 'col-md-3' },
        { name: 'description', label: 'Description', type: 'textarea', rows: 2 },
        { type: 'html', name: 'h1', html: '<div class="fw-bold small mt-2">Limits (blank = unlimited)</div>' },
        ...[['student_limit', 'Students'], ['teacher_limit', 'Teachers'], ['activity_limit', 'Activities'], ['batch_limit', 'Batches'], ['branch_limit', 'Branches'], ['storage_limit_mb', 'Storage MB'], ['monthly_whatsapp_limit', 'WhatsApp / month'], ['trial_days', 'Trial days']].map(([k, l]) => ({ name: k, label: l, type: 'number', col: 'col-6 col-md-3' })),
        { name: 'features', label: 'Features', type: 'multicheck', options: Object.entries(pk.feature_labels) },
        { name: 'is_public', label: 'Show on website pricing', type: 'switch', col: 'col-md-4' }, { name: 'is_featured', label: 'Highlight as popular', type: 'switch', col: 'col-md-4' }, { name: 'sort_order', label: 'Order', type: 'number', col: 'col-md-4' },
        ...(p.id ? [{ name: 'status', label: 'Status', type: 'select', placeholder: false, options: [['active', 'Active'], ['inactive', 'Inactive']], col: 'col-md-4' }] : []),
      ],
      submit: async (d) => { ['student_limit', 'teacher_limit', 'activity_limit', 'batch_limit', 'branch_limit', 'storage_limit_mb', 'monthly_whatsapp_limit'].forEach((k) => { if (d[k] === '') d[k] = null; }); const r = p.id ? await put('/admin/packages/' + p.id, d) : await post('/admin/packages', d); toast(r.message); C.dispatch(); },
    });
    $$('[data-id]', v).forEach((c) => c.onclick = () => edit(pk.packages.find((x) => String(x.id) === c.dataset.id)));
    $('#add', v).onclick = () => edit();
  }, 'Packages');

  route('/sub-payments', async (v) => {
    v.innerHTML = head('Subscription payments') + '<div id="l"></div>';
    listView({ el: $('#l', v), endpoint: '/admin/subscription-payments', search: false, filters: [{ name: 'status', placeholder: 'Any status', options: [['paid', 'Paid'], ['pending', 'Pending'], ['failed', 'Failed']] }], columns: [{ label: 'Date', render: (x) => dateTime(x.paid_at || x.created_at) }, { label: 'Academy', render: (x) => `<a href="#/owners/${x.academy_id}">${esc(x.academy_name)}</a>` }, { label: 'Package', render: (x) => esc(x.package_name) }, { label: 'Amount', render: (x) => money(x.amount_paise) }, { label: 'Method', render: (x) => esc(x.method) + (x.reference ? `<div class="small text-muted">${esc(x.reference)}</div>` : '') }, { label: 'Status', render: (x) => badge(x.status) }, { label: 'By', render: (x) => esc(x.recorded_by_name || 'Online') }], empty: 'No payments.' });
  }, 'Subscription Payments');

  // ========================================================= INTEGRATIONS
  route('/integrations', async (v, p) => {
    const tab = p.tab || 'whatsapp';
    const d = (await get('/admin/integrations')).data;
    const modeSel = (m) => ({ name: 'mode', label: 'Mode', type: 'select', placeholder: false, value: m, options: [['off', 'Off'], ['test', 'Test (log only – nothing is sent)'], ['live', 'Live']], col: 'col-md-4' });
    const tabs = [['whatsapp', 'WhatsApp Cloud API'], ['firebase', 'Firebase Push'], ['email', 'Email (SMTP)'], ['sms', 'SMS'], ['razorpay', 'Payments (Razorpay)'], ['ocr', 'OCR']];
    v.innerHTML = head('Integrations', 'Credentials are encrypted in the database and never sent to browsers or the app.') + `<ul class="nav nav-pills mb-3 gap-1 flex-wrap">${tabs.map(([k, l]) => `<li><a class="nav-link ${k === tab ? 'active' : ''}" href="#/integrations?tab=${k}">${l}</a></li>`).join('')}</ul><div id="it"></div>`;
    const el = $('#it', v);
    const card = (title, body, test = '') => `<form class="card" id="f"><div class="card-header">${title}</div><div class="card-body">${body}<div class="d-flex gap-2 mt-3"><button class="btn btn-primary">Save</button>${test}</div></div></form>`;
    if (tab === 'whatsapp') {
      const w = d.whatsapp;
      el.innerHTML = card('Official WhatsApp Business Cloud API', `<div class="alert alert-light small">Get these from <strong>Meta for Developers → your app → WhatsApp → API Setup</strong>. Use a <em>permanent System User token</em>. Webhook: <code>${esc(w.webhook_url)}</code> (subscribe to <code>messages</code>). Last test: ${esc(w.last_test_result || '—')}</div>` + form([
        modeSel(w.mode), { name: 'api_version', label: 'API version', value: w.api_version, col: 'col-md-4' }, { name: 'rate_per_minute', label: 'Max messages / minute', type: 'number', value: w.rate_per_minute, col: 'col-md-4' },
        { name: 'business_account_id', label: 'WhatsApp Business Account ID', value: w.business_account_id, col: 'col-md-6' }, { name: 'phone_number_id', label: 'Phone Number ID', value: w.phone_number_id, col: 'col-md-6' },
        { name: 'access_token', label: 'Access token', type: 'password', placeholder: w.access_token || 'not set', col: 'col-md-6' }, { name: 'app_secret', label: 'App secret (webhook signature)', type: 'password', placeholder: w.app_secret || 'not set', col: 'col-md-6' },
        { name: 'webhook_verify_token', label: 'Webhook verify token', value: w.webhook_verify_token, col: 'col-md-6', help: 'Any secret text; enter the same in Meta webhook settings.' },
      ]) + `<h6 class="fw-bold mt-4">Approved message templates</h6><p class="small text-muted">Create these templates in WhatsApp Manager (category Utility) with the variables in the order shown, then enter the exact names here.</p>${table([{ label: 'Event', render: (t) => label(t.event_key) }, { label: 'Template name', render: (t) => `<input class="form-control form-control-sm" data-tid="${t.id}" data-k="wa_template_name" value="${esc(t.wa_template_name || '')}">` }, { label: 'Lang', render: (t) => `<input class="form-control form-control-sm" style="width:70px" data-tid="${t.id}" data-k="wa_language" value="${esc(t.wa_language || 'en')}">` }, { label: 'Variables {{1}}…', render: (t) => `<span class="small text-muted">${esc((JSON.parse(t.wa_params || '[]') || []).join(', '))}</span>` }], w.templates)}`,
        '<button type="button" class="btn btn-light" id="test"><i class="bi bi-send"></i> Send test (hello_world)</button>');
      $('#test', el).onclick = () => formModal({ title: 'Send WhatsApp test', fields: [{ name: 'to', label: 'WhatsApp number', type: 'phone', required: true }], okText: 'Send', submit: async (x) => { const r = await post('/admin/integrations/whatsapp/test', x); toast(r.message, r.data.simulated ? 'info' : 'success'); } });
    } else if (tab === 'firebase') {
      const f = d.firebase;
      el.innerHTML = card('Firebase Cloud Messaging (HTTP v1)', `<div class="alert alert-light small">Firebase Console → Project settings → <strong>Service accounts</strong> → Generate new private key. Paste the JSON below. The Android app also needs <code>google-services.json</code> at build time (see docs). Status: ${f.configured ? `configured (${esc(f.service_account_email)})` : 'not configured'} · Last test: ${esc(f.last_test_result || '—')}</div>` + form([modeSel(f.mode), { name: 'project_id', label: 'Project ID', value: f.project_id, col: 'col-md-8' }, { name: 'service_account_json', label: 'Service account JSON (leave empty to keep current)', type: 'textarea', rows: 6 }]),
        '<button type="button" class="btn btn-light" id="test">Send test to my devices</button>');
      $('#test', el).onclick = async () => { try { const r = await post('/admin/integrations/firebase/test'); toast(r.message); } catch (e) { fail(e); } };
    } else if (tab === 'email') {
      const m = d.email;
      el.innerHTML = card('SMTP email', `<div class="small text-muted mb-2">Use your hosting mailbox, Gmail/Workspace (App Password), Zoho, Amazon SES, etc. Last test: ${esc(m.last_test_result || '—')}</div>` + form([modeSel(m.mode), { name: 'host', label: 'SMTP host', value: m.host, col: 'col-md-4' }, { name: 'port', label: 'Port', type: 'number', value: m.port, col: 'col-md-2' }, { name: 'encryption', label: 'Encryption', type: 'select', placeholder: false, value: m.encryption, options: [['tls', 'STARTTLS (587)'], ['ssl', 'SSL (465)'], ['none', 'None']], col: 'col-md-2' }, { name: 'username', label: 'Username', value: m.username, col: 'col-md-6' }, { name: 'password', label: 'Password', type: 'password', placeholder: m.password || 'not set', col: 'col-md-6' }, { name: 'from_email', label: 'From email', value: m.from_email, col: 'col-md-6' }, { name: 'from_name', label: 'From name', value: m.from_name, col: 'col-md-6' }]),
        '<button type="button" class="btn btn-light" id="test">Send test email</button>');
      $('#test', el).onclick = () => formModal({ title: 'Send test email', fields: [{ name: 'to', label: 'To', type: 'email', required: true, value: C.BOOT.me.email || '' }], okText: 'Send', submit: async (x) => { const r = await post('/admin/integrations/email/test', x); toast(r.message); } });
    } else if (tab === 'sms') {
      const s = d.sms;
      el.innerHTML = card('SMS gateway (optional)', `<div class="small text-muted mb-2">Works with any HTTP SMS API. Parameters JSON may use <code>{to}</code>, <code>{to_national}</code>, <code>{message}</code>, <code>{sender}</code>, <code>{api_key}</code>; keys starting with <code>header:</code> become HTTP headers. In India, messages must match your DLT-registered templates.</div>` + form([modeSel(s.mode), { name: 'provider', label: 'Provider name', value: s.provider, col: 'col-md-4' }, { name: 'http_method', label: 'Method', type: 'select', placeholder: false, value: s.http_method, options: [['POST', 'POST (JSON)'], ['GET', 'GET']], col: 'col-md-4' }, { name: 'endpoint_url', label: 'Endpoint URL', value: s.endpoint_url }, { name: 'params_template', label: 'Parameters JSON', type: 'textarea', rows: 4, value: s.params_template || '{"to":"{to}","message":"{message}","sender":"{sender}","header:Authorization":"Bearer {api_key}"}' }, { name: 'api_key', label: 'API key', type: 'password', placeholder: s.api_key || 'not set', col: 'col-md-6' }, { name: 'sender_id', label: 'Sender ID', value: s.sender_id, col: 'col-md-6' }]),
        '<button type="button" class="btn btn-light" id="test">Send test SMS</button>');
      $('#test', el).onclick = () => formModal({ title: 'Send test SMS', fields: [{ name: 'to', label: 'Mobile', type: 'phone', required: true }], okText: 'Send', submit: async (x) => { const r = await post('/admin/integrations/sms/test', x); toast(r.message); } });
    } else if (tab === 'razorpay') {
      const r = d.razorpay;
      el.innerHTML = card('Razorpay – ClassNex subscription payments', `<div class="small text-muted mb-2">Owners pay for their plans online with these keys. (Each academy configures its own keys for student fees.) Webhook URL: <code>${esc(r.webhook_url)}</code> with events <code>payment.captured</code>, <code>order.paid</code>.</div>` + form([{ name: 'key_id', label: 'Key ID', value: r.key_id, col: 'col-md-6' }, { name: 'key_secret', label: 'Key secret', type: 'password', placeholder: r.key_secret || 'not set', col: 'col-md-6' }, { name: 'webhook_secret', label: 'Webhook secret', type: 'password', placeholder: r.webhook_secret || 'not set', col: 'col-md-6' }, { name: 'is_enabled', label: 'Enabled', type: 'switch', value: r.is_enabled ? 1 : 0, col: 'col-md-6' }]));
    } else if (tab === 'ocr') {
      const o = d.ocr;
      el.innerHTML = card('Document OCR', '<div class="small text-muted mb-2">By default OCR runs on the device (Android ML Kit, browser Tesseract.js) — free and private. Optionally add a Google Cloud Vision API key for higher accuracy server-side OCR. Extracted Aadhaar numbers are always masked and never stored.</div>' + form([{ name: 'provider', label: 'Provider', type: 'select', placeholder: false, value: o.provider, options: [['client', 'On device (default)'], ['google_vision', 'Google Cloud Vision']], col: 'col-md-6' }, { name: 'google_vision_key', label: 'Vision API key', type: 'password', placeholder: o.google_vision_key || 'not set', col: 'col-md-6' }]));
    }
    $('#f', el).onsubmit = async (e) => {
      e.preventDefault();
      const data = readForm(e.target);
      if (tab === 'whatsapp') data.templates = [...new Set($$('[data-tid]', el).map((i) => i.dataset.tid))].map((id) => ({ id: +id, wa_template_name: $(`[data-tid="${id}"][data-k=wa_template_name]`, el).value, wa_language: $(`[data-tid="${id}"][data-k=wa_language]`, el).value }));
      try { const r = await post('/admin/integrations/' + tab, data); toast(r.message); } catch (er) { showErrors(e.target, er.errors); fail(er); }
    };
  }, 'Integrations');

  route('/templates', async (v) => {
    const d = (await get('/admin/templates')).data;
    v.innerHTML = head('Message templates', 'Default texts for every academy. Variables like {student_name}, {amount}, {due_date}, {academy_name} are replaced automatically.') + table([
      { label: 'Event', render: (t) => `<strong>${esc(d.events[t.event_key] || t.event_key)}</strong>` }, { label: 'Channel', render: (t) => label(t.channel) },
      { label: 'Text', render: (t) => `<div class="small text-muted" style="max-width:520px">${esc(t.body)}</div>` }, { label: '', render: (t) => +t.is_active ? '' : badge('inactive') },
    ], d.templates, { onRow: (t) => edit(t) });
    const edit = (t) => formModal({ title: `${d.events[t.event_key] || t.event_key} · ${label(t.channel)}`, size: 'lg', values: { ...t, wa_params: (JSON.parse(t.wa_params || '[]') || []).join(', ') }, fields: [{ name: 'title', label: 'Title / subject' }, { name: 'body', label: 'Text', type: 'textarea', rows: 6, required: true }, ...(t.channel === 'whatsapp' ? [{ name: 'wa_template_name', label: 'Meta template name', col: 'col-md-6' }, { name: 'wa_language', label: 'Language', col: 'col-md-2' }, { name: 'wa_params', label: 'Variables order', col: 'col-md-4', help: 'Comma separated, maps to {{1}}, {{2}}…' }] : []), { name: 'is_active', label: 'Active', type: 'switch' }], submit: async (x) => { const r = await put('/admin/templates/' + t.id, x); toast(r.message); C.dispatch(); } });
    bindRows(v, d.templates, edit);
  }, 'Templates');

  route('/reminders', async (v) => {
    const rules = (await get('/admin/reminder-rules')).data.map((r) => +r.offset_days);
    v.innerHTML = head('Default reminder schedule', 'Used by academies that have not customised their own schedule.') + `<div class="card"><div class="card-body"><div class="d-flex flex-wrap gap-2">${[-15, -10, -7, -5, -3, -2, -1, 0, 1, 2, 3, 5, 7, 10, 15, 30].map((d) => `<label class="btn btn-sm btn-outline-primary ${rules.includes(d) ? 'active' : ''}"><input type="checkbox" class="d-none" data-off="${d}" ${rules.includes(d) ? 'checked' : ''}>${d < 0 ? -d + ' days before' : d === 0 ? 'On due date' : d + ' days after'}</label>`).join('')}</div><button class="btn btn-primary mt-3" id="sv">Save</button></div></div>`;
    $$('[data-off]', v).forEach((c) => c.onchange = () => c.closest('label').classList.toggle('active', c.checked));
    $('#sv', v).onclick = async () => { try { const r = await post('/admin/reminder-rules', { offsets: $$('[data-off]:checked', v).map((c) => +c.dataset.off) }); toast(r.message); } catch (e) { fail(e); } };
  }, 'Reminder Rules');

  route('/queue', async (v) => {
    v.innerHTML = head('Notification queue', 'Every WhatsApp / push / email / SMS with its delivery status.', '<button class="btn btn-light" id="run"><i class="bi bi-play"></i> Process now</button>') + '<div id="pz" class="d-flex flex-wrap gap-2 mb-3"></div><div id="ql"></div>';
    const lv = listView({
      el: $('#ql', v), endpoint: '/admin/queue', search: false,
      filters: [{ name: 'channel', placeholder: 'All channels', options: [['whatsapp', 'WhatsApp'], ['push', 'Push'], ['email', 'Email'], ['sms', 'SMS']] }, { name: 'status', placeholder: 'Any status', options: ['pending', 'processing', 'sent', 'delivered', 'read', 'failed', 'cancelled'].map((s) => [s, label(s)]) }],
      columns: [
        { label: '', render: (q) => ['failed', 'pending'].includes(q.status) ? `<input type="checkbox" class="form-check-input" data-q="${q.id}">` : '' },
        { label: 'Created', render: (q) => dateTime(q.created_at) }, { label: 'Academy', render: (q) => esc(q.academy_name || 'Platform') },
        { label: 'Event', render: (q) => `${label(q.event_key)}<div class="small text-muted">${label(q.channel)} → ${esc(q.recipient)}</div>` },
        { label: 'Status', render: (q) => `${badge(q.status)} <span class="small text-muted">×${q.attempts}</span>${q.last_error ? `<div class="small text-muted" style="max-width:280px">${esc(q.last_error)}</div>` : ''}` },
        { label: '', render: (q) => `<button class="btn btn-sm btn-light" data-log="${q.id}">Logs</button>` },
      ], empty: 'Queue is empty.',
      after: (j, el) => {
        const paused = j.meta.paused;
        $('#pz', v).innerHTML = Object.entries(paused).map(([ch, isP]) => `<button class="btn btn-sm ${isP ? 'btn-warning' : 'btn-light'}" data-ch="${ch}" data-act="${isP ? 'resume' : 'pause'}"><i class="bi ${isP ? 'bi-play-fill' : 'bi-pause-fill'}"></i> ${isP ? 'Resume' : 'Pause'} ${label(ch)}</button>`).join('') + `<span class="ms-2 small text-muted align-self-center">${j.meta.counts.map((c) => `${label(c.status)}: ${c.n}`).join(' · ')}</span><span class="ms-auto"><button class="btn btn-sm btn-light" data-bulk="retry">Retry selected</button> <button class="btn btn-sm btn-light" data-bulk="cancel">Cancel selected</button></span>`;
        $$('[data-ch]', v).forEach((b) => b.onclick = async () => { await post('/admin/queue/action', { action: b.dataset.act, channel: b.dataset.ch }).catch(fail); lv.reload(); });
        $$('[data-bulk]', v).forEach((b) => b.onclick = async () => { const ids = $$('[data-q]:checked', v).map((c) => +c.dataset.q); if (!ids.length) return toast('Select messages first', 'warning'); await post('/admin/queue/action', { action: b.dataset.bulk, ids }).then(() => toast('Done')).catch(fail); lv.reload(); });
        $$('[data-log]', el).forEach((b) => b.onclick = async () => { const l = (await get(`/admin/queue/${b.dataset.log}/logs`)).data; modal({ title: 'Delivery log', size: 'lg', body: l.length ? l.map((x) => `<div class="mb-2"><strong>${esc(x.action)}</strong> ${x.http_status ? 'HTTP ' + x.http_status : ''} <span class="small text-muted">${dateTime(x.created_at)}</span><pre class="code">${esc(x.response_body || '')}</pre></div>`).join('') : 'No attempts yet.' }); });
      },
    });
    $('#run', v).onclick = async () => { try { const r = await post('/admin/queue/action', { action: 'run' }); toast('Processed: ' + JSON.stringify(r.data)); lv.reload(); } catch (e) { fail(e); } };
  }, 'Notification Queue');

  // ================================================================ CMS
  route('/cms', async (v, p) => {
    const tab = p.tab || 'home';
    const d = (await get('/admin/cms')).data;
    const s = d.settings;
    const tabs = [['home', 'Home page'], ['about', 'About & contact'], ['seo', 'SEO & footer'], ['faq', 'FAQ'], ['testimonials', 'Testimonials'], ['pages', 'Pages']];
    v.innerHTML = head('Website content', `<a href="${C.BOOT.root}/" target="_blank">View website <i class="bi bi-box-arrow-up-right"></i></a> · Pricing comes from Packages automatically.`) + `<ul class="nav nav-pills mb-3 gap-1 flex-wrap">${tabs.map(([k, l]) => `<li><a class="nav-link ${k === tab ? 'active' : ''}" href="#/cms?tab=${k}">${l}</a></li>`).join('')}</ul><div id="ct"></div>`;
    const el = $('#ct', v);
    const save = async (patch) => { try { const r = await post('/admin/cms/settings', patch); toast(r.message); } catch (e) { fail(e); } };
    const listEditor = (key, items, fields, title) => {
      const draw = () => {
        el.innerHTML = `<div class="card"><div class="card-header">${title}<button class="btn btn-sm btn-light ms-auto" id="addI"><i class="bi bi-plus"></i> Add</button></div><div class="card-body">${items.map((it, i) => `<div class="border rounded p-3 mb-2" data-i="${i}">${form(fields.map((f) => ({ ...f, name: f.name })), it)}<div class="text-end mt-2"><button class="btn btn-sm btn-link" data-up="${i}">↑</button><button class="btn btn-sm btn-link text-danger" data-rm="${i}">Remove</button></div></div>`).join('')}<button class="btn btn-primary" id="svL">Save</button></div></div>`;
        const collect = () => $$('[data-i]', el).map((b) => readForm(b));
        $('#addI', el).onclick = () => { items = collect(); items.push({}); draw(); };
        $$('[data-rm]', el).forEach((b) => b.onclick = () => { items = collect(); items.splice(+b.dataset.rm, 1); draw(); });
        $$('[data-up]', el).forEach((b) => b.onclick = () => { items = collect(); const i = +b.dataset.up; if (i > 0) [items[i - 1], items[i]] = [items[i], items[i - 1]]; draw(); });
        $('#svL', el).onclick = () => save({ [key]: collect() });
      };
      draw();
    };
    if (tab === 'home') {
      el.innerHTML = `<form class="card mb-3" id="hf"><div class="card-header">Hero banner</div><div class="card-body">${form([{ name: 'title', label: 'Headline', required: true }, { name: 'subtitle', label: 'Sub-headline', type: 'textarea', rows: 2 }, { name: 'cta_primary', label: 'Primary button', col: 'col-md-6' }, { name: 'cta_secondary', label: 'Secondary button', col: 'col-md-6' }, { name: 'image', label: 'Hero image URL (optional)', col: 'col-md-8' }, { name: 'upl', type: 'html', col: 'col-md-4', html: '<label class="form-label">Upload image</label><input type="file" class="form-control" accept="image/*" id="himg">' }], s.hero || {})}<button class="btn btn-primary mt-3">Save hero</button></div></form>
        <div class="row g-3"><div class="col-lg-6"><form class="card" id="cf"><div class="card-header">Categories (one per line)</div><div class="card-body"><textarea class="form-control" rows="8" name="cats">${esc((s.categories || []).join('\n'))}</textarea><button class="btn btn-primary mt-3">Save</button></div></form></div>
        <div class="col-lg-6"><div class="card"><div class="card-header">Sections</div><div class="card-body d-grid gap-2"><button class="btn btn-light" data-le="features">Edit feature cards</button><button class="btn btn-light" data-le="steps">Edit "How it works" steps</button><button class="btn btn-light" data-le="stats">Edit highlight numbers</button></div></div></div></div>`;
      $('#hf', el).onsubmit = async (e) => { e.preventDefault(); const h = readForm(e.target); delete h.upl; const f = $('#himg', el).files[0]; if (f) { const fd = new FormData(); fd.append('image', f); try { const r = await post('/admin/cms/settings', fd); h.image = r.data.url; } catch (er) { return fail(er); } } save({ hero: h }); };
      $('#cf', el).onsubmit = (e) => { e.preventDefault(); save({ categories: e.target.cats.value.split('\n').map((x) => x.trim()).filter(Boolean) }); };
      $$('[data-le]', el).forEach((b) => b.onclick = () => {
        const k = b.dataset.le;
        if (k === 'features') listEditor('features', s.features || [], [{ name: 'icon', label: 'Bootstrap icon (e.g. bi-cash-coin)', col: 'col-md-4' }, { name: 'title', label: 'Title', col: 'col-md-8' }, { name: 'text', label: 'Text', type: 'textarea', rows: 2 }], 'Feature cards');
        if (k === 'steps') listEditor('steps', s.steps || [], [{ name: 'title', label: 'Title' }, { name: 'text', label: 'Text', type: 'textarea', rows: 2 }], 'How it works');
        if (k === 'stats') listEditor('stats', s.stats || [], [{ name: 'value', label: 'Value', col: 'col-md-4' }, { name: 'label', label: 'Label', col: 'col-md-8' }], 'Highlight numbers');
      });
    } else if (tab === 'about') {
      el.innerHTML = `<form class="card mb-3" id="af"><div class="card-header">About</div><div class="card-body">${form([{ name: 'title', label: 'Title' }, { name: 'body', label: 'Text', type: 'textarea', rows: 6 }], s.about || {})}<button class="btn btn-primary mt-3">Save</button></div></form>
        <form class="card mb-3" id="kf"><div class="card-header">Contact details</div><div class="card-body">${form([{ name: 'email', label: 'Email', col: 'col-md-4' }, { name: 'phone', label: 'Phone', col: 'col-md-4' }, { name: 'whatsapp', label: 'WhatsApp (digits with country code)', col: 'col-md-4' }, { name: 'address', label: 'Address', type: 'textarea', rows: 2 }, { name: 'map_embed', label: 'Google Maps embed URL (optional)' }], s.contact || {})}<button class="btn btn-primary mt-3">Save</button></div></form>
        <form class="card" id="sf"><div class="card-header">Social media</div><div class="card-body">${form(['facebook', 'instagram', 'youtube', 'linkedin', 'twitter'].map((k) => ({ name: k, label: label(k), col: 'col-md-6' })), s.social || {})}<button class="btn btn-primary mt-3">Save</button></div></form>`;
      $('#af', el).onsubmit = (e) => { e.preventDefault(); save({ about: readForm(e.target) }); };
      $('#kf', el).onsubmit = (e) => { e.preventDefault(); save({ contact: readForm(e.target) }); };
      $('#sf', el).onsubmit = (e) => { e.preventDefault(); save({ social: readForm(e.target) }); };
    } else if (tab === 'seo') {
      el.innerHTML = `<form class="card mb-3" id="sf"><div class="card-header">Search engine (SEO)</div><div class="card-body">${form([{ name: 'title', label: 'Site title' }, { name: 'description', label: 'Meta description', type: 'textarea', rows: 2 }, { name: 'keywords', label: 'Keywords' }], s.seo || {})}<button class="btn btn-primary mt-3">Save</button></div></form>
        <form class="card" id="ff"><div class="card-header">Footer</div><div class="card-body">${form([{ name: 'text', label: 'Footer text', type: 'textarea', rows: 2 }, { name: 'copyright', label: 'Copyright' }], s.footer || {})}<button class="btn btn-primary mt-3">Save</button></div></form>`;
      $('#sf', el).onsubmit = (e) => { e.preventDefault(); save({ seo: readForm(e.target) }); };
      $('#ff', el).onsubmit = (e) => { e.preventDefault(); save({ footer: readForm(e.target) }); };
    } else if (tab === 'faq') {
      listEditor('faq', s.faq || [], [{ name: 'q', label: 'Question' }, { name: 'a', label: 'Answer', type: 'textarea', rows: 2 }], 'Frequently asked questions');
    } else if (tab === 'testimonials') {
      el.innerHTML = `<button class="btn btn-primary mb-2" id="at"><i class="bi bi-plus"></i> Add testimonial</button>` + table([{ label: 'Name', render: (t) => `<strong>${esc(t.name)}</strong><div class="small text-muted">${esc(t.designation || '')}</div>` }, { label: 'Message', render: (t) => `<span class="small">${esc(t.message)}</span>` }, { label: '', render: (t) => badge(t.status) }], d.testimonials, { onRow: (t) => ed(t) });
      const ed = (t = {}) => { const m = formModal({ title: 'Testimonial', values: t, fields: [{ name: 'name', label: 'Name', required: true, col: 'col-md-6' }, { name: 'designation', label: 'Academy / role', col: 'col-md-6' }, { name: 'message', label: 'Message', type: 'textarea', required: true }, { name: 'rating', label: 'Rating (1-5)', type: 'number', value: t.rating || 5, col: 'col-4' }, { name: 'sort_order', label: 'Order', type: 'number', col: 'col-4' }, { name: 'status', label: 'Status', type: 'select', placeholder: false, options: [['active', 'Active'], ['inactive', 'Hidden']], col: 'col-4' }], submit: async (x) => { await post('/admin/testimonials', { ...x, id: t.id }); toast('Saved'); C.dispatch(); } }); if (t.id) { const b = document.createElement('button'); b.className = 'btn btn-outline-danger me-auto'; b.textContent = 'Delete'; b.onclick = async () => { await del('/admin/testimonials/' + t.id); m.close(); C.dispatch(); }; m.footer.prepend(b); } };
      bindRows(el, d.testimonials, ed);
      $('#at', el).onclick = () => ed();
    } else if (tab === 'pages') {
      el.innerHTML = `<button class="btn btn-primary mb-2" id="ap"><i class="bi bi-plus"></i> New page</button>` + table([{ label: 'Page', render: (pg) => `<strong>${esc(pg.title)}</strong><div class="small text-muted">/page.php?p=${esc(pg.slug)}</div>` }, { label: 'Status', render: (pg) => badge(pg.status === 'published' ? 'active' : 'inactive', label(pg.status)) }, { label: 'Updated', render: (pg) => dateTime(pg.updated_at) }], d.pages, { onRow: async (pg) => ed((await get('/admin/cms/pages/' + pg.id)).data) });
      const ed = (pg = {}) => formModal({ title: pg.id ? 'Edit page' : 'New page', size: 'xl', values: pg, fields: [{ name: 'title', label: 'Title', required: true, col: 'col-md-6' }, { name: 'slug', label: 'URL slug', required: true, col: 'col-md-6' }, { name: 'content', label: 'Content (HTML: p, h2-h4, ul, ol, li, a, strong, em, table, img)', type: 'textarea', rows: 16 }, { name: 'meta_title', label: 'Meta title', col: 'col-md-6' }, { name: 'meta_description', label: 'Meta description', col: 'col-md-6' }, { name: 'status', label: 'Status', type: 'select', placeholder: false, options: [['published', 'Published'], ['draft', 'Draft']], col: 'col-md-4' }], submit: async (x) => { const r = await post('/admin/cms/pages', { ...x, id: pg.id }); toast(r.message); C.dispatch(); } });
      bindRows(el, d.pages, async (pg) => ed((await get('/admin/cms/pages/' + pg.id)).data));
      $('#ap', el).onclick = () => ed();
    }
  }, 'Website CMS');

  route('/enquiries', async (v) => {
    v.innerHTML = head('Enquiries') + '<div id="l"></div>';
    const lv = listView({ el: $('#l', v), endpoint: '/admin/enquiries', search: false, filters: [{ name: 'status', placeholder: 'All', options: ['new', 'contacted', 'converted', 'closed'].map((s) => [s, label(s)]) }], columns: [{ label: 'Date', render: (e) => dateTime(e.created_at) }, { label: 'Name', render: (e) => `<strong>${esc(e.name)}</strong><div class="small text-muted">${esc(e.academy_name || '')} ${e.category ? '· ' + esc(e.category) : ''}</div>` }, { label: 'Contact', render: (e) => `${e.mobile ? C.wa('+91', e.mobile) : ''}<div class="small">${esc(e.email || '')}</div>` }, { label: 'Message', render: (e) => `<span class="small">${esc(e.message || '')}</span>` }, { label: 'Status', render: (e) => `<select class="form-select form-select-sm" data-enq="${e.id}">${['new', 'contacted', 'converted', 'closed'].map((s) => `<option ${s === e.status ? 'selected' : ''}>${s}</option>`).join('')}</select>` }], empty: 'No enquiries yet.', after: (_, el) => $$('[data-enq]', el).forEach((s) => s.onchange = () => put('/admin/enquiries/' + s.dataset.enq, { status: s.value }).then(() => toast('Updated')).catch(fail)) });
  }, 'Enquiries');

  route('/support', async (v, p) => {
    if (p.id) return C.supportView(v, p);
    v.innerHTML = head('Support tickets', 'Requests from academy owners') + '<div id="l"></div>';
    listView({ el: $('#l', v), endpoint: '/admin/support', search: false, filters: [{ name: 'status', placeholder: 'All', options: ['open', 'in_progress', 'resolved', 'closed'].map((s) => [s, label(s)]) }], columns: [{ label: 'Subject', render: (t) => `<strong>${esc(t.subject)}</strong><div class="small text-muted">${esc(t.academy_name || '')} · ${esc(t.user_name || '')}</div>` }, { label: 'Priority', render: (t) => label(t.priority) }, { label: 'Status', render: (t) => badge(t.status) }, { label: 'Updated', render: (t) => dateTime(t.updated_at) }], onRow: (t) => go('#/support/' + t.id), empty: 'No tickets.' });
  }, 'Support');
  route('/support/:id', C.supportView, 'Support ticket');

  route('/reports', async (v, p) => {
    const key = p.r || 'subscriptions';
    const reps = [['subscriptions', 'Subscription report'], ['subscription_payments', 'Subscription payments'], ['academies', 'Academies'], ['notifications', 'Notification usage']];
    v.innerHTML = head('Reports') + `<div class="d-flex flex-wrap gap-2 mb-3">${reps.map(([k, l]) => `<a class="btn ${k === key ? 'btn-primary' : 'btn-light'}" href="#/reports?r=${k}">${l}</a>`).join('')}<div class="btn-group ms-auto">${['pdf', 'xlsx', 'csv'].map((f) => `<button class="btn btn-light" data-x="${f}">${f.toUpperCase()}</button>`).join('')}</div></div><div id="rr"></div>`;
    const r = (await get('/reports/' + key)).data;
    $('#rr', v).innerHTML = table(r.columns.map((c, i) => ({ label: c, render: (row) => esc(row[i] ?? '') })), r.rows, { empty: 'No data.' });
    $$('[data-x]', v).forEach((b) => b.onclick = () => C.download(`/reports/${key}?format=${b.dataset.x}`, `${key}.${b.dataset.x}`));
  }, 'Reports');

  route('/audit', async (v) => {
    v.innerHTML = head('Audit log', 'Every important change across the platform') + '<div id="l"></div>';
    listView({ el: $('#l', v), endpoint: '/admin/audit-logs', search: false, extraFilters: '<input class="form-control" name="action" placeholder="Filter action (e.g. payment)">', filters: [], columns: [{ label: 'When', render: (l) => dateTime(l.created_at) }, { label: 'Academy', render: (l) => esc(l.academy_name || '–') }, { label: 'User', render: (l) => esc(l.user_name || 'System') }, { label: 'Action', render: (l) => `<code>${esc(l.action)}</code> <span class="small text-muted">${esc(l.entity || '')} ${esc(l.entity_id || '')}</span>` }, { label: 'Changes', render: (l) => `<div class="small text-muted" style="max-width:380px;word-break:break-all">${esc(l.new_values || l.old_values || '')}</div>` }, { label: 'IP', render: (l) => `<span class="small">${esc(l.ip || '')}</span>` }], empty: 'No entries.' });
    const inp = $('[name=action]', v);
    inp.addEventListener('change', () => {});
  }, 'Audit Log');

  route('/settings', async (v) => {
    const s = (await get('/admin/settings')).data;
    const pk = await packages();
    v.innerHTML = head('Global settings') + `<form class="card" id="f"><div class="card-body">${form([
      { name: 'platform_name', label: 'Platform name', col: 'col-md-6' }, { name: 'support_email', label: 'Support email', col: 'col-md-3' }, { name: 'support_phone', label: 'Support phone', col: 'col-md-3' },
      { name: 'self_registration_enabled', label: 'Allow owners to register from the website', type: 'select', placeholder: false, options: [['1', 'Yes'], ['0', 'No']], col: 'col-md-6' },
      { name: 'default_trial_package_id', label: 'Default trial package', type: 'select', options: pk.packages.map((p) => [p.id, p.name]), col: 'col-md-6' },
      { name: 'grace_days', label: 'Grace days after expiry', type: 'number', col: 'col-md-3' }, { name: 'invoice_advance_days', label: 'Create dues N days early', type: 'number', col: 'col-md-3' },
      { name: 'queue_batch_size', label: 'Queue batch size / run', type: 'number', col: 'col-md-3' }, { name: 'document_retention_days', label: 'Document retention (days)', type: 'number', col: 'col-md-3' },
      { name: 'default_country_code', label: 'Default country code', col: 'col-md-3' },
    ], s)}<button class="btn btn-primary mt-3">Save</button></div></form>`;
    $('#f', v).onsubmit = async (e) => { e.preventDefault(); try { const r = await post('/admin/settings', readForm(e.target)); toast(r.message); } catch (er) { fail(er); } };
  }, 'Global Settings');

  route('/health', async (v) => {
    const h = (await get('/admin/health')).data;
    const ok = (b) => b ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle-fill text-danger"></i>';
    v.innerHTML = head('System health', '', '<button class="btn btn-primary" id="bk"><i class="bi bi-database-down"></i> Create backup</button>') + `<div class="row g-3">
      <div class="col-lg-4"><div class="card h-100"><div class="card-header">Server</div><div class="card-body"><dl class="kv">${[['PHP', h.php_version], ['Database', esc(h.db_version)], ['HTTPS', ok(h.https)], ['Debug off', ok(!h.debug)], ['App key', ok(h.app_key_set)], ['Storage writable', ok(h.storage_writable)], ['Disk free', h.disk_free_mb !== null ? h.disk_free_mb.toLocaleString() + ' MB' : '–'], ['Migrations', h.migrations.length]].map(([k, x]) => `<dt>${k}</dt><dd>${x}</dd>`).join('')}</dl><div class="mt-2">${h.extensions.map((e) => `<span class="badge ${e.loaded ? 'b-success' : 'b-danger'} me-1">${e.name}</span>`).join('')}</div></div></div></div>
      <div class="col-lg-4"><div class="card h-100"><div class="card-header">Cron jobs</div><div class="card-body">${h.cron.length ? h.cron.map((c) => `<div class="d-flex small mb-1"><span class="fw-bold">${esc(c.task)}</span><span class="ms-auto">${dateTime(c.last_run)}</span> ${badge(c.last_status === 'ok' ? 'success' : c.last_status)}</div>`).join('') : '<div class="alert alert-danger small">Cron has never run. Add the cron job from the deployment guide.</div>'}</div></div></div>
      <div class="col-lg-4"><div class="card h-100"><div class="card-header">Backups</div><div class="card-body">${h.backups.length ? h.backups.map((b) => `<div class="d-flex small mb-1"><a href="javascript:" data-b="${esc(b.name)}">${esc(b.name)}</a><span class="ms-auto">${b.size_kb} KB</span></div>`).join('') : '<div class="small text-muted">No backups yet. The cron job creates one daily.</div>'}</div></div></div>
      <div class="col-lg-6"><div class="card"><div class="card-header">Recent cron runs</div><div class="card-body py-1">${h.cron_recent.map((c) => `<div class="list-tile small"><span class="fw-bold" style="width:110px">${esc(c.task)}</span><span class="flex-grow-1 text-muted text-truncate">${esc(c.summary || '')}</span><span>${dateTime(c.started_at)}</span></div>`).join('')}</div></div></div>
      <div class="col-lg-6"><div class="card"><div class="card-header">PHP error log (last lines)</div><div class="card-body"><pre class="code" style="max-height:300px;overflow:auto">${esc(h.error_log_tail.join('') || 'No errors 🎉')}</pre></div></div></div></div>`;
    $('#bk', v).onclick = async () => { try { const r = await post('/admin/backups'); toast(r.message); C.dispatch(); } catch (e) { fail(e); } };
    $$('[data-b]', v).forEach((a) => a.onclick = () => C.download('/admin/backups/' + encodeURIComponent(a.dataset.b), a.dataset.b));
  }, 'System Health');

  C.start();
})();
