/* ClassNex parent / student portal */
(() => {
  'use strict';
  const C = window.CNX;
  const { $, $$, esc, get, post, money, date, dateTime, time, badge, toast, fail, table, route, go, head, label, WEEK } = C;
  const ME = C.BOOT.me;
  const kids = ME.children || [];
  const has = (f) => (ME.features || []).includes(f);
  let sid = (() => { try { return +localStorage.getItem('cnx_child') || 0; } catch { return 0; } })();
  if (!kids.find((k) => k.id === sid)) sid = kids[0]?.id || 0;
  const choose = (id) => { sid = id; try { localStorage.setItem('cnx_child', id); } catch {} C.dispatch(); };

  function switcher() {
    if (kids.length < 2) return '';
    return `<div class="child-switch">${kids.map((k) => `<button class="${k.id === sid ? 'active' : ''}" data-kid="${k.id}">${k.photo_url ? `<img class="avatar" src="${esc(k.photo_url)}">` : `<span class="avatar">${esc(k.name[0])}</span>`}${esc(k.name.split(' ')[0])}</button>`).join('')}</div>`;
  }
  function bindSwitch(v) { $$('[data-kid]', v).forEach((b) => (b.onclick = () => choose(+b.dataset.kid))); }
  const noKid = (v) => { v.innerHTML = '<div class="card"><div class="empty"><i class="bi bi-person-x"></i>No student is linked to your account yet. Please contact the academy.</div></div>'; };

  route('/', async (v) => {
    if (!sid) return noKid(v);
    const d = (await get(`/portal/students/${sid}/dashboard`)).data;
    const t = d.today_attendance;
    v.innerHTML = switcher() + `
      <div class="card mb-3"><div class="card-body d-flex align-items-center gap-3">${d.student.photo_url ? `<img class="avatar avatar-lg" src="${esc(d.student.photo_url)}" style="object-fit:cover">` : `<span class="avatar avatar-lg">${esc(d.student.name[0])}</span>`}
        <div><div class="h5 fw-bold mb-0">${esc(d.student.name)}</div><div class="text-muted small">ID ${esc(d.student.student_code)}</div><div class="small">${d.enrollments.map((e) => esc(e.activity_name + (e.batch_name ? ' · ' + e.batch_name : ''))).join(', ')}</div></div></div></div>
      <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3"><a class="stat" href="#/attendance"><div class="ic ${t ? (t.status === 'absent' ? 'ic-red' : 'ic-green') : 'ic-gray'}"><i class="bi bi-calendar-check"></i></div><div><div class="val fs-6">${t ? label(t.status) : 'Not marked'}</div><div class="lbl">Today</div></div></a></div>
        <div class="col-6 col-lg-3"><a class="stat" href="#/attendance"><div class="ic ic-blue"><i class="bi bi-graph-up"></i></div><div><div class="val">${d.month_attendance.percentage ?? '–'}${d.month_attendance.percentage !== null ? '%' : ''}</div><div class="lbl">This month</div></div></a></div>
        ${d.fees ? `<div class="col-6 col-lg-3"><a class="stat" href="#/fees"><div class="ic ${d.fees.outstanding_paise ? 'ic-red' : 'ic-green'}"><i class="bi bi-wallet2"></i></div><div><div class="val">${money(d.fees.outstanding_paise)}</div><div class="lbl">Pending fees</div></div></a></div>
        <div class="col-6 col-lg-3"><a class="stat" href="#/fees"><div class="ic ic-purple"><i class="bi bi-receipt"></i></div><div><div class="val fs-6">${d.last_payment ? money(d.last_payment.amount_paise) : '–'}</div><div class="lbl">Last payment ${d.last_payment ? date(d.last_payment.paid_on) : ''}</div></div></a></div>` : ''}
      </div>
      <div class="row g-3">
        <div class="col-lg-6"><div class="card h-100"><div class="card-header"><i class="bi bi-clock text-primary"></i> Next class</div><div class="card-body">${d.next_class ? `<div class="fs-5 fw-bold">${esc(d.next_class.activity_name)} · ${esc(d.next_class.batch_name)}</div><div class="text-muted">${date(d.next_class.date)} at ${time(d.next_class.start_time)} ${d.next_class.location ? '· ' + esc(d.next_class.location) : ''}</div>` : '<div class="text-muted">No upcoming class in the timetable.</div>'}</div></div></div>
        ${d.fees ? `<div class="col-lg-6"><div class="card h-100"><div class="card-header"><i class="bi bi-cash-coin text-warning"></i> Fees</div><div class="card-body">${d.next_due ? `<div class="fw-bold">${esc(d.next_due.title)}</div><div class="text-muted small">Due ${date(d.next_due.due_date)}</div><div class="fs-4 fw-bold text-danger money">${money(d.next_due.balance_paise)}</div>${d.online_payment ? '<a class="btn btn-primary mt-2" href="#/fees"><i class="bi bi-credit-card"></i> Pay online</a>' : '<div class="small text-muted mt-2">Please pay at the academy.</div>'}` : '<div class="text-success fw-bold"><i class="bi bi-check-circle"></i> All fees paid. Thank you!</div>'}
          ${d.last_payment?.receipt_id ? `<button class="btn btn-sm btn-light mt-3" data-rc="${d.last_payment.receipt_id}" data-no="${esc(d.last_payment.receipt_no)}"><i class="bi bi-download"></i> Last receipt ${esc(d.last_payment.receipt_no)}</button>` : ''}</div></div></div>` : ''}
        <div class="col-12"><div class="card"><div class="card-header"><i class="bi bi-bell text-primary"></i> Recent updates <a class="ms-auto small" href="#/notifications">All</a></div><div class="card-body py-1">${d.notifications.length ? d.notifications.map((n) => `<div class="list-tile"><div class="flex-grow-1"><div class="fw-bold small">${esc(n.title)}</div><div class="small text-muted">${esc(n.body)}</div></div><div class="small text-muted">${dateTime(n.created_at)}</div></div>`).join('') : '<div class="text-muted py-3">No updates yet.</div>'}</div></div></div>
      </div>`;
    bindSwitch(v);
    $$('[data-rc]', v).forEach((b) => (b.onclick = () => C.download(`/receipts/${b.dataset.rc}/pdf?download=1`, b.dataset.no.replace(/\//g, '-') + '.pdf')));
  }, 'Home');

  route('/attendance', async (v) => {
    if (!sid) return noKid(v);
    let m = C.month();
    const draw = async () => {
      const r = (await get(`/students/${sid}/attendance?month=${m}`)).data;
      const first = new Date(m + '-01');
      const days = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();
      const offset = (first.getDay() + 6) % 7;
      const map = Object.fromEntries(r.days.map((d) => [+d.session_date.slice(8), d.status]));
      v.innerHTML = switcher() + head('Attendance') + `<div class="card"><div class="card-body"><div class="d-flex align-items-center gap-2 mb-3"><input type="month" class="form-control w-auto" value="${m}" id="mp" max="${C.month()}"><div class="ms-auto text-end">${r.percentage !== null ? `<div class="fs-3 fw-bold">${r.percentage}%</div><div class="small text-muted">attendance</div>` : ''}</div></div>
        <div class="d-flex flex-wrap gap-2 mb-3">${Object.entries(r.counts).filter(([, n]) => n).map(([k, n]) => badge(k, `${label(k)}: ${n}`)).join('') || '<span class="text-muted small">No classes marked this month</span>'}</div>
        <div class="cal mx-auto" style="max-width:460px">${['M', 'T', 'W', 'T', 'F', 'S', 'S'].map((x) => `<div class="h">${x}</div>`).join('')}${'<div class="h"></div>'.repeat(offset)}${Array.from({ length: days }, (_, i) => `<div class="${map[i + 1] || ''}">${i + 1}</div>`).join('')}</div>
        <div class="d-flex justify-content-center flex-wrap gap-3 mt-3 small">${['present', 'absent', 'late', 'leave'].map((k) => `<span><span class="cal d-inline-grid" style="grid-template-columns:1fr"><span class="${k} d-inline-block rounded" style="width:14px;height:14px"></span></span> ${label(k)}</span>`).join('')}</div></div></div>`;
      bindSwitch(v);
      $('#mp', v).onchange = (e) => { m = e.target.value; draw(); };
    };
    await draw();
  }, 'Attendance');

  route('/fees', async (v) => {
    if (!sid) return noKid(v);
    const f = (await get(`/portal/students/${sid}/fees`)).data;
    v.innerHTML = switcher() + head('Fees', '', f.online_payment && f.pending.length ? '<button class="btn btn-primary btn-lg" id="payAll"><i class="bi bi-credit-card"></i> Pay ' + money(f.balance.outstanding_paise) + ' online</button>' : '') + `
      <div class="row g-3 mb-3"><div class="col-4"><div class="stat"><div><div class="lbl">Pending</div><div class="val text-danger">${money(f.balance.outstanding_paise)}</div></div></div></div><div class="col-4"><div class="stat"><div><div class="lbl">Overdue</div><div class="val">${money(f.balance.overdue_paise)}</div></div></div></div><div class="col-4"><div class="stat"><div><div class="lbl">Advance</div><div class="val text-success">${money(f.balance.advance_paise)}</div></div></div></div></div>
      <h6 class="fw-bold">Pending</h6>${table([
        { label: 'Fee', render: (i) => `<div class="fw-bold">${esc(i.title)}</div><div class="small text-muted">${esc(i.invoice_no)}</div>` },
        { label: 'Due', render: (i) => date(i.due_date) + (+i.overdue ? '<div class="small text-danger">Overdue</div>' : '') },
        { label: 'Amount', cls: 'text-end', render: (i) => `<span class="money fw-bold">${money(i.balance_paise)}</span>${+i.paid_paise ? `<div class="small text-muted">paid ${money(i.paid_paise)}</div>` : ''}` },
        { label: '', cls: 'text-end', render: (i) => f.online_payment ? `<button class="btn btn-sm btn-outline-primary" data-pay="${i.id}">Pay</button>` : '' },
      ], f.pending, { empty: 'No pending fees. 🎉', emptyIcon: 'bi-emoji-smile' })}
      <h6 class="fw-bold mt-4">Payments & receipts</h6>${table([
        { label: 'Date', render: (p) => date(p.paid_on) }, { label: 'Receipt', render: (p) => esc(p.receipt_no || '–') },
        { label: 'Method', render: (p) => label(p.method) }, { label: 'Amount', cls: 'text-end', render: (p) => `<span class="money fw-bold">${money(p.amount_paise)}</span>` },
        { label: '', render: (p) => p.status !== 'success' ? badge(p.status) : '' },
        { label: '', cls: 'text-end', render: (p) => p.receipt_id ? `<button class="btn btn-sm btn-light" data-rc="${p.receipt_id}" data-no="${esc(p.receipt_no)}"><i class="bi bi-download"></i> Receipt</button>` : '' },
      ], f.payments, { empty: 'No payments yet.' })}
      ${f.paid.length ? `<h6 class="fw-bold mt-4">Paid</h6>${table([{ label: 'Fee', render: (i) => esc(i.title) }, { label: 'Amount', cls: 'text-end', render: (i) => money(i.total_paise) }, { label: '', render: (i) => badge(i.status) }], f.paid)}` : ''}`;
    bindSwitch(v);
    const pay = async (ids) => { try { const o = (await post(`/portal/students/${sid}/pay`, { invoice_ids: ids })).data; C.razorpay(o, () => C.dispatch()); } catch (e) { fail(e); } };
    $('#payAll', v) && ($('#payAll', v).onclick = () => pay([]));
    $$('[data-pay]', v).forEach((b) => (b.onclick = () => pay([+b.dataset.pay])));
    $$('[data-rc]', v).forEach((b) => (b.onclick = () => C.download(`/receipts/${b.dataset.rc}/pdf?download=1`, b.dataset.no.replace(/\//g, '-') + '.pdf')));
  }, 'Fees');

  route('/timetable', async (v) => {
    if (!sid) return noKid(v);
    const rows = (await get(`/portal/students/${sid}/timetable`)).data;
    v.innerHTML = switcher() + head('Timetable') + table([{ label: 'Day', render: (r) => `<strong>${WEEK[r.weekday]}</strong>` }, { label: 'Time', render: (r) => `${time(r.start_time)} – ${time(r.end_time)}` }, { label: 'Class', render: (r) => `${esc(r.activity_name)} · ${esc(r.batch_name)}` }, { label: 'Teacher', render: (r) => esc(r.teacher_name || '–') }, { label: 'Where', render: (r) => esc(r.location || '–') }], rows, { empty: 'Timetable not published yet.' });
    bindSwitch(v);
  }, 'Timetable');

  route('/announcements', async (v) => {
    const rows = (await get('/announcements')).data;
    v.innerHTML = head('Announcements') + (rows.length ? rows.map((a) => `<div class="card mb-2"><div class="card-body"><div class="d-flex"><div class="fw-bold">${esc(a.title)}</div><div class="ms-auto small text-muted">${dateTime(a.publish_at)}</div></div><div class="mt-1" style="white-space:pre-wrap">${esc(a.body)}</div></div></div>`).join('') : '<div class="card"><div class="empty"><i class="bi bi-megaphone"></i>No announcements.</div></div>');
  }, 'Announcements');

  route('/holidays', async (v) => {
    const rows = (await get('/holidays')).data;
    v.innerHTML = head('Holidays') + table([{ label: 'Date', render: (h) => `<strong>${date(h.holiday_date)}</strong>` }, { label: 'Occasion', render: (h) => esc(h.title) }, { label: 'For', render: (h) => esc(h.batch_name || 'Everyone') }], rows, { empty: 'No upcoming holidays.' });
  }, 'Holidays');

  route('/support', C.supportView, 'Help');
  route('/support/:id', C.supportView, 'Help');
  C.start();
})();
