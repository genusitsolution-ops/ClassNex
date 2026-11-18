/* ClassNex academy panel (Class Admin + Teacher). OWNER = the Class Admin role (stored as 'owner'). Every screen talks to the same REST API as the Android app. */
(() => {
  'use strict';
  const C = window.CNX;
  const { $, $$, esc, get, post, put, del, qs, money, rupees, date, dateTime, time, badge, person, wa, toast, fail, modal, confirm, form, readForm, showErrors, formModal, table, bindRows, listView, route, go, head, label, WEEK } = C;
  const ME = C.BOOT.me;
  const OWNER = ME.role === 'owner';
  const can = (p) => OWNER || (ME.permissions || []).includes(p);
  const has = (f) => (ME.features || []).includes(f);
  const readOnly = ME.subscription && !ME.subscription.writable;

  // cached look-ups for selects
  let LK = null;
  async function lookups(force = false) {
    if (LK && !force) return LK;
    const [a, b, br] = await Promise.all([get('/activities?status=active'), get('/batches?status=active'), get('/branches?status=active')]);
    LK = { activities: a.data, batches: b.data, branches: br.data };
    return LK;
  }
  const branchOpts = () => (LK?.branches || []).map((b) => [b.id, b.name]);
  const actOpts = () => LK.activities.map((a) => [a.id, a.name]);
  const batchOpts = (actId) => LK.batches.filter((b) => !actId || String(b.activity_id) === String(actId)).map((b) => [b.id, `${b.name}${b.start_time ? ' · ' + time(b.start_time) : ''}${actId ? '' : ' (' + b.activity_name + ')'}`]);
  function linkSelects(root, actSel = '[name=activity_id]', batchSel = '[name=batch_id]') {
    const a = $(actSel, root), b = $(batchSel, root);
    if (!a || !b) return;
    const fill = () => {
      const keep = b.value;
      const opts = batchOpts(a.value);
      b.innerHTML = `<option value="">${opts.length ? 'Select batch…' : (a.value ? 'No batches – optional' : 'Select activity first')}</option>` + opts.map(([v, l]) => `<option value="${v}" ${String(v) === keep ? 'selected' : ''}>${esc(l)}</option>`).join('');
      if (opts.length === 1) b.value = opts[0][0];
    };
    a.addEventListener('change', fill);
    fill();
  }

  // =================================================================== DASHBOARD
  route('/', async (v) => {
    const d = (await get('/dashboard')).data;
    if (d.role === 'teacher') return teacherDash(v, d);
    const setupDone = Object.values(d.setup).every(Boolean);
    const fees = has('fees');
    v.innerHTML = `
      ${head('Welcome, ' + esc(ME.name.split(' ')[0]) + ' 👋', esc(ME.academy?.name || '') + ' · ' + date(C.today()))}
      ${setupDone ? '' : `<div class="card mb-3 border-primary"><div class="card-body"><div class="fw-bold mb-2"><i class="bi bi-rocket-takeoff text-primary"></i> Get started in 3 steps</div><div class="d-flex flex-wrap gap-3">
        ${[['activities', '#/activities', '1. Add an activity (e.g. Cricket)'], ['batches', '#/batches', '2. Create a batch & timing'], ['students', '#/students/new', '3. Register your first student']].map(([k, h, t]) => `<a href="${h}" class="btn ${d.setup[k] ? 'btn-light text-success' : 'btn-outline-primary'}"><i class="bi ${d.setup[k] ? 'bi-check-circle-fill' : 'bi-circle'}"></i> ${t}</a>`).join('')}
        ${d.setup.logo ? '' : '<a href="#/settings" class="btn btn-light">Upload your logo (for receipts)</a>'}</div></div></div>`}
      <div class="quick-actions mb-3">
        <a class="qa" href="#/students/new"><i class="bi bi-person-plus ic-blue"></i>Add Student</a>
        ${has('attendance') ? '<a class="qa" href="#/attendance"><i class="bi bi-calendar-check ic-green"></i>Mark Attendance</a>' : ''}
        ${fees ? '<a class="qa" href="#/collect"><i class="bi bi-cash-coin ic-orange"></i>Collect Fee</a><a class="qa" href="#/fees"><i class="bi bi-hourglass-split ic-red"></i>Pending Fees</a>' : ''}
        ${has('manual_reminders') ? '<a class="qa" href="#/messages"><i class="bi bi-whatsapp ic-green"></i>Send Reminder</a>' : ''}
        <a class="qa" href="#/reports"><i class="bi bi-bar-chart ic-purple"></i>View Reports</a>
      </div>
      <div class="row g-3 mb-3">
        ${stat('#/students', 'bi-people', 'ic-blue', d.active_students, 'Active students', d.student_quota?.limit ? `${d.student_quota.active} of ${d.student_quota.limit} in your package · all branches` : `${d.new_admissions} new this month`)}
        ${stat('#/attendance', 'bi-check2-circle', 'ic-green', `${d.today_attendance.present}<small class="text-muted fs-6"> / ${d.today_attendance.marked}</small>`, 'Present today', d.batches_not_marked ? `<span class="text-danger">${d.batches_not_marked} batch(es) not marked</span>` : `${d.today_attendance.absent} absent`)}
        ${fees ? stat('#/payments', 'bi-wallet2', 'ic-purple', money(d.month_collection_paise), 'Collected this month', `Today ${money(d.today_collection_paise)}`) : stat('#/teachers', 'bi-person-badge', 'ic-purple', d.total_teachers, 'Teachers', `${d.total_batches} batches`)}
        ${fees ? stat('#/fees?status=overdue', 'bi-exclamation-triangle', 'ic-red', money(d.overdue_fees_paise), 'Overdue fees', `${d.overdue_students} student(s) · pending ${money(d.pending_fees_paise)}`) : stat('#/batches', 'bi-collection', 'ic-orange', d.total_batches, 'Active batches', `${d.total_activities} activities`)}
      </div>
      ${d.branches?.length ? `<div class="card mb-3"><div class="card-header d-flex"><span><i class="bi bi-geo-alt text-primary"></i> Branches this month</span><a class="ms-auto small" href="#/branches">Manage</a></div><div class="card-body p-0">${branchTable(d.branches)}</div></div>` : ''}
      <div class="row g-3">
        ${fees ? `<div class="col-lg-7"><div class="card h-100"><div class="card-header"><i class="bi bi-graph-up text-primary"></i> Fee collection (last 6 months)</div><div class="card-body">${bars(d.collection_trend)}</div></div></div>
        <div class="col-lg-5"><div class="card h-100"><div class="card-header"><i class="bi bi-calendar-event text-warning"></i> Due in the next 7 days</div><div class="card-body py-1">${d.upcoming_dues.length ? d.upcoming_dues.map((u) => `<div class="list-tile"><div class="flex-grow-1"><div class="fw-bold">${esc(u.student_name)}</div><div class="small text-muted">${esc(u.title)} · ${date(u.due_date)}</div></div><div class="money fw-bold">${money(u.balance_paise)}</div><a class="btn btn-sm btn-light" href="#/collect?student=${u.student_id}">Collect</a></div>`).join('') : '<div class="empty py-4"><i class="bi bi-emoji-smile"></i>No dues in the next 7 days</div>'}</div></div></div>
        <div class="col-lg-7"><div class="card h-100"><div class="card-header"><i class="bi bi-receipt text-success"></i> Recent payments <a class="ms-auto small" href="#/payments">View all</a></div><div class="card-body py-1">${d.recent_payments.length ? d.recent_payments.map((p) => `<div class="list-tile"><div class="flex-grow-1"><div class="fw-bold">${esc(p.student_name)}</div><div class="small text-muted">${esc(p.receipt_no || '')} · ${label(p.method)} · ${date(p.paid_on)}</div></div><div class="money fw-bold ${p.status === 'reversed' ? 'text-decoration-line-through text-muted' : 'text-success'}">${money(p.amount_paise)}</div></div>`).join('') : '<div class="empty py-4">No payments yet</div>'}</div></div></div>` : ''}
        <div class="col-lg-5"><div class="card h-100"><div class="card-header"><i class="bi bi-bell text-primary"></i> Recent notifications <a class="ms-auto small" href="#/message-log">Log</a></div><div class="card-body py-1">${d.recent_notifications.length ? d.recent_notifications.map((n) => `<div class="list-tile"><i class="bi ${{ whatsapp: 'bi-whatsapp text-success', push: 'bi-phone text-primary', email: 'bi-envelope', sms: 'bi-chat-dots' }[n.channel] || 'bi-bell'}"></i><div class="flex-grow-1"><div class="small fw-bold">${label(n.event_key)}</div><div class="small text-muted">${esc(n.recipient_name || '')} · ${dateTime(n.created_at)}</div></div>${badge(n.status)}</div>`).join('') : '<div class="empty py-4">Nothing sent yet</div>'}</div></div></div>
        ${d.pending_online_admissions ? `<div class="col-12"><a href="#/admissions" class="alert alert-info d-flex align-items-center gap-2 mb-0 text-decoration-none"><i class="bi bi-inbox fs-4"></i><div><strong>${d.pending_online_admissions} online admission(s)</strong> waiting for your approval.</div></a></div>` : ''}
      </div>`;
  }, 'Dashboard');
  function stat(href, icon, cls, val, lbl, sub) {
    return `<div class="col-6 col-xl-3"><a class="stat" href="${href}"><div class="ic ${cls}"><i class="bi ${icon}"></i></div><div class="min-w-0"><div class="val">${val}</div><div class="lbl">${lbl}</div>${sub ? `<div class="small mt-1">${sub}</div>` : ''}</div></a></div>`;
  }
  function bars(rows) {
    if (!rows?.length) return '<div class="empty py-4"><i class="bi bi-bar-chart"></i>Collections will appear here</div>';
    const max = Math.max(...rows.map((r) => +r.total), 1);
    return `<div class="chart-bars">${rows.map((r) => `<div class="bar"><div class="small fw-bold text-dark">${money(r.total)}</div><span style="height:${Math.max(3, (r.total / max) * 100)}%"></span>${new Date(r.month + '-01').toLocaleDateString('en-IN', { month: 'short' })}</div>`).join('')}</div>`;
  }
  function teacherDash(v, d) {
    v.innerHTML = head('Hello, ' + esc(ME.name.split(' ')[0]) + ' 👋', date(C.today())) + `
      <div class="row g-3 mb-3">
        ${stat('#/attendance', 'bi-calendar-check', 'ic-green', d.todays_classes.length, "Today's classes", d.attendance_pending ? `<span class="text-danger">${d.attendance_pending} not marked</span>` : 'All marked')}
        ${stat('#/batches', 'bi-collection', 'ic-blue', d.assigned_batches, 'Assigned batches')}
        ${stat('#/students', 'bi-people', 'ic-purple', d.total_students, 'Students')}
        ${stat('#/announcements', 'bi-megaphone', 'ic-orange', d.announcements.length, 'Announcements')}
      </div>
      <div class="row g-3"><div class="col-lg-7"><div class="card"><div class="card-header"><i class="bi bi-clock text-primary"></i> Today's classes</div><div class="card-body py-1">
        ${d.todays_classes.length ? d.todays_classes.map((c) => `<div class="list-tile"><div class="flex-grow-1"><div class="fw-bold">${esc(c.name)} <span class="text-muted fw-normal">· ${esc(c.activity_name)}</span></div><div class="small text-muted">${time(c.start_time)} – ${time(c.end_time)} ${c.location ? '· ' + esc(c.location) : ''}</div></div>${+c.marked ? badge('success', 'Marked') : `<a class="btn btn-sm btn-primary" href="#/attendance/${c.id}">Mark attendance</a>`}</div>`).join('') : '<div class="empty py-4"><i class="bi bi-cup-hot"></i>No classes scheduled today</div>'}
      </div></div></div>
      <div class="col-lg-5"><div class="card"><div class="card-header"><i class="bi bi-megaphone text-warning"></i> Announcements</div><div class="card-body py-1">${d.announcements.length ? d.announcements.map((a) => `<div class="list-tile align-items-start"><div><div class="fw-bold">${esc(a.title)}</div><div class="small text-muted">${esc(a.body)}</div></div></div>`).join('') : '<div class="empty py-4">No announcements</div>'}</div></div></div></div>`;
  }

  // ===================================================================== STUDENTS
  route('/students', async (v, p) => {
    await lookups();
    v.innerHTML = head('Students', 'Search by name, Student ID or phone', (OWNER && LK.branches.length && can('students.manage') ? '<button class="btn btn-light" id="bm"><i class="bi bi-arrow-left-right"></i> Move to branch</button>' : '') + (can('students.manage') ? '<a class="btn btn-primary" href="#/students/new"><i class="bi bi-person-plus"></i> Add Student</a>' : '')) + '<div id="sl"></div>';
    $('#bm', v) && ($('#bm', v).onclick = () => bulkMove());
    listView({
      el: $('#sl', v), endpoint: '/students', searchPlaceholder: 'Search students…', params: { q: p.q || '', status: 'active', activity_id: p.activity_id || '', batch_id: p.batch_id || '', dues: p.dues || '', branch_id: p.branch_id || '' },
      filters: [
        ...(LK.branches.length ? [{ name: 'branch_id', placeholder: 'All branches', options: [...branchOpts(), ['none', 'Main / no branch']] }] : []),
        { name: 'activity_id', placeholder: 'All activities', options: actOpts() },
        { name: 'batch_id', placeholder: 'All batches', options: batchOpts() },
        { name: 'status', all: false, options: [['active', 'Active'], ['inactive', 'Inactive'], ['left', 'Left'], ['all', 'All']] },
        ...(can('fees.view') && has('fees') ? [{ name: 'dues', placeholder: 'Any fee status', options: [['1', 'With pending fees']] }] : []),
      ],
      columns: [
        { label: 'Student', render: (s) => person(s.name, `${esc(s.student_code)}${s.guardian_name ? ' · ' + esc(s.guardian_name) : ''}`, s.photo_url) },
        { label: 'WhatsApp', render: (s) => wa(s.country_code, s.whatsapp) },
        { label: 'Activity / Batch', render: (s) => `<span class="small">${esc(s.enrolled_in || '–')}</span>${s.branch_name ? `<div class="small text-muted"><i class="bi bi-geo-alt"></i> ${esc(s.branch_name)}</div>` : ''}` },
        ...(can('fees.view') && has('fees') ? [{ label: 'Pending', cls: 'text-end', render: (s) => +s.pending_paise ? `<span class="money fw-bold text-danger">${money(s.pending_paise)}</span>` : '<span class="text-success small">Clear</span>' }] : []),
        { label: '', cls: 'text-end', render: (s) => s.status !== 'active' ? badge(s.status) : (can('fees.collect') && has('fees') && +s.pending_paise ? `<a class="btn btn-sm btn-light" href="#/collect?student=${s.id}">Collect</a>` : '') },
      ],
      onRow: (s) => go('#/students/' + s.id), empty: 'No students found.', emptyIcon: 'bi-people',
    });
  }, 'Students');

  // ------------------------------------------------- quick registration (30–60 s)
  // Possible duplicate found while registering: open it, link it (no new profile), or – Class Admin only –
  // create a separate student after checking (reason goes to the audit log). Never merges automatically.
  function duplicateDialog(info, data, submit) {
    const cands = info.candidates || [];
    const m = modal({
      title: 'Possible duplicate student', size: 'lg',
      body: `<p class="small text-muted">These existing students look similar. Brothers and sisters can share a parent's WhatsApp number – check the name, guardian and date of birth.</p>
        <div class="list-group">${cands.map((c) => `<div class="list-group-item"><div class="d-flex flex-wrap gap-2 align-items-center">
          <div class="flex-grow-1"><strong>${esc(c.name)}</strong> <span class="small text-muted">${esc(c.student_code)}</span> ${badge(c.status)} ${c.level === 'high' ? '<span class="badge text-bg-danger">Likely same student</span>' : '<span class="badge text-bg-warning">Possible match</span>'}
            <div class="small text-muted">${esc(c.reasons.join(' · '))} · Guardian: ${esc(c.guardian_name || '–')} · DOB: ${c.dob ? date(c.dob) : '–'} · Branch: ${esc(c.branch_name)} · WhatsApp ••${esc(c.whatsapp_last4)}</div></div>
          <a class="btn btn-sm btn-light" href="#/students/${c.id}" data-bs-dismiss="modal">Open</a>
          <button class="btn btn-sm btn-primary" data-link="${c.id}">Link this student</button></div></div>`).join('')}</div>
        ${info.can_override ? `<div class="border-top mt-3 pt-3"><label class="form-label small fw-bold">This is a different student</label><input class="form-control" id="dr" placeholder="Reason, e.g. twin brother / different child of the same parent"></div>` : '<p class="small text-muted mt-3 mb-0">Only the Class Admin can create a separate profile when a possible duplicate exists.</p>'}`,
      footer: `<button class="btn btn-light" data-bs-dismiss="modal">Cancel</button>${info.can_override ? '<button class="btn btn-outline-danger" id="sep">Create separate student</button>' : ''}`,
    });
    $$('[data-link]', m.el).forEach((b) => b.onclick = async () => {
      try {
        const r = await post(`/students/${b.dataset.link}/link`, { activity_id: data.activity_id, batch_id: data.batch_id || null, branch_id: data.branch_id || null });
        toast(r.message); m.close(); go('#/students/' + b.dataset.link);
      } catch (e) { fail(e); }
    });
    $('#sep', m.el) && ($('#sep', m.el).onclick = async () => {
      const reason = $('#dr', m.el).value.trim();
      if (reason.length < 5) return toast('Please give a short reason.', 'warning');
      try { await submit(true, reason); m.close(); } catch (e) { fail(e); }
    });
  }

  route('/students/new', async (v) => {
    if (!can('students.manage')) throw new Error('You do not have permission to add students.');
    await lookups(true);
    if (!LK.activities.length) {
      v.innerHTML = head('Add Student') + `<div class="card"><div class="card-body text-center py-5"><i class="bi bi-collection fs-1 text-primary"></i><h5 class="mt-2">First, add an activity</h5><p class="text-muted">Students are registered into an activity (Cricket, Dance, Yoga…).</p><a class="btn btn-primary" href="#/activities?new=1">Add activity</a></div></div>`;
      return;
    }
    let photoBlob = null;
    let docFile = null;
    let ocrFields = null;
    v.innerHTML = head('Add Student', 'Only the fields marked * are needed. Everything else is optional.') + `
    <form id="sf" novalidate class="row g-3">
      <div class="col-lg-8"><div class="card"><div class="card-body">
        <div class="photo-pick mb-3"><div class="ph" id="ph"><i class="bi bi-person"></i></div>
          <div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-primary" id="cam"><i class="bi bi-camera"></i> Take photo</button><button type="button" class="btn btn-light" id="gal"><i class="bi bi-image"></i> Gallery</button><button type="button" class="btn btn-link text-danger d-none" id="rmph">Remove</button></div></div>
        ${form([
          { name: 'name', label: 'Student name', required: true, col: 'col-md-6', attrs: 'autocomplete="off" autofocus' },
          { name: 'whatsapp', label: 'WhatsApp number', type: 'phone', required: true, col: 'col-md-6', help: 'Parent\'s number for students under 18. Used for receipts & reminders.' },
          { name: 'activity_id', label: 'Activity / Class', type: 'select', required: true, options: actOpts(), col: 'col-md-6', value: LK.activities.length === 1 ? LK.activities[0].id : '' },
          { name: 'batch_id', label: 'Batch', type: 'select', options: [], col: 'col-md-6' },
          ...(LK.branches.length ? [{ name: 'branch_id', label: 'Branch', type: 'select', options: branchOpts(), placeholder: 'Same as batch', col: 'col-md-6', help: 'All branches share one student quota.' }] : []),
          { name: 'is_minor', label: 'Student is under 18', type: 'switch', value: 1, col: 'col-md-6' },
          { name: 'guardian_name', label: 'Parent / Guardian name', required: true, col: 'col-md-6' },
        ])}
        <div class="mt-3"><span class="more-toggle" data-bs-toggle="collapse" data-bs-target="#more"><i class="bi bi-plus-circle"></i> More details (optional)</span></div>
        <div class="collapse mt-3" id="more">${form([
          { name: 'dob', label: 'Date of birth', type: 'date', col: 'col-md-4', max: C.today() },
          { name: 'gender', label: 'Gender', type: 'select', options: [['male', 'Male'], ['female', 'Female'], ['other', 'Other']], col: 'col-md-4' },
          { name: 'email', label: 'Email', type: 'email', col: 'col-md-4' },
          { name: 'address', label: 'Address', type: 'textarea', rows: 2, col: 'col-12' },
          { name: 'guardian_relation', label: 'Relation', type: 'select', options: [['Father', 'Father'], ['Mother', 'Mother'], ['Guardian', 'Guardian']], col: 'col-md-4' },
          { name: 'parent_whatsapp', label: 'Separate parent WhatsApp (if different)', type: 'phone', ccName: 'parent_country_code', col: 'col-md-8' },
          { name: 'school_name', label: 'School / College', col: 'col-md-6' },
          { name: 'emergency_contact', label: 'Emergency contact', type: 'tel', col: 'col-md-6' },
          { name: 'blood_group', label: 'Blood group', type: 'select', options: ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'].map((x) => [x, x]), col: 'col-md-4' },
          { name: 'medical_notes', label: 'Medical notes / allergies', col: 'col-md-8' },
          { name: 'admission_date', label: 'Admission date', type: 'date', value: C.today(), col: 'col-md-4' },
          ...(can('fees.manage') && has('fees') ? [{ name: 'monthly_fee', label: 'Custom monthly fee (leave blank for default)', type: 'money', col: 'col-md-8' }] : []),
          { name: 'notes', label: 'Notes', type: 'textarea', rows: 2 },
        ])}</div>
      </div></div></div>
      <div class="col-lg-4">
        ${has('documents') && can('documents.manage') ? `<div class="card mb-3"><div class="card-header"><i class="bi bi-file-earmark-text text-primary"></i> Document (optional)</div><div class="card-body">
          <p class="small text-muted">Scan a birth certificate or ID to auto-fill name, date of birth and address. Aadhaar is never required.</p>
          <select class="form-select mb-2" id="doctype"><option value="birth_certificate">Birth certificate</option><option value="aadhaar">Aadhaar (optional)</option><option value="school_id">School ID</option><option value="other">Other</option></select>
          <div class="d-flex gap-2"><button type="button" class="btn btn-outline-primary flex-fill" id="docCam"><i class="bi bi-camera"></i> Scan</button><button type="button" class="btn btn-light flex-fill" id="docPick"><i class="bi bi-upload"></i> Upload</button></div>
          <div id="docInfo" class="small mt-2"></div></div></div>` : ''}
        <div class="card"><div class="card-body">
          <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="consent" name="consent_given" checked><label class="form-check-label small" for="consent">Parent / guardian has consented to sharing these details with the academy.</label></div>
          <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="sendmsg" name="send_message" checked><label class="form-check-label small" for="sendmsg">Send admission confirmation on WhatsApp</label></div>
          ${has('parent_access') ? '<div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="invp" name="invite_parent"><label class="form-check-label small" for="invp">Invite parent to the ClassNex app</label></div>' : ''}
          <button class="btn btn-primary btn-lg w-100" id="save"><i class="bi bi-check2-circle"></i> Save Student</button>
          <button type="button" class="btn btn-light w-100 mt-2" id="saveNew">Save &amp; add another</button>
        </div></div>
      </div>
    </form>`;
    const f = $('#sf', v);
    linkSelects(f);
    const minor = $('[name=is_minor]', f), gBox = $('[data-field=guardian_name]', f);
    minor.addEventListener('change', () => { gBox.classList.toggle('d-none', !minor.checked); $('[name=guardian_name]', gBox).required = minor.checked; });
    const setPhoto = (blob) => {
      photoBlob = blob;
      const ph = $('#ph', v);
      ph.style.backgroundImage = blob ? `url(${URL.createObjectURL(blob)})` : '';
      ph.classList.toggle('has', !!blob);
      ph.innerHTML = blob ? '' : '<i class="bi bi-person"></i>';
      $('#rmph', v).classList.toggle('d-none', !blob);
      $('#cam', v).innerHTML = blob ? '<i class="bi bi-arrow-repeat"></i> Retake' : '<i class="bi bi-camera"></i> Take photo';
    };
    $('#cam', v).onclick = async () => { const b = await C.capturePhoto({ facing: 'user', title: 'Student photo' }); if (b) setPhoto(await C.compress(b, 900, 0.85)); };
    $('#gal', v).onclick = async () => { const b = await C.pickFile('image/*'); if (b) setPhoto(await C.compress(b, 900, 0.85)); };
    $('#rmph', v).onclick = () => setPhoto(null);
    const handleDoc = async (file) => {
      if (!file) return;
      docFile = file.type === 'application/pdf' ? file : await C.compress(file, 1800, 0.9);
      const info = $('#docInfo', v);
      info.innerHTML = `<div class="text-success"><i class="bi bi-paperclip"></i> ${esc(file.name || 'Scanned document')} attached</div>`;
      if (!file.type?.startsWith('image/')) return;
      info.innerHTML += '<div class="text-muted" id="ocrst"><span class="spinner-border spinner-border-sm"></span> Reading document… <span id="ocrp">0</span>%</div>';
      try {
        const r = await C.ocr(file, (pct) => { const e = $('#ocrp', v); if (e) e.textContent = pct; });
        ocrFields = r;
        if (r.document_type && $('#doctype', v)) $('#doctype', v).value = ['aadhaar', 'birth_certificate', 'school_id'].includes(r.document_type) ? r.document_type : 'other';
        const fill = { name: r.name, dob: r.dob, gender: r.gender, address: r.address, guardian_name: r.guardian_name };
        const filled = [];
        Object.entries(fill).forEach(([k, val]) => {
          const el = $(`[name=${k}]`, f);
          if (val && el && !el.value) { el.value = val; el.classList.add('ocr-hit'); filled.push(label(k)); }
        });
        if (filled.some((x) => ['Dob', 'Gender', 'Address'].includes(x))) bootstrap.Collapse.getOrCreateInstance($('#more', v)).show();
        $('#ocrst', v).outerHTML = filled.length ? `<div class="alert alert-warning py-2 mt-2 mb-0 small"><i class="bi bi-magic"></i> Filled: ${filled.join(', ')}. ${esc(r.message)}${r.id_last4 ? `<br>ID: ${esc(r.id_last4)} (number not stored)` : ''}</div>` : `<div class="text-muted mt-1">${esc(r.message)}</div>`;
      } catch (e) { const s = $('#ocrst', v); if (s) s.outerHTML = `<div class="text-muted mt-1">${esc(e.message)}</div>`; }
    };
    $('#docCam', v) && ($('#docCam', v).onclick = async () => handleDoc(await C.capturePhoto({ facing: 'environment', title: 'Scan document' })));
    $('#docPick', v) && ($('#docPick', v).onclick = async () => handleDoc(await C.pickFile('image/*,application/pdf')));

    let another = false;
    $('#saveNew', v).onclick = () => { another = true; f.requestSubmit(); };
    f.onsubmit = async (e) => {
      e.preventDefault();
      const btn = $('#save', v);
      btn.disabled = true;
      const data = readForm(f);
      const fd = new FormData();
      Object.entries(data).forEach(([k, val]) => { if (val !== '' && val !== null) fd.append(k, val); });
      if (photoBlob) fd.append('photo', photoBlob, 'photo.jpg');
      const submit = async (confirmDup = false, reason = '') => {
        if (confirmDup) { fd.set('confirm_duplicate', '1'); fd.set('duplicate_reason', reason); }
        const r = await post('/students', fd);
        const s = r.data.student;
        if (docFile) {
          const d = new FormData();
          d.append('file', docFile, docFile.name || 'document.jpg');
          d.append('doc_type', $('#doctype', v)?.value || 'other');
          if (ocrFields) d.append('ocr_fields', JSON.stringify(ocrFields));
          try { await post(`/students/${s.id}/documents`, d); } catch (er) { toast('Student saved, but the document upload failed: ' + er.message, 'warning'); }
        }
        toast(`${s.name} registered · ID ${s.student_code}`);
        if (r.data.invite?.length && r.data.invite[0].whatsapp_link) toast('Parent invitation sent.', 'info');
        if (another) { another = false; C.dispatch(); } else go('#/students/' + s.id + '?new=1');
      };
      try { await submit(); }
      catch (er) {
        if (er.code === 'duplicate_student') duplicateDialog(er.errors, data, submit);
        else { showErrors(f, er.errors); fail(er); }
      } finally { btn.disabled = false; another = false; }
    };
  }, 'Add Student');

  // ----------------------------------------------------------- student profile
  route('/students/:id', async (v, p) => {
    const s = (await get('/students/' + p.id)).data;
    await lookups();
    const fees = has('fees') && can('fees.view');
    const active = s.enrollments.filter((e) => e.status === 'active');
    v.innerHTML = `
      <div class="card mb-3"><div class="card-body d-flex flex-wrap gap-3 align-items-center">
        ${s.photo_url ? `<img src="${esc(s.photo_url)}" class="avatar avatar-lg" style="object-fit:cover">` : `<span class="avatar avatar-lg">${esc(s.name[0])}</span>`}
        <div class="flex-grow-1 min-w-0"><h1 class="h4 fw-bold mb-1">${esc(s.name)} ${s.status !== 'active' ? badge(s.status) : ''}</h1>
          <div class="text-muted small">ID <strong>${esc(s.student_code)}</strong> · ${active.map((e) => esc(e.activity_name + (e.batch_name ? ' – ' + e.batch_name : ''))).join(', ') || 'Not enrolled'} · Joined ${date(s.admission_date)}${s.branch_name ? ` · <i class="bi bi-geo-alt"></i> ${esc(s.branch_name)} branch` : ''}</div>
          <div class="mt-1">${wa(s.country_code, s.whatsapp)} ${s.guardian_name ? `<span class="text-muted small ms-2">Guardian: ${esc(s.guardian_name)}</span>` : ''}</div></div>
        ${fees ? `<div class="text-end"><div class="small text-muted">Pending</div><div class="fs-4 fw-bold ${s.balance.outstanding_paise ? 'text-danger' : 'text-success'} money">${money(s.balance.outstanding_paise)}</div>${s.balance.advance_paise ? `<div class="small text-success">Advance ${money(s.balance.advance_paise)}</div>` : ''}</div>` : ''}
        <div class="d-flex flex-wrap gap-2 w-100">
          ${has('fees') && can('fees.collect') ? `<a class="btn btn-primary" href="#/collect?student=${s.id}"><i class="bi bi-cash-coin"></i> Collect fee</a>` : ''}
          ${can('students.manage') ? `<button class="btn btn-light" data-a="edit"><i class="bi bi-pencil"></i> Edit</button><button class="btn btn-light" data-a="transfer"><i class="bi bi-arrow-left-right"></i> Change batch</button>${LK.branches.length ? '<button class="btn btn-light" data-a="branch"><i class="bi bi-geo-alt"></i> Change branch</button>' : ''}` : ''}
          ${OWNER ? '<button class="btn btn-light" data-a="enroll"><i class="bi bi-plus-square"></i> Add activity</button>' : ''}
          ${has('manual_reminders') && can('notifications.send') ? '<button class="btn btn-light" data-a="msg"><i class="bi bi-chat-dots"></i> Message</button>' : ''}
          ${has('whatsapp_manual') && can('notifications.send') ? '<button class="btn btn-light text-success" data-a="wa"><i class="bi bi-whatsapp"></i> WhatsApp</button>' : ''}
          ${has('parent_access') && can('students.manage') ? '<button class="btn btn-light" data-a="invite"><i class="bi bi-phone"></i> App invite</button>' : ''}
        </div>
      </div></div>
      <ul class="nav nav-pills mb-3 gap-1" id="tabs">
        <li><a class="nav-link active" data-t="info" href="javascript:">Profile</a></li>
        ${fees ? '<li><a class="nav-link" data-t="fees" href="javascript:">Fees</a></li>' : ''}
        ${has('attendance') ? '<li><a class="nav-link" data-t="att" href="javascript:">Attendance</a></li>' : ''}
        ${has('documents') && can('documents.manage') ? '<li><a class="nav-link" data-t="docs" href="javascript:">Documents</a></li>' : ''}
        <li><a class="nav-link" data-t="family" href="javascript:">Parents</a></li>
      </ul><div id="tab"></div>`;
    const tabs = {
      info: (el) => {
        el.innerHTML = `<div class="row g-3"><div class="col-lg-6"><div class="card"><div class="card-body"><dl class="kv">
          ${[['Date of birth', date(s.dob)], ['Gender', label(s.gender) || '–'], ['Under 18', s.is_minor ? 'Yes' : 'No'], ['Email', esc(s.email || '–')], ['Address', esc(s.address || '–')], ['School', esc(s.school_name || '–')], ['Blood group', esc(s.blood_group || '–')], ['Medical notes', esc(s.medical_notes || '–')], ['Emergency', esc(s.emergency_contact || '–')], ['Consent', s.consent_given ? `Given by ${esc(s.consent_by || '')} on ${date(s.consent_at)}` : '<span class="text-danger">Not recorded</span>'], ['Source', label(s.source)]].map(([k, val]) => `<dt>${k}</dt><dd>${val}</dd>`).join('')}
        </dl></div></div></div><div class="col-lg-6"><div class="card"><div class="card-header">Enrolment history</div><div class="card-body py-1">${s.enrollments.map((e) => `<div class="list-tile"><div class="flex-grow-1"><div class="fw-bold">${esc(e.activity_name)} ${e.batch_name ? '· ' + esc(e.batch_name) : ''}</div><div class="small text-muted">${date(e.start_date)} → ${e.end_date ? date(e.end_date) : 'present'}</div></div>${badge(e.status)}</div>`).join('')}</div></div>
        ${s.notes ? `<div class="card mt-3"><div class="card-body small">${esc(s.notes)}</div></div>` : ''}</div></div>`;
      },
      fees: async (el) => {
        const [inv, pays] = await Promise.all([get(`/invoices?student_id=${s.id}&status=all&per_page=100`), get(`/payments?student_id=${s.id}&per_page=100`)]);
        el.innerHTML = `<div class="d-flex flex-wrap gap-2 mb-2">${can('fees.manage') ? '<button class="btn btn-sm btn-light" id="assignFee"><i class="bi bi-plus"></i> Assign fee</button><button class="btn btn-sm btn-light" id="addCharge"><i class="bi bi-plus"></i> One-time charge</button>' : ''}</div>
          ${s.fee_assignments?.length ? `<h6 class="fw-bold mt-2">Fee plans</h6>${table([
            { label: 'Fee', render: (a) => `<div class="fw-bold">${esc(a.name)}</div><div class="small text-muted">${a.fee_type === 'recurring' ? `Every ${a.frequency_months == 1 ? 'month' : a.frequency_months + ' months'} · due on ${a.due_day}` : label(a.fee_type)}</div>` },
            { label: 'Amount', cls: 'text-end', render: (a) => `<span class="money">${money(a.amount_paise)}</span>${+a.discount_paise ? `<div class="small text-success">−${money(a.discount_paise)}</div>` : ''}` },
            { label: 'Status', render: (a) => badge(a.status) + (a.paused_until ? `<div class="small text-muted">until ${date(a.paused_until)}</div>` : '') },
            { label: '', cls: 'text-end', render: (a) => can('fees.manage') && OWNER && ['active', 'paused'].includes(a.status) ? `<button class="btn btn-sm btn-light" data-fa="${a.id}">Change</button>` : '' },
          ], s.fee_assignments)}` : ''}
          <h6 class="fw-bold mt-3">Invoices</h6>${table([
            { label: 'Invoice', render: (i) => `<div class="fw-bold">${esc(i.title)}</div><div class="small text-muted">${esc(i.invoice_no)} · due ${date(i.due_date)}</div>` },
            { label: 'Total', cls: 'text-end', render: (i) => `<span class="money">${money(i.total_paise)}</span>` },
            { label: 'Paid', cls: 'text-end', render: (i) => `<span class="money">${money(i.paid_paise)}</span>` },
            { label: 'Status', render: (i) => badge(i.status === 'unpaid' && i.days_overdue > 0 ? 'overdue' : i.status, i.status === 'unpaid' && i.days_overdue > 0 ? `Overdue ${i.days_overdue}d` : null) },
            { label: '', cls: 'text-end', render: (i) => OWNER && can('fees.manage') && i.status === 'unpaid' ? `<button class="btn btn-sm btn-link text-danger" data-cancel="${i.id}">Cancel</button>` : '' },
          ], inv.data, { empty: 'No invoices yet.' })}
          <h6 class="fw-bold mt-3">Payments</h6>${paymentsTable(pays.data)}`;
        bindPaymentRows(el, pays.data, () => tabs.fees(el));
        $('#assignFee', el) && ($('#assignFee', el).onclick = () => assignFeeModal(s.id, () => C.dispatch()));
        $('#addCharge', el) && ($('#addCharge', el).onclick = () => formModal({ title: 'Add one-time charge', fields: [{ name: 'title', label: 'Description', required: true, placeholder: 'e.g. Tournament fee, Kit' }, { name: 'amount', label: 'Amount', type: 'money', required: true, col: 'col-6' }, { name: 'due_date', label: 'Due date', type: 'date', value: C.today(), col: 'col-6' }], submit: async (d) => { const r = await post(`/students/${s.id}/charges`, d); toast(r.message); C.dispatch(); } }));
        $$('[data-cancel]', el).forEach((b) => b.onclick = async () => { const reason = await confirm('Cancel this invoice? It will no longer be due.', { input: 'Reason (required)', danger: true, ok: 'Cancel invoice' }); if (!reason) return; try { const r = await post(`/invoices/${b.dataset.cancel}/cancel`, { reason }); toast(r.message); tabs.fees(el); } catch (e) { fail(e); } });
        $$('[data-fa]', el).forEach((b) => b.onclick = () => {
          const a = s.fee_assignments.find((x) => String(x.id) === b.dataset.fa);
          formModal({ title: 'Change fee: ' + a.name, intro: '<p class="small text-muted">Changes apply to future dues only. Already generated invoices are not changed.</p>', fields: [
            { name: 'amount', label: 'Amount', type: 'money', value: rupees(a.amount_paise), col: 'col-6' }, { name: 'discount', label: 'Discount per invoice', type: 'money', value: rupees(a.discount_paise), col: 'col-6' },
            { name: 'due_day', label: 'Due day of month', type: 'number', min: 1, max: 28, value: a.due_day, col: 'col-6' },
            { name: 'status', label: 'Status', type: 'select', placeholder: false, options: [['active', 'Active'], ['paused', 'Paused (fee holiday)'], ['stopped', 'Stopped']], value: a.status, col: 'col-6' },
            { name: 'paused_until', label: 'Paused until (blank = until resumed)', type: 'date', value: a.paused_until || '', col: 'col-6' },
          ], submit: async (d) => { const r = await put('/fee-assignments/' + a.id, d); toast(r.message); C.dispatch(); } });
        });
      },
      att: async (el) => {
        let m = C.month();
        const draw = async () => {
          const r = (await get(`/students/${s.id}/attendance?month=${m}`)).data;
          const first = new Date(m + '-01');
          const days = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();
          const offset = (first.getDay() + 6) % 7;
          const map = Object.fromEntries(r.days.map((d) => [+d.session_date.slice(8), d.status]));
          el.innerHTML = `<div class="card"><div class="card-body"><div class="d-flex align-items-center gap-2 mb-3"><input type="month" class="form-control w-auto" value="${m}" id="mp"><div class="ms-auto">${r.percentage !== null ? `<span class="fs-4 fw-bold">${r.percentage}%</span> <span class="text-muted small">attendance</span>` : ''}</div></div>
            <div class="d-flex flex-wrap gap-2 mb-3">${Object.entries(r.counts).filter(([, n]) => n).map(([k, n]) => badge(k, `${label(k)}: ${n}`)).join('') || '<span class="text-muted small">No attendance this month</span>'}</div>
            <div class="cal" style="max-width:420px">${['M', 'T', 'W', 'T', 'F', 'S', 'S'].map((x) => `<div class="h">${x}</div>`).join('')}${'<div class="h"></div>'.repeat(offset)}${Array.from({ length: days }, (_, i) => `<div class="${map[i + 1] || ''}" title="${map[i + 1] || ''}">${i + 1}</div>`).join('')}</div>
            ${r.corrections.length ? `<h6 class="fw-bold mt-4">Corrections</h6>${r.corrections.map((c) => `<div class="small">${date(c.session_date)}: ${label(c.old_status)} → <strong>${label(c.new_status)}</strong> by ${esc(c.by_name || '')} ${c.reason ? '(' + esc(c.reason) + ')' : ''}</div>`).join('')}` : ''}</div></div>`;
          $('#mp', el).onchange = (e) => { m = e.target.value; draw(); };
        };
        draw();
      },
      docs: async (el) => {
        const docs = (await get(`/students/${s.id}/documents`)).data;
        el.innerHTML = `<div class="mb-2"><button class="btn btn-sm btn-light" id="upd"><i class="bi bi-upload"></i> Upload document</button> <button class="btn btn-sm btn-light" id="scd"><i class="bi bi-camera"></i> Scan</button></div>${table([
          { label: 'Document', render: (d) => `<div class="fw-bold">${label(d.doc_type)}</div><div class="small text-muted">${esc(d.title || d.original_name || '')}</div>` },
          { label: 'Uploaded', render: (d) => date(d.created_at) }, { label: 'Auto-delete', render: (d) => date(d.retention_until) },
          { label: '', cls: 'text-end', render: (d) => `<button class="btn btn-sm btn-light" data-view="${d.id}">View</button> ${OWNER ? `<button class="btn btn-sm btn-link text-danger" data-del="${d.id}">Delete</button>` : ''}` },
        ], docs, { empty: 'No documents. Documents are optional and stored encrypted.', emptyIcon: 'bi-shield-lock' })}`;
        const up = async (file) => { if (!file) return; const fd = new FormData(); fd.append('file', file.type === 'application/pdf' ? file : await C.compress(file, 1800, 0.9), file.name || 'document.jpg'); fd.append('doc_type', 'other'); try { await post(`/students/${s.id}/documents`, fd); toast('Document saved'); tabs.docs(el); } catch (e) { fail(e); } };
        $('#upd', el).onclick = async () => up(await C.pickFile('image/*,application/pdf'));
        $('#scd', el).onclick = async () => up(await C.capturePhoto({ facing: 'environment', title: 'Scan document' }));
        $$('[data-view]', el).forEach((b) => b.onclick = () => C.openPdf('/files/document/' + b.dataset.view));
        $$('[data-del]', el).forEach((b) => b.onclick = async () => { if (!(await confirm('Permanently delete this document? This cannot be undone.', { danger: true, ok: 'Delete' }))) return; try { await del('/documents/' + b.dataset.del); toast('Deleted'); tabs.docs(el); } catch (e) { fail(e); } });
      },
      family: (el) => {
        el.innerHTML = `${can('students.manage') ? '<button class="btn btn-sm btn-light mb-2" id="addp"><i class="bi bi-plus"></i> Link parent</button>' : ''}${table([
          { label: 'Parent', render: (x) => `<div class="fw-bold">${esc(x.name)}</div><div class="small text-muted">${esc(x.relation || '')}</div>` },
          { label: 'WhatsApp', render: (x) => wa(x.country_code, x.whatsapp) }, { label: 'Email', render: (x) => esc(x.email || '–') },
          { label: 'App login', render: (x) => x.login_status ? badge(x.login_status) : '<span class="small text-muted">Not invited</span>' },
          { label: '', cls: 'text-end', render: (x) => OWNER ? `<button class="btn btn-sm btn-link text-danger" data-unlink="${x.id}">Unlink</button>` : '' },
        ], s.parents, { empty: 'No parent linked.' })}`;
        $('#addp', el) && ($('#addp', el).onclick = () => formModal({ title: 'Link parent / guardian', intro: '<p class="small text-muted">If this number already belongs to a parent in your academy, the child is added to the same parent account.</p>', fields: [{ name: 'name', label: 'Name', required: true }, { name: 'whatsapp', label: 'WhatsApp number', type: 'phone', required: true }, { name: 'relation', label: 'Relation', type: 'select', options: [['Father', 'Father'], ['Mother', 'Mother'], ['Guardian', 'Guardian']], col: 'col-6' }, { name: 'email', label: 'Email', type: 'email', col: 'col-6' }], submit: async (d) => { const r = await post(`/students/${s.id}/parents`, d); toast(r.message); C.dispatch(); } }));
        $$('[data-unlink]', el).forEach((b) => b.onclick = async () => { if (!(await confirm('Unlink this parent from the student?'))) return; await del(`/students/${s.id}/parents/${b.dataset.unlink}`).then(() => C.dispatch()).catch(fail); });
      },
    };
    const show = (t) => { $$('#tabs .nav-link', v).forEach((a) => a.classList.toggle('active', a.dataset.t === t)); tabs[t]($('#tab', v)); };
    $$('#tabs .nav-link', v).forEach((a) => a.onclick = () => show(a.dataset.t));
    show(p.tab && tabs[p.tab] ? p.tab : 'info');

    $$('[data-a]', v).forEach((b) => b.onclick = () => ({
      edit: () => formModal({
        title: 'Edit ' + s.name, size: 'lg', values: s, fields: [
          { name: 'name', label: 'Name', required: true, col: 'col-md-6' }, { name: 'whatsapp', label: 'WhatsApp', type: 'phone', cc: s.country_code, required: true, col: 'col-md-6' },
          { name: 'guardian_name', label: 'Guardian name', col: 'col-md-6' }, { name: 'is_minor', label: 'Under 18', type: 'switch', col: 'col-md-6' },
          { name: 'dob', label: 'Date of birth', type: 'date', col: 'col-md-4' }, { name: 'gender', label: 'Gender', type: 'select', options: [['male', 'Male'], ['female', 'Female'], ['other', 'Other']], col: 'col-md-4' }, { name: 'email', label: 'Email', type: 'email', col: 'col-md-4' },
          { name: 'address', label: 'Address', type: 'textarea', rows: 2 }, { name: 'school_name', label: 'School', col: 'col-md-6' }, { name: 'emergency_contact', label: 'Emergency contact', col: 'col-md-6' },
          { name: 'blood_group', label: 'Blood group', col: 'col-md-3' }, { name: 'medical_notes', label: 'Medical notes', col: 'col-md-9' },
          { name: 'status', label: 'Status', type: 'select', placeholder: false, options: [['active', 'Active'], ['inactive', 'Inactive (on break)'], ['left', 'Left the academy']], col: 'col-md-6', help: 'Inactive / left students stop receiving new fee dues. History is kept.' },
          { name: 'whatsapp_opt_in', label: 'Send WhatsApp messages', type: 'switch', col: 'col-md-6' }, { name: 'consent_given', label: 'Guardian consent recorded', type: 'switch', col: 'col-md-6' },
          { name: 'notes', label: 'Notes', type: 'textarea', rows: 2 },
        ], submit: async (d) => { const r = await post('/students/' + s.id, d); toast(r.message); C.dispatch(); },
      }),
      transfer: () => formModal({ title: 'Change batch', intro: '<p class="small text-muted">Attendance and fee history stay with the student.</p>', fields: [{ name: 'batch_id', label: 'New batch', type: 'select', required: true, options: batchOpts() }, { name: 'date', label: 'From date', type: 'date', value: C.today() }], submit: async (d) => { const r = await post(`/students/${s.id}/transfer`, d); toast(r.message); C.dispatch(); } }),
      branch: () => {
        const bopts = (brId) => LK.batches.filter((b) => String(b.branch_id || '') === String(brId)).map((b) => [b.id, `${b.name} (${b.activity_name})`]);
        const m = formModal({ title: 'Change branch', intro: '<p class="small text-muted">The same student profile moves to the new branch. Fees, attendance and the Student ID stay; your student count does not change.</p>', values: { branch_id: s.branch_id || '' },
          fields: [{ name: 'branch_id', label: 'New branch', type: 'select', required: true, options: branchOpts() }, { name: 'batch_id', label: 'New batch at that branch (optional)', type: 'select', options: bopts(s.branch_id || '') }, { name: 'date', label: 'From date', type: 'date', value: C.today() }],
          submit: async (d) => { const r = await post(`/students/${s.id}/branch`, d); toast(r.message); C.dispatch(); } });
        const bs = $('[name=branch_id]', m.el), bb = $('[name=batch_id]', m.el);
        bs.onchange = () => { bb.innerHTML = '<option value="">Keep current batch</option>' + bopts(bs.value).map(([v, l]) => `<option value="${v}">${esc(l)}</option>`).join(''); };
      },
      enroll: () => { const m = formModal({ title: 'Enrol in another activity', fields: [{ name: 'activity_id', label: 'Activity', type: 'select', required: true, options: actOpts() }, { name: 'batch_id', label: 'Batch', type: 'select', options: [] }, { name: 'monthly_fee', label: 'Monthly fee (blank = default)', type: 'money' }, { name: 'assign_fees', label: 'Start monthly fee', type: 'switch', value: 1 }], submit: async (d) => { const r = await post(`/students/${s.id}/enroll`, d); toast(r.message); C.dispatch(); } }); linkSelects(m.el); },
      msg: () => messageModal({ target: 'students', student_ids: [s.id] }, s.name),
      wa: () => {
        const m = formModal({ title: 'WhatsApp ' + s.name, okText: 'Prepare message', intro: '<p class="small text-muted">ClassNex prepares the text; WhatsApp opens and you press send.</p>',
          fields: [{ name: 'purpose', label: 'Message', type: 'select', placeholder: false, options: [['custom', 'Custom message'], ['admission', 'Admission confirmation'], ...(fees && s.balance.outstanding_paise > 0 ? [['fee_reminder', 'Fee reminder']] : [])] }, { name: 'message', label: 'Text (custom message)', type: 'textarea', rows: 4 }],
          submit: async (d) => { const r = await post('/whatsapp/manual/prepare', { ...d, target: 'students', student_ids: [s.id], student_id: s.id }); waSend(r.data.items, 'WhatsApp'); } });
        void m;
      },
      invite: async () => { try { const r = await post(`/students/${s.id}/invite`); inviteResult(r.data); } catch (e) { fail(e); } },
    })[b.dataset.a]());
    if (p.new && has('whatsapp_manual') && !has('whatsapp') && can('notifications.send')) waPrepare({ purpose: 'admission', student_id: s.id }, 'Admission confirmation');
    if (p.new && can('fees.collect') && has('fees') && fees && s.balance.outstanding_paise > 0) {
      const m = modal({ title: 'Student registered 🎉', body: `<p><strong>${esc(s.name)}</strong> was registered with ID <strong>${esc(s.student_code)}</strong>.</p><p class="mb-0">Pending fees: <strong>${money(s.balance.outstanding_paise)}</strong>. Collect now?</p>`, footer: `<button class="btn btn-light" data-bs-dismiss="modal">Later</button><a class="btn btn-primary" href="#/collect?student=${s.id}">Collect fee</a>` });
      $('a', m.footer).onclick = () => m.close();
    }
  }, 'Student');

  function inviteResult(list) {
    modal({ title: 'App invitation', body: list.map((x) => x.already_active ? `<p><strong>${esc(x.name)}</strong> already has an active account.</p>` : `<p><strong>${esc(x.name)}</strong> (${label(x.role)}) – activation link sent by WhatsApp / email.</p><div class="input-group mb-2"><input class="form-control form-control-sm" value="${esc(x.link)}" readonly><button class="btn btn-sm btn-light" onclick="navigator.clipboard.writeText('${esc(x.link)}');CNX.toast('Copied')">Copy</button></div>${x.whatsapp_link ? `<a class="btn btn-success btn-sm mb-3" target="_blank" href="${esc(x.whatsapp_link)}"><i class="bi bi-whatsapp"></i> Share on WhatsApp</a>` : ''}`).join('') || 'No parent to invite. Link a parent first.' });
  }

  function assignFeeModal(studentId, done) {
    formModal({
      title: 'Assign fee', fields: [
        { name: 'name', label: 'Fee name', required: true, placeholder: 'e.g. Coaching fee' },
        { name: 'fee_type', label: 'Type', type: 'select', required: true, placeholder: false, options: [['recurring', 'Recurring (monthly/quarterly…)'], ['one_time', 'One-time'], ['admission', 'Admission'], ['registration', 'Registration'], ['additional', 'Additional charge']], col: 'col-md-6' },
        { name: 'frequency_months', label: 'Repeats every', type: 'select', placeholder: false, options: [[1, 'Month'], [3, 'Quarter (3 months)'], [6, 'Half year'], [12, 'Year']], col: 'col-md-6' },
        { name: 'amount', label: 'Amount', type: 'money', required: true, col: 'col-md-4' }, { name: 'discount', label: 'Discount', type: 'money', col: 'col-md-4' }, { name: 'due_day', label: 'Due day', type: 'number', value: 5, min: 1, max: 28, col: 'col-md-4' },
        { name: 'start_date', label: 'Start from', type: 'date', value: C.today(), col: 'col-md-6' }, { name: 'prorate_first', label: 'Prorate first month', type: 'switch', col: 'col-md-6' },
      ], submit: async (d) => { const r = await post(`/students/${studentId}/fees`, d); toast(r.message); done && done(); },
    });
  }

  // ================================================================== ATTENDANCE
  route('/attendance', async (v, p) => {
    const day = p.date || C.today();
    const r = (await get('/attendance/today?date=' + day)).data;
    v.innerHTML = head('Attendance', 'Choose a batch to mark', `<input type="date" class="form-control" id="d" value="${day}" max="${C.today()}"><a class="btn btn-light" href="#/attendance-history"><i class="bi bi-clock-history"></i> History</a>`) +
      (r.batches.length ? `<div class="row g-3">${r.batches.map((b) => `<div class="col-md-6 col-xl-4"><a class="card h-100 text-decoration-none text-reset" href="#/attendance/${b.id}?date=${day}"><div class="card-body d-flex gap-3 align-items-center">
        <div class="stat p-0 border-0"><div class="ic ${b.session_id ? 'ic-green' : (+b.scheduled ? 'ic-orange' : 'ic-gray')}"><i class="bi ${b.session_id ? 'bi-check2-all' : 'bi-calendar-check'}"></i></div></div>
        <div class="flex-grow-1"><div class="fw-bold">${esc(b.name)}</div><div class="small text-muted">${esc(b.activity_name)} ${b.start_time ? '· ' + time(b.start_time) : ''}</div>
        <div class="small mt-1">${b.session_id ? `<span class="text-success fw-bold">${b.present} present</span> · <span class="text-danger">${b.absent} absent</span>` : (+b.scheduled ? '<span class="text-warning fw-bold">Not marked yet</span>' : '<span class="text-muted">No class scheduled</span>')} · ${b.enrolled} students</div></div><i class="bi bi-chevron-right text-muted"></i></div></a></div>`).join('')}</div>`
        : `<div class="card"><div class="empty"><i class="bi bi-collection"></i>${OWNER ? 'No batches yet. <a href="#/batches">Create a batch</a> first.' : 'No batches are assigned to you yet.'}</div></div>`);
    $('#d', v).onchange = (e) => go('#/attendance?date=' + e.target.value);
  }, 'Attendance');

  route('/attendance/:batch', async (v, p) => {
    const day = p.date || C.today();
    const r = (await get(`/attendance/sheet?batch_id=${p.batch}&date=${day}`)).data;
    const b = (LK?.batches || (await lookups()).batches).find((x) => String(x.id) === String(p.batch)) || {};
    // present by default: a new register starts with everyone present; the teacher only taps the exceptions
    const state = Object.fromEntries(r.students.map((s) => [s.id, s.status || (r.holiday ? 'holiday' : (r.already_marked ? null : 'present'))]));
    const opts = [['present', 'P'], ['absent', 'A'], ['late', 'L'], ['leave', 'Lv']];
    v.innerHTML = head(`${b.name || 'Batch'} · ${date(day)}`, `${esc(b.activity_name || '')} · ${r.students.length} students ${r.already_marked ? '· <span class="text-success fw-bold">already marked – you can update it</span>' : ''}`, `<a class="btn btn-light" href="#/attendance?date=${day}">Back</a>`) +
      (r.holiday ? `<div class="alert alert-info">Holiday: ${esc(r.holiday)}</div>` : '') +
      (r.students.length ? `${!r.already_marked && !r.holiday ? '<div class="alert alert-success py-2 small mb-2"><i class="bi bi-check2-all"></i> Everyone is marked <b>present</b>. Tap only the students who are absent, late or on leave, then Save.</div>' : ''}<div class="d-flex flex-wrap gap-2 mb-3"><button class="btn btn-success" id="allP"><i class="bi bi-check2-all"></i> Mark all present</button><button class="btn btn-outline-danger" id="allA">Mark all absent</button><input class="form-control ms-auto" style="max-width:260px" placeholder="Find student…" id="find"></div>
      <div class="att-list" id="al">${r.students.map((s) => `<div class="att-row" data-sid="${s.id}">${person(s.name, esc(s.student_code), s.photo_url)}<div class="att-btns">${opts.map(([k, l]) => `<button type="button" class="att-btn" data-st="${k}" title="${label(k)}">${l}</button>`).join('')}</div></div>`).join('')}</div>
      <div class="sticky-save"><div class="card"><div class="card-body d-flex align-items-center gap-3 py-2"><div class="small" id="cnt"></div><button class="btn btn-primary btn-lg ms-auto px-5" id="saveAtt"><i class="bi bi-check2-circle"></i> Save</button></div></div></div>` : '<div class="card"><div class="empty"><i class="bi bi-people"></i>No students in this batch.</div></div>');
    const paint = () => {
      $$('.att-row', v).forEach((row) => $$('.att-btn', row).forEach((btn) => { btn.className = 'att-btn' + (state[row.dataset.sid] === btn.dataset.st ? ' on-' + btn.dataset.st : ''); }));
      const c = { present: 0, absent: 0, late: 0, leave: 0, none: 0 };
      Object.values(state).forEach((s) => c[s || 'none'] !== undefined ? c[s || 'none']++ : null);
      const cnt = $('#cnt', v);
      if (cnt) cnt.innerHTML = `<span class="text-success fw-bold">${c.present} P</span> · <span class="text-danger fw-bold">${c.absent} A</span> · ${c.late} L · ${c.leave} Lv ${c.none ? `· <span class="text-warning">${c.none} not marked</span>` : ''}`;
    };
    paint();
    $$('.att-btn', v).forEach((btn) => btn.onclick = () => { state[btn.closest('.att-row').dataset.sid] = btn.dataset.st; paint(); });
    $('#allP', v) && ($('#allP', v).onclick = () => { Object.keys(state).forEach((k) => (state[k] = 'present')); paint(); });
    $('#allA', v) && ($('#allA', v).onclick = () => { Object.keys(state).forEach((k) => (state[k] = 'absent')); paint(); });
    $('#find', v) && ($('#find', v).oninput = (e) => { const q = e.target.value.toLowerCase(); $$('.att-row', v).forEach((row) => row.classList.toggle('d-none', !row.textContent.toLowerCase().includes(q))); });
    $('#saveAtt', v) && ($('#saveAtt', v).onclick = async () => {
      const entries = Object.entries(state).filter(([, st]) => st).map(([sid, st]) => ({ student_id: +sid, status: st }));
      if (!entries.length) return toast('Mark at least one student.', 'warning');
      const missing = Object.values(state).filter((x) => !x).length;
      if (missing && !(await confirm(`${missing} student(s) are not marked. Save anyway?`))) return;
      let reason = null;
      if (r.already_marked && day < C.today()) { reason = await confirm('You are changing earlier attendance. Please give a reason.', { input: 'Reason', ok: 'Save changes' }); if (!reason) return; }
      const btn = $('#saveAtt', v);
      btn.disabled = true;
      try {
        const res = await post('/attendance', { batch_id: +p.batch, date: day, entries, reason });
        toast(res.message + (res.data.notifications ? ` Parents notified: ${res.data.notifications}.` : ''));
        const absent = entries.filter((x) => ['absent', 'late'].includes(x.status)).length;
        if (has('whatsapp_manual') && !has('whatsapp') && absent && can('notifications.send') && (await confirm(`${absent} student(s) absent or late. Prepare WhatsApp messages for their parents?`, { ok: 'Prepare WhatsApp' }))) {
          await waPrepare({ purpose: 'attendance', batch_id: +p.batch, date: day }, 'Attendance alerts');
        }
        go('#/attendance?date=' + day);
      } catch (e) { fail(e); btn.disabled = false; }
    });
  }, 'Mark Attendance');

  route('/attendance-history', async (v, p) => {
    await lookups();
    const st = { view: p.view || 'sessions', from: p.from || C.month() + '-01', to: p.to || C.today(), branch_id: p.branch_id || '', activity_id: p.activity_id || '', batch_id: p.batch_id || '', student_id: p.student_id || '', status: p.status || '' };
    const pct = (x) => (x === null || x === undefined ? '–' : x + '%');
    const card = (l, x, cls = '') => `<div class="col-4 col-md-2"><div class="stat py-2"><div><div class="lbl">${l}</div><div class="val fs-5 ${cls}">${x}</div></div></div></div>`;
    const views = [['sessions', 'By class'], ['students', 'By student'], ...(LK.branches.length ? [['branches', 'By branch']] : [])];
    v.innerHTML = head('Attendance history', LK.branches.length ? 'Pick a branch for one branch, or leave “All branches” for the combined academy history.' : '',
      `<div class="btn-group"><button class="btn btn-light" data-x="pdf"><i class="bi bi-filetype-pdf"></i> PDF</button><button class="btn btn-light" data-x="xlsx"><i class="bi bi-file-earmark-excel"></i> Excel</button><button class="btn btn-light" data-x="csv">CSV</button></div>`) + `
      <div class="d-flex gap-2 flex-wrap mb-2"><div class="btn-group" id="vw">${views.map(([k, l]) => `<button class="btn btn-sm ${st.view === k ? 'btn-primary' : 'btn-light'}" data-v="${k}">${l}</button>`).join('')}</div></div>
      <div class="row g-2 mb-3" id="sum"></div><div id="bb"></div><div id="h"></div>`;
    const studentOpt = st.student_id ? [[st.student_id, p.student_name || 'Selected student']] : [];
    const columns = {
      sessions: [
        { label: 'Date', render: (s) => `<strong>${date(s.session_date)}</strong>` },
        ...(LK.branches.length ? [{ label: 'Branch', render: (s) => esc(s.branch_name) }] : []),
        { label: 'Batch', render: (s) => `${esc(s.batch_name)} <span class="text-muted small">${esc(s.activity_name)}</span>` },
        { label: 'Students', cls: 'text-end', render: (s) => s.total_students },
        { label: 'Present', cls: 'text-end', render: (s) => `<span class="text-success fw-bold">${s.present}</span>` },
        { label: 'Absent', cls: 'text-end', render: (s) => `<span class="text-danger fw-bold">${s.absent}</span>` },
        { label: 'Late', cls: 'text-end', render: (s) => s.late }, { label: 'Leave', cls: 'text-end', render: (s) => s.leave },
        { label: '%', cls: 'text-end', render: (s) => pct(s.attendance_pct) }, { label: 'Marked by', render: (s) => esc(s.marked_by || '') },
      ],
      students: [
        { label: 'Date', render: (s) => date(s.session_date) },
        { label: 'Student', render: (s) => `<a href="#/students/${s.student_id}">${esc(s.student_name)}</a> <span class="small text-muted">${esc(s.student_code)}</span>` },
        ...(LK.branches.length ? [{ label: 'Branch', render: (s) => esc(s.branch_name || 'Main') }] : []),
        { label: 'Batch', render: (s) => `${esc(s.batch_name)} <span class="text-muted small">${esc(s.activity_name)}</span>` },
        { label: 'Status', render: (s) => badge(s.status) }, { label: 'Remarks', render: (s) => esc(s.remarks || '') },
      ],
      branches: [
        { label: 'Branch', render: (s) => `<strong>${esc(s.branch_name)}</strong>` }, { label: 'Classes', cls: 'text-end', render: (s) => s.sessions },
        { label: 'Students', cls: 'text-end', render: (s) => s.total_students }, { label: 'Present', cls: 'text-end', render: (s) => s.present },
        { label: 'Absent', cls: 'text-end', render: (s) => s.absent }, { label: 'Late', cls: 'text-end', render: (s) => s.late }, { label: 'Leave', cls: 'text-end', render: (s) => s.leave },
        { label: 'Attendance %', cls: 'text-end', render: (s) => `<strong>${pct(s.attendance_pct)}</strong>` },
      ],
    };
    const list = listView({
      el: $('#h', v), endpoint: '/attendance/history', search: false, params: st, perPage: 50,
      filters: [
        { name: 'from', type: 'date', placeholder: 'From' }, { name: 'to', type: 'date', placeholder: 'To' },
        ...(LK.branches.length ? [{ name: 'branch_id', placeholder: 'All branches (combined)', options: [...branchOpts(), ['none', 'Main / no branch']] }] : []),
        { name: 'activity_id', placeholder: 'All activities', options: LK.activities.map((a) => [a.id, a.name]) },
        { name: 'batch_id', placeholder: 'All batches', options: batchOpts() },
        { name: 'status', placeholder: 'Any status', options: [['present', 'Present'], ['absent', 'Absent'], ['late', 'Late'], ['leave', 'Leave']] },
        ...(studentOpt.length ? [{ name: 'student_id', placeholder: 'All students', options: studentOpt }] : []),
      ],
      columns: columns[st.view], empty: 'No attendance for these filters.',
      after: (json) => {
        const s = json.meta?.summary || {};
        $('#sum', v).innerHTML = card('Students', s.total_students ?? 0) + card('Present', s.present ?? 0, 'text-success') + card('Absent', s.absent ?? 0, 'text-danger') + card('Late', s.late ?? 0) + card('Leave', s.leave ?? 0) + card('Attendance', pct(s.attendance_pct));
        const bb = json.meta?.by_branch;
        $('#bb', v).innerHTML = bb?.length > 1 ? `<div class="card mb-3"><div class="card-header">Branch-wise (combined academy)</div><div class="card-body p-0">${table(columns.branches, bb)}</div></div>` : '';
      },
    });
    $$('[data-v]', v).forEach((b) => b.onclick = () => go('#/attendance-history' + qs({ ...list.state, page: undefined, view: b.dataset.v })));
    $$('[data-x]', v).forEach((b) => b.onclick = () => C.download('/attendance/history' + qs({ ...list.state, page: undefined, format: b.dataset.x }), `attendance-history.${b.dataset.x}`));
  }, 'Attendance History');

  // ================================================================== COLLECT FEE
  route('/collect', async (v, p) => {
    if (!can('fees.collect')) throw new Error('You do not have permission to collect fees.');
    v.innerHTML = head('Collect Fee', 'Search the student, check dues, enter the amount, save. A receipt is generated automatically.') + `
      <div class="row g-3"><div class="col-lg-5"><div class="card"><div class="card-body">
        <label class="form-label">Student</label><div class="position-relative"><input class="form-control form-control-lg" id="q" placeholder="Name, ID or phone…" autocomplete="off"><div class="list-group position-absolute w-100 shadow" style="z-index:10" id="sug"></div></div>
        <div id="who" class="mt-3"></div></div></div></div><div class="col-lg-7" id="pay"></div></div>`;
    const q = $('#q', v), sug = $('#sug', v);
    let t;
    q.oninput = () => {
      clearTimeout(t);
      if (q.value.trim().length < 2) { sug.innerHTML = ''; return; }
      t = setTimeout(async () => {
        const r = await get('/students' + qs({ q: q.value.trim(), per_page: 8 }));
        sug.innerHTML = r.data.map((s) => `<button type="button" class="list-group-item list-group-item-action" data-id="${s.id}">${person(s.name, `${esc(s.student_code)} · ${esc(s.whatsapp)} ${+s.pending_paise ? `· <span class="text-danger">${money(s.pending_paise)} due</span>` : ''}`, s.photo_url)}</button>`).join('') || '<div class="list-group-item text-muted">No match</div>';
        $$('[data-id]', sug).forEach((b) => b.onclick = () => { sug.innerHTML = ''; q.value = ''; history.replaceState(null, '', '#/collect?student=' + b.dataset.id); load(b.dataset.id); });
      }, 250);
    };
    const load = async (sid) => {
      const d = (await get(`/students/${sid}/dues`)).data;
      $('#who', v).innerHTML = `<div class="d-flex align-items-center gap-3 p-2 rounded bg-light">${person(d.student.name, `${esc(d.student.student_code)} · ${esc(d.student.whatsapp)}`)}<a class="ms-auto small" href="#/students/${sid}">Profile</a></div>
        <div class="row text-center mt-3 g-2"><div class="col"><div class="small text-muted">Pending</div><div class="fs-5 fw-bold text-danger money">${money(d.balance.outstanding_paise)}</div></div><div class="col"><div class="small text-muted">Overdue</div><div class="fs-5 fw-bold money">${money(d.balance.overdue_paise)}</div></div><div class="col"><div class="small text-muted">Advance</div><div class="fs-5 fw-bold text-success money">${money(d.balance.advance_paise)}</div></div></div>
        ${d.recent_payments.length ? `<div class="small text-muted mt-3">Last payment: ${money(d.recent_payments[0].amount_paise)} on ${date(d.recent_payments[0].paid_on)} (${esc(d.recent_payments[0].receipt_no || '')})</div>` : ''}`;
      const idem = C.uuid();
      $('#pay', v).innerHTML = `<form class="card" id="pf"><div class="card-body">
        ${d.invoices.length ? `<label class="form-label">Pay against</label><div class="list-group mb-3">${d.invoices.map((i) => `<label class="list-group-item d-flex gap-3 align-items-center"><input class="form-check-input m-0" type="checkbox" value="${i.id}" data-bal="${i.balance_paise}" checked><div class="flex-grow-1"><div class="fw-bold">${esc(i.title)}</div><div class="small text-muted">${esc(i.invoice_no)} · due ${date(i.due_date)} ${i.status === 'partial' ? '· partly paid' : ''}</div></div><div class="money fw-bold">${money(i.balance_paise)}</div></label>`).join('')}</div>` : '<div class="alert alert-success py-2"><i class="bi bi-check-circle"></i> No pending dues. Any amount collected now is kept as advance.</div>'}
        <div class="row g-3"><div class="col-md-6" data-field="amount"><label class="form-label">Amount received <span class="text-danger">*</span></label><div class="input-group input-group-lg"><span class="input-group-text">₹</span><input class="form-control fw-bold" name="amount" inputmode="decimal" required></div><div class="invalid-feedback"></div><div class="form-text" id="amtHint"></div></div>
        <div class="col-md-6" data-field="paid_on"><label class="form-label">Date</label><input type="date" class="form-control form-control-lg" name="paid_on" value="${C.today()}" max="${C.today()}"><div class="invalid-feedback"></div></div>
        <div class="col-12" data-field="method"><label class="form-label">Payment method</label><div class="d-flex flex-wrap gap-2">${[['cash', 'bi-cash', 'Cash'], ['upi', 'bi-qr-code', 'UPI'], ['bank_transfer', 'bi-bank', 'Bank'], ['cheque', 'bi-journal-text', 'Cheque'], ['card', 'bi-credit-card', 'Card'], ['other', 'bi-three-dots', 'Other']].map(([k, ic, l], i) => `<input type="radio" class="btn-check" name="method" id="m_${k}" value="${k}" ${i === 0 ? 'checked' : ''}><label class="btn btn-outline-primary" for="m_${k}"><i class="bi ${ic}"></i> ${l}</label>`).join('')}</div></div>
        <div class="col-md-6 d-none" id="refBox" data-field="reference"><label class="form-label">Transaction / cheque no.</label><input class="form-control" name="reference"><div class="invalid-feedback"></div></div>
        <div class="col-md-6" data-field="notes"><label class="form-label">Note (optional)</label><input class="form-control" name="notes"></div></div>
        <button class="btn btn-primary btn-lg w-100 mt-4" id="paybtn"><i class="bi bi-check2-circle"></i> Save &amp; generate receipt</button></div></form>`;
      const f = $('#pf', v), amt = $('[name=amount]', f);
      const recalc = () => { const sum = $$('input[type=checkbox]:checked', f).reduce((a, c) => a + +c.dataset.bal, 0); amt.value = sum ? rupees(sum) : ''; hint(); };
      const hint = () => {
        const sel = $$('input[type=checkbox]:checked', f).reduce((a, c) => a + +c.dataset.bal, 0);
        const a = Math.round(parseFloat(amt.value || '0') * 100);
        $('#amtHint', v).innerHTML = !a ? '' : a < sel ? `Partial payment · ${money(sel - a)} will remain pending` : a > d.balance.outstanding_paise ? `<span class="text-success">${money(a - d.balance.outstanding_paise)} will be kept as advance</span>` : 'Full payment';
      };
      $$('input[type=checkbox]', f).forEach((c) => c.onchange = recalc);
      amt.oninput = hint;
      recalc();
      $$('[name=method]', f).forEach((r) => r.onchange = () => $('#refBox', v).classList.toggle('d-none', ['cash'].includes(r.value) && r.checked));
      f.onsubmit = async (e) => {
        e.preventDefault();
        const data = readForm(f);
        data.method = $('[name=method]:checked', f).value;
        data.student_id = +sid;
        data.invoice_ids = $$('input[type=checkbox]:checked', f).map((c) => +c.value);
        const btn = $('#paybtn', v);
        btn.disabled = true;
        try {
          const r = await post('/payments', data, { idempotency: idem });
          receiptModal(r.data, () => load(sid));
        } catch (er) { showErrors(f, er.errors); fail(er); btn.disabled = false; }
      };
      amt.focus();
    };
    if (p.student) load(p.student); else q.focus();
  }, 'Collect Fee');

  function receiptModal(pay, after) {
    const m = modal({
      title: 'Payment saved ✅', body: `<div class="text-center"><div class="display-6 fw-bold text-success">${money(pay.amount_paise)}</div><div class="text-muted">${esc(pay.student_name)} · ${label(pay.method)}</div><div class="mt-2">Receipt <strong>${esc(pay.receipt_no)}</strong></div><div class="small text-muted">Balance after payment: ${money(pay.balance_after_paise)}</div>${pay.duplicate ? '<div class="alert alert-warning mt-2 py-2 small">This payment was already saved earlier — no duplicate was created.</div>' : ''}</div>
        <div class="d-grid gap-2 mt-4"><button class="btn btn-primary" data-r="pdf"><i class="bi bi-download"></i> Download PDF</button><button class="btn btn-light" data-r="print"><i class="bi bi-printer"></i> Print</button><button class="btn btn-success" data-r="wa"><i class="bi bi-whatsapp"></i> Share on WhatsApp</button>${has('email') ? '<button class="btn btn-light" data-r="email"><i class="bi bi-envelope"></i> Email receipt</button>' : ''}</div>`,
      footer: '<button class="btn btn-light" data-bs-dismiss="modal">Done</button>', onClose: after,
    });
    $$('[data-r]', m.el).forEach((b) => b.onclick = async () => {
      if (b.dataset.r === 'pdf') return C.download(`/receipts/${pay.receipt_id}/pdf?download=1`, pay.receipt_no.replace(/\//g, '-') + '.pdf');
      if (b.dataset.r === 'print') return window.open(pay.receipt_url + '&pdf=1', '_blank');
      try {
        if (b.dataset.r === 'wa') { const r = await post(`/payments/${pay.id}/share`, { channel: 'link' }); window.open(r.data.whatsapp_link, '_blank'); }
        else { const r = await post(`/payments/${pay.id}/share`, { channel: 'email' }); toast(r.message); }
      } catch (e) { fail(e); }
    });
  }

  const paymentColumns = [
    { label: 'Date', render: (p) => `<strong>${date(p.paid_on)}</strong>` },
    { label: 'Receipt', render: (p) => `${esc(p.receipt_no || '–')}<div class="small text-muted">${esc(p.fee_period || '')}</div>` },
    { label: 'Student', render: (p) => `${esc(p.student_name)} <span class="small text-muted">${esc(p.student_code)}</span>` },
    { label: 'Method', render: (p) => label(p.method) + (p.reference ? `<div class="small text-muted">${esc(p.reference)}</div>` : '') },
    { label: 'Amount', cls: 'text-end', render: (p) => `<span class="money fw-bold ${p.status === 'reversed' ? 'text-decoration-line-through text-muted' : ''}">${money(p.amount_paise)}</span>${+p.refunded_paise ? `<div class="small text-danger">refunded ${money(p.refunded_paise)}</div>` : ''}` },
    { label: 'Status', render: (p) => badge(p.status) }, { label: 'By', render: (p) => `<span class="small">${esc(p.collected_by_name || 'Online')}</span>` },
  ];
  function paymentsTable(rows) {
    return table(paymentColumns, rows, { empty: 'No payments found.', onRow: () => {}, emptyIcon: 'bi-receipt' });
  }
  function bindPaymentRows(el, rows, reload) { bindRows(el, rows, (p) => paymentDetail(p.id, reload)); }
  async function paymentDetail(id, reload) {
    const p = (await get('/payments/' + id)).data;
    const m = modal({
      title: 'Payment ' + (p.receipt_no || ''), body: `<dl class="kv">${[['Student', esc(p.student_name) + ' (' + esc(p.student_code) + ')'], ['Amount', money(p.amount_paise)], ['Date', date(p.paid_on)], ['Method', label(p.method)], ['Reference', esc(p.reference || '–')], ['Status', badge(p.status)], ['Collected by', esc(p.collected_by_name || 'Online')], ['Balance after', money(p.balance_after_paise)], ...(+p.refunded_paise ? [['Refunded', money(p.refunded_paise)]] : []), ...(p.reversal_reason ? [['Reversal reason', esc(p.reversal_reason)]] : [])].map(([k, x]) => `<dt>${k}</dt><dd>${x}</dd>`).join('')}</dl>
        ${p.allocations.length ? `<h6 class="fw-bold mt-3">Applied to</h6>${p.allocations.map((a) => `<div class="d-flex small"><span>${esc(a.title)}</span><span class="ms-auto money">${money(a.amount_paise)}</span></div>`).join('')}` : ''}`,
      footer: `${p.status === 'success' && can('payments.reverse') ? '<button class="btn btn-outline-danger me-auto" data-x="rev">Reverse</button><button class="btn btn-outline-secondary" data-x="ref">Refund</button>' : ''}<button class="btn btn-success" data-x="wa"><i class="bi bi-whatsapp"></i></button><button class="btn btn-primary" data-x="pdf"><i class="bi bi-download"></i> Receipt</button>`,
    });
    $$('[data-x]', m.el).forEach((b) => b.onclick = async () => {
      try {
        if (b.dataset.x === 'pdf') return C.download(`/receipts/${p.receipt_id}/pdf?download=1`, (p.receipt_no || 'receipt').replace(/\//g, '-') + '.pdf');
        if (b.dataset.x === 'wa') { const r = await post(`/payments/${p.id}/share`, { channel: 'link' }); return window.open(r.data.whatsapp_link, '_blank'); }
        if (b.dataset.x === 'rev') {
          const reason = await confirm('Reverse this payment? The dues will be re-opened and the receipt marked cancelled. Records are never deleted.', { input: 'Reason (e.g. entered by mistake, cheque bounced)', danger: true, ok: 'Reverse payment' });
          if (!reason) return;
          const r = await post(`/payments/${p.id}/reverse`, { reason });
          toast(r.message); m.close(); reload && reload();
        }
        if (b.dataset.x === 'ref') {
          m.close();
          formModal({ title: 'Refund', fields: [{ name: 'amount', label: 'Refund amount', type: 'money', required: true, value: rupees(p.amount_paise - p.refunded_paise) }, { name: 'method', label: 'Refunded by', type: 'select', placeholder: false, options: [['cash', 'Cash'], ['upi', 'UPI'], ['bank_transfer', 'Bank transfer'], ['cheque', 'Cheque']] }, { name: 'reference', label: 'Reference' }, { name: 'reason', label: 'Reason', required: true }], submit: async (d) => { const r = await post(`/payments/${p.id}/refund`, d); toast(r.message); reload && reload(); } });
        }
      } catch (e) { fail(e); }
    });
  }

  // ===================================================================== FEES
  route('/fees', async (v, p) => {
    await lookups();
    v.innerHTML = head('Pending Fees', '', `${has('manual_reminders') && can('notifications.send') ? '<button class="btn btn-success" id="remAll"><i class="bi bi-whatsapp"></i> Remind all overdue</button>' : ''}${can('fees.manage') ? '<a class="btn btn-light" href="#/fee-plans">Fee plans</a>' : ''}`) +
      `<div class="alert alert-light border d-flex align-items-center gap-2" id="tot"></div><div id="fl"></div>`;
    const lv = listView({
      el: $('#fl', v), endpoint: '/invoices', params: { status: p.status || 'open' }, searchPlaceholder: 'Search student or invoice…',
      filters: [{ name: 'status', all: false, options: [['open', 'All pending'], ['overdue', 'Overdue'], ['paid', 'Paid'], ['cancelled', 'Cancelled'], ['all', 'All invoices']] }, ...(LK.branches.length ? [{ name: 'branch_id', placeholder: 'All branches', options: branchOpts() }] : []), { name: 'batch_id', placeholder: 'All batches', options: batchOpts() }],
      columns: [
        { label: 'Student', render: (i) => `<div class="fw-bold">${esc(i.student_name)}</div><div class="small text-muted">${esc(i.student_code)} · ${esc(i.whatsapp)}</div>` },
        { label: 'Fee', render: (i) => `${esc(i.title)}<div class="small text-muted">${esc(i.invoice_no)}${i.branch_name ? ' · ' + esc(i.branch_name) : ''}</div>` },
        { label: 'Due', render: (i) => `${date(i.due_date)}${+i.days_overdue && ['unpaid', 'partial'].includes(i.status) ? `<div class="small text-danger">${i.days_overdue} days overdue</div>` : ''}` },
        { label: 'Balance', cls: 'text-end', render: (i) => `<span class="money fw-bold">${money(i.balance_paise)}</span><div class="small text-muted">of ${money(i.total_paise)}</div>` },
        { label: 'Status', render: (i) => badge(i.status) },
        { label: '', cls: 'text-end', render: (i) => ['unpaid', 'partial'].includes(i.status) && can('fees.collect') ? `<a class="btn btn-sm btn-primary" href="#/collect?student=${i.student_id}">Collect</a>` : '' },
      ], onRow: (i) => go(`#/students/${i.student_id}?tab=fees`), empty: 'No pending fees 🎉', emptyIcon: 'bi-emoji-smile',
      after: (j) => { $('#tot', v).innerHTML = `<i class="bi bi-wallet2 fs-4 text-danger"></i> Total pending in this view: <strong class="money">${money(j.meta.total_balance_paise)}</strong> · ${j.meta.total} invoice(s)`; },
    });
    $('#remAll', v) && ($('#remAll', v).onclick = async () => {
      if (!(await confirm('Send a fee reminder to every student with overdue fees? Each family receives their own amount.', { ok: 'Send reminders' }))) return;
      try {
        const r = await post('/messages/send', { target: 'dues', overdue_only: 1, type: 'fee_reminder', channels: ['whatsapp', 'inapp', 'push', 'sms'], client_ref: C.uuid() }); toast(r.message);
        // manual WhatsApp mode: prepare one click-to-send message per family (bulk reminder preparation)
        if (has('whatsapp_manual') && !has('whatsapp')) waPrepare({ purpose: 'fee_reminder', target: 'dues', overdue_only: 1, branch_id: lv.state.branch_id || '' }, 'Overdue fee reminders');
      } catch (e) { fail(e); }
    });
  }, 'Pending Fees');

  route('/payments', async (v) => {
    await lookups();
    v.innerHTML = head('Payment History') + '<div class="alert alert-light border" id="tot"></div><div id="pl"></div>';
    const lv = listView({
      el: $('#pl', v), endpoint: '/payments', params: { from: C.month() + '-01', to: C.today() }, searchPlaceholder: 'Student, receipt no, reference…',
      filters: [{ name: 'from', type: 'date' }, { name: 'to', type: 'date' }, ...(LK.branches.length ? [{ name: 'branch_id', placeholder: 'All branches', options: branchOpts() }] : []), { name: 'method', placeholder: 'All methods', options: [['cash', 'Cash'], ['upi', 'UPI'], ['bank_transfer', 'Bank'], ['online', 'Online'], ['cheque', 'Cheque'], ['card', 'Card'], ['other', 'Other']] }, { name: 'status', placeholder: 'Any status', options: [['success', 'Successful'], ['reversed', 'Reversed']] }, { name: 'batch_id', placeholder: 'All batches', options: batchOpts() }, { name: 'activity_id', placeholder: 'All activities', options: actOpts() }],
      columns: paymentColumns, empty: 'No payments in this period.', emptyIcon: 'bi-receipt',
      onRow: (x) => paymentDetail(x.id, () => lv.reload()),
      after: (j) => { $('#tot', v).innerHTML = `Net collected: <strong class="money">${money(j.meta.total_paise)}</strong> · ${j.meta.total} payment(s)`; },
    });
  }, 'Payments');

  // ============================================================ MESSAGES
  function messageModal(preset = {}, who = '') {
    const chans = ['whatsapp', 'inapp', 'push', 'email', 'sms'].filter(has);
    const manualWa = has('whatsapp_manual');
    formModal({
      title: who ? 'Message ' + who : 'Send message', okText: 'Send',
      fields: [
        { name: 'type', label: 'What to send', type: 'select', placeholder: false, options: [['message', 'A message'], ...(has('fees') ? [['fee_reminder', 'Fee reminder (each family gets their own amount)']] : [])] },
        { name: 'title', label: 'Title (app / email)', placeholder: 'Optional' },
        { name: 'message', label: 'Message', type: 'textarea', rows: 4, placeholder: 'Type your message…' },
        { name: 'channels', label: 'Send via', type: 'multicheck', options: chans.map((c) => [c, label(c === 'inapp' ? 'App inbox' : c)]), value: chans.filter((c) => c !== 'email' && c !== 'sms') },
      ],
      submit: async (d) => { const r = await post('/messages/send', { ...preset, ...d, client_ref: C.uuid() }); toast(r.message); },
    });
  }
  route('/messages', async (v) => {
    await lookups();
    const chans = ['whatsapp', 'inapp', 'push', 'email', 'sms'].filter(has);
    const manualWa = has('whatsapp_manual');
    const tpl = [
      ['Fee reminder', '', 'fee_reminder'],
      ['Class cancelled', 'Dear Parent, today\'s class is cancelled. Sorry for the inconvenience.'],
      ['Holiday', 'Dear Parent, the academy will remain closed tomorrow on account of a holiday.'],
      ['Timing change', 'Dear Parent, please note the class timing has changed from tomorrow.'],
      ['Event', 'Dear Parent, we are happy to announce an upcoming event. Details will be shared soon.'],
    ];
    v.innerHTML = head('Reminders & Messages', has('whatsapp') ? 'Send to one student, a batch, or everyone. WhatsApp goes out automatically through the official WhatsApp Business API.' : (manualWa ? 'Prepare messages, then tap Send on each one to open it in WhatsApp / WhatsApp Business.' : 'Send to one student, a batch, or everyone.'), `${manualWa ? '<a class="btn btn-light" href="#/whatsapp-log"><i class="bi bi-whatsapp"></i> WhatsApp history</a>' : ''}<a class="btn btn-light" href="#/message-log"><i class="bi bi-list-check"></i> Delivery log</a>`) + `
      <div class="row g-3"><div class="col-lg-8"><form class="card" id="mf"><div class="card-body">
        <div class="mb-3"><label class="form-label">Quick templates</label><div class="d-flex flex-wrap gap-2">${tpl.map(([n], i) => `<button type="button" class="btn btn-sm btn-light" data-tpl="${i}">${n}</button>`).join('')}</div></div>
        ${form([
          { name: 'target', label: 'Send to', type: 'select', placeholder: false, options: [['batch', 'A batch'], ['activity', 'An activity'], ['students', 'Selected students'], ...(OWNER ? [['all', 'All active students']] : []), ...(has('fees') ? [['dues', 'Students with pending fees']] : [])], col: 'col-md-6' },
          { name: 'batch_id', label: 'Batch', type: 'select', options: batchOpts(), col: 'col-md-6' },
          { name: 'activity_id', label: 'Activity', type: 'select', options: actOpts(), col: 'col-md-6' },
          { name: 'students_pick', label: 'Students', type: 'html', col: 'col-12', html: '<label class="form-label">Students</label><input class="form-control" id="spq" placeholder="Type a name and pick…"><div class="list-group mt-1" id="sps"></div><div class="d-flex flex-wrap gap-1 mt-2" id="spl"></div>' },
          { name: 'overdue_only', label: 'Only overdue', type: 'switch', col: 'col-md-6' },
          { name: 'type', label: 'Type', type: 'select', placeholder: false, options: [['message', 'Message'], ...(has('fees') ? [['fee_reminder', 'Personalised fee reminder']] : [])], col: 'col-md-6' },
          { name: 'title', label: 'Title (app & email)', col: 'col-12' },
          { name: 'message', label: 'Message', type: 'textarea', rows: 5, help: '<span id="cc">0</span>/1000 characters. WhatsApp uses your approved template; the text goes into the message body.' },
          { name: 'channels', label: 'Send via', type: 'multicheck', options: [...chans.map((c) => [c, c === 'inapp' ? 'App inbox' : c === 'whatsapp' ? 'WhatsApp (automatic)' : label(c)]), ...(manualWa ? [['wa_manual', 'WhatsApp – click to send']] : [])], value: [...chans.filter((c) => !['email', 'sms'].includes(c)), ...(manualWa && !has('whatsapp') ? ['wa_manual'] : [])] },
          ...(chans.some((c) => c !== 'inapp') ? [{ name: 'send_at', label: 'Schedule (optional)', type: 'datetime-local', col: 'col-md-6', help: 'Leave empty to send now. Automatic channels only.' }] : []),
        ])}
        <button class="btn btn-primary btn-lg mt-3 px-5"><i class="bi bi-send"></i> Send</button></div></form></div>
        <div class="col-lg-4"><div class="card"><div class="card-header"><i class="bi bi-robot text-primary"></i> Automatic reminders</div><div class="card-body small">
          ${has('auto_reminders') ? 'Fee reminders go out automatically before and after each due date. <a href="#/settings?tab=notify">Change schedule</a>' : 'Automatic reminders are not included in your plan. <a href="#/subscription">Upgrade</a>'}
        </div></div></div></div>`;
    const f = $('#mf', v);
    C.bindMulti(f);
    const picked = new Map();
    const vis = () => {
      const t = $('[name=target]', f).value, ty = $('[name=type]', f).value;
      $('[data-field=batch_id]', f).classList.toggle('d-none', t !== 'batch');
      $('[data-field=activity_id]', f).classList.toggle('d-none', t !== 'activity');
      $('#spq', f).closest('div.col-12').classList.toggle('d-none', t !== 'students');
      $('[data-field=overdue_only]', f).classList.toggle('d-none', t !== 'dues');
      ['title', 'message'].forEach((k) => $(`[data-field=${k}]`, f).classList.toggle('d-none', ty === 'fee_reminder'));
    };
    $('[name=target]', f).onchange = vis; $('[name=type]', f).onchange = vis; vis();
    $('[name=message]', f).oninput = (e) => ($('#cc', f).textContent = e.target.value.length);
    $$('[data-tpl]', f).forEach((b) => b.onclick = () => { const t = tpl[+b.dataset.tpl]; if (t[2]) { $('[name=type]', f).value = 'fee_reminder'; $('[name=target]', f).value = 'dues'; } else { $('[name=type]', f).value = 'message'; $('[name=message]', f).value = t[1]; $('[name=title]', f).value = t[0]; } vis(); });
    let tt;
    $('#spq', f).oninput = (e) => { clearTimeout(tt); tt = setTimeout(async () => { const q = e.target.value.trim(); if (q.length < 2) return ($('#sps', f).innerHTML = ''); const r = await get('/students' + qs({ q, per_page: 6 })); $('#sps', f).innerHTML = r.data.map((s) => `<button type="button" class="list-group-item list-group-item-action py-1" data-id="${s.id}" data-n="${esc(s.name)}">${esc(s.name)} <span class="small text-muted">${esc(s.student_code)}</span></button>`).join(''); $$('[data-id]', $('#sps', f)).forEach((b) => b.onclick = () => { picked.set(b.dataset.id, b.dataset.n); drawPicked(); $('#sps', f).innerHTML = ''; $('#spq', f).value = ''; }); }, 250); };
    const drawPicked = () => { $('#spl', f).innerHTML = [...picked].map(([id, n]) => `<span class="badge b-info">${esc(n)} <a href="javascript:" data-rm="${id}" class="text-reset ms-1">×</a></span>`).join(''); $$('[data-rm]', f).forEach((a) => a.onclick = () => { picked.delete(a.dataset.rm); drawPicked(); }); };
    f.onsubmit = async (e) => {
      e.preventDefault();
      const d = readForm(f);
      d.student_ids = [...picked.keys()].map(Number);
      d.client_ref = C.uuid();
      const chs = [].concat(d.channels || []);
      const manual = chs.includes('wa_manual');
      d.channels = chs.filter((c) => c !== 'wa_manual');
      if (!manual && !d.channels.length) return toast('Choose at least one way to send.', 'warning');
      if (!(await confirm(d.send_at ? 'Schedule this message?' : 'Send now?', { ok: d.send_at ? 'Schedule' : 'Send' }))) return;
      try {
        if (d.channels.length) { const r = await post('/messages/send', d); toast(r.message + ' ' + Object.entries(r.data).filter(([, n]) => n).map(([k, n]) => `${label(k)}: ${n}`).join(', ')); }
        if (manual) {
          const r = await post('/whatsapp/manual/prepare', { purpose: d.type === 'fee_reminder' ? 'fee_reminder' : 'custom', target: d.target, batch_id: d.batch_id, activity_id: d.activity_id, student_ids: d.student_ids, overdue_only: d.overdue_only, message: d.message });
          waSend(r.data.items, d.type === 'fee_reminder' ? 'Fee reminders' : 'Message');
        }
        f.reset(); picked.clear(); drawPicked(); vis();
      } catch (er) { showErrors(f, er.errors); fail(er); }
    };
  }, 'Reminders & Messages');

  // Click-to-send WhatsApp: one row per message, Send opens WhatsApp with the text ready; the user presses send there.
  function waSend(items, title = 'WhatsApp') {
    if (!items?.length) return toast('Nobody to message.', 'warning');
    let done = 0;
    const m = modal({
      title: `${title} – ${items.length} message(s)`, size: 'lg',
      body: `<p class="small text-muted">Tap <b>Send</b> to open WhatsApp with the message ready, then press send in WhatsApp. Come back here for the next one.</p>
        <div class="progress mb-3" style="height:6px"><div class="progress-bar bg-success" id="wp" style="width:0"></div></div>
        <div class="list-group">${items.map((x, i) => `<div class="list-group-item d-flex gap-2 align-items-start" data-i="${i}"><div class="flex-grow-1"><div class="fw-bold">${esc(x.student_name)} <span class="small text-muted fw-normal">to ${esc(x.name || '')} ${esc(x.phone)}</span></div><div class="small text-muted" style="white-space:pre-wrap">${esc(x.text)}</div></div>
          <a class="btn btn-success btn-sm" target="_blank" rel="noopener" href="${esc(x.link)}" data-send="${i}"><i class="bi bi-whatsapp"></i> Send</a></div>`).join('')}</div>`,
      footer: '<span class="small me-auto" id="wn">0 sent</span><button class="btn btn-light" data-bs-dismiss="modal">Done</button>',
    });
    $$('[data-send]', m.el).forEach((a) => a.addEventListener('click', () => {
      const x = items[+a.dataset.send];
      if (!a.classList.contains('btn-outline-success')) { done++; a.className = 'btn btn-outline-success btn-sm'; a.innerHTML = '<i class="bi bi-check2"></i> Sent'; }
      $('#wn', m.el).textContent = `${done} of ${items.length} opened in WhatsApp`;
      $('#wp', m.el).style.width = Math.round((done * 100) / items.length) + '%';
      post(`/whatsapp/manual/${x.id}/opened`, {}).catch(() => {});
    }));
  }
  C.waSend = waSend;
  async function waPrepare(body, title) {
    try { const r = await post('/whatsapp/manual/prepare', body); waSend(r.data.items, title); } catch (e) { fail(e); }
  }

  route('/whatsapp-log', async (v) => {
    v.innerHTML = head('WhatsApp history', 'Messages prepared for click-to-send. “Opened” means Send was tapped and WhatsApp opened.') + '<div id="wl"></div>';
    await lookups();
    listView({
      el: $('#wl', v), endpoint: '/whatsapp/manual/log', search: false, params: { from: '', to: '' },
      filters: [{ name: 'purpose', placeholder: 'All types', options: [['fee_reminder', 'Fee reminder'], ['receipt', 'Receipt'], ['attendance', 'Attendance'], ['admission', 'Admission'], ['announcement', 'Announcement'], ['custom', 'Custom']] },
        { name: 'status', placeholder: 'Any status', options: [['prepared', 'Prepared'], ['opened', 'Opened in WhatsApp']] }, ...(LK.branches.length ? [{ name: 'branch_id', placeholder: 'All branches', options: branchOpts() }] : []),
        { name: 'from', type: 'date' }, { name: 'to', type: 'date' }],
      columns: [
        { label: 'When', render: (q) => dateTime(q.created_at) }, { label: 'Type', render: (q) => label(q.purpose) },
        { label: 'To', render: (q) => `${esc(q.student_name || q.recipient_name || '')}<div class="small text-muted">${esc(q.recipient_name || '')} · +${esc(q.phone)}${q.branch_name ? ' · ' + esc(q.branch_name) : ''}</div>` },
        { label: 'Message', render: (q) => `<div class="small text-muted" style="max-width:360px;white-space:pre-wrap">${esc(q.message.slice(0, 160))}</div>` },
        { label: 'Status', render: (q) => (q.status === 'opened' ? badge('sent') + `<div class="small text-muted">${dateTime(q.opened_at)}</div>` : badge('pending')) + `<div class="small text-muted">by ${esc(q.prepared_by || '')}</div>` },
        { label: '', render: (q) => `<a class="btn btn-sm btn-light" target="_blank" rel="noopener" href="${esc(q.link)}" data-open="${q.id}"><i class="bi bi-whatsapp"></i> Send again</a>` },
      ], empty: 'No WhatsApp messages prepared in this period.',
      after: (_, el) => $$('[data-open]', el).forEach((a) => a.addEventListener('click', () => post(`/whatsapp/manual/${a.dataset.open}/opened`, {}).catch(() => {}))),
    });
  }, 'WhatsApp History');

  route('/message-log', async (v) => {
    v.innerHTML = head('Message delivery log', 'WhatsApp, push, email and SMS sent by your academy') + '<div id="ml"></div>';
    const lv = listView({
      el: $('#ml', v), endpoint: '/messages/log', search: false,
      filters: [{ name: 'channel', placeholder: 'All channels', options: [['whatsapp', 'WhatsApp'], ['push', 'Push'], ['email', 'Email'], ['sms', 'SMS']] }, { name: 'status', placeholder: 'Any status', options: [['pending', 'Pending'], ['sent', 'Sent'], ['delivered', 'Delivered'], ['read', 'Read'], ['failed', 'Failed']] }],
      columns: [
        { label: 'When', render: (q) => dateTime(q.created_at) }, { label: 'Type', render: (q) => label(q.event_key) },
        { label: 'To', render: (q) => `${esc(q.recipient_name || '')}<div class="small text-muted">${label(q.channel)} · ${esc(q.recipient)}</div>` },
        { label: 'Message', render: (q) => `<div class="small text-muted" style="max-width:340px">${esc(q.body.slice(0, 140))}</div>` },
        { label: 'Status', render: (q) => badge(q.status) + (q.last_error ? `<div class="small text-muted" style="max-width:220px">${esc(q.last_error)}</div>` : '') },
        { label: '', render: (q) => q.status === 'failed' ? `<button class="btn btn-sm btn-light" data-retry="${q.id}">Retry</button>` : '' },
      ], empty: 'Nothing sent yet.',
      after: (_, el) => $$('[data-retry]', el).forEach((b) => b.onclick = async () => { try { const r = await post('/messages/retry', { ids: [+b.dataset.retry] }); toast(r.message); lv.reload(); } catch (e) { fail(e); } }),
    });
  }, 'Message Log');

  // ============================================================ STRUCTURE
  route('/activities', async (v, p) => {
    const rows = (await get('/activities')).data;
    const teachers = OWNER ? (await get('/teachers')).data : [];
    v.innerHTML = head('Activities', 'Cricket, Dance, Yoga, Tuition… each with its own fees', OWNER ? '<button class="btn btn-primary" id="add"><i class="bi bi-plus-lg"></i> Add activity</button>' : '') + table([
      { label: 'Activity', render: (a) => `<div class="fw-bold">${esc(a.name)}</div><div class="small text-muted">${esc(a.description || '')}</div>` },
      { label: 'Monthly fee', cls: 'text-end', render: (a) => `<span class="money">${money(a.monthly_fee_paise)}</span>` },
      { label: 'Registration', cls: 'text-end', render: (a) => `<span class="money">${money(a.registration_fee_paise)}</span>` },
      { label: 'Batches', render: (a) => `<a href="#/batches?activity_id=${a.id}">${a.batch_count}</a>` }, { label: 'Students', render: (a) => `<a href="#/students?activity_id=${a.id}">${a.student_count}</a>` },
      { label: 'Instructor', render: (a) => esc(a.instructor_name || '–') }, { label: 'Status', render: (a) => badge(a.status) },
    ], rows, { empty: 'No activities yet. Add your first activity (e.g. Cricket).', onRow: OWNER ? (a) => edit(a) : null, emptyIcon: 'bi-collection' });
    const edit = (a = {}) => formModal({
      title: a.id ? 'Edit activity' : 'Add activity', values: { ...a, monthly_fee: a.id ? rupees(a.monthly_fee_paise) : '', registration_fee: a.id ? rupees(a.registration_fee_paise) : '', one_time_fee: a.id ? rupees(a.one_time_fee_paise) : '' },
      fields: [
        { name: 'name', label: 'Activity name', required: true, placeholder: 'e.g. Cricket, Kathak, Yoga, Maths Tuition' },
        { name: 'monthly_fee', label: 'Monthly fee', type: 'money', col: 'col-md-4' }, { name: 'registration_fee', label: 'Registration fee', type: 'money', col: 'col-md-4' }, { name: 'one_time_fee', label: 'One-time fee (kit etc.)', type: 'money', col: 'col-md-4' },
        { name: 'duration', label: 'Duration', placeholder: 'e.g. 3 months, Ongoing', col: 'col-md-6' }, { name: 'instructor_id', label: 'Main instructor', type: 'select', options: teachers.map((t) => [t.id, t.name]), col: 'col-md-6' },
        { name: 'description', label: 'Description', type: 'textarea', rows: 2 },
        ...(a.id ? [{ name: 'status', label: 'Status', type: 'select', placeholder: false, options: [['active', 'Active'], ['inactive', 'Inactive']] }] : []),
      ],
      submit: async (d) => { const r = a.id ? await put('/activities/' + a.id, d) : await post('/activities', d); toast(r.message); LK = null; C.dispatch(); },
    });
    if (OWNER) bindRows(v, rows, edit);
    $('#add', v) && ($('#add', v).onclick = () => edit());
    if (p.new) edit();
  }, 'Activities');

  route('/batches', async (v, p) => {
    await lookups(true);
    const all = (await get('/batches' + qs({ activity_id: p.activity_id || '' }))).data;
    const teachers = OWNER ? (await get('/teachers')).data : [];
    v.innerHTML = head('Batches', 'Timings, weekly schedule and teacher for each group', OWNER ? '<button class="btn btn-primary" id="add"><i class="bi bi-plus-lg"></i> Add batch</button>' : '') + table([
      { label: 'Batch', render: (b) => `<div class="fw-bold">${esc(b.name)}</div><div class="small text-muted">${esc(b.activity_name)}${b.location ? ' · ' + esc(b.location) : ''}</div>` },
      { label: 'Timing', render: (b) => b.start_time ? `${time(b.start_time)} – ${time(b.end_time)}` : '–' },
      { label: 'Days', render: (b) => b.weekdays.length === 7 ? 'Every day' : b.weekdays.map((d) => WEEK[d]).join(', ') || '–' },
      { label: 'Teacher', render: (b) => esc(b.teacher_name || '–') },
      { label: 'Students', render: (b) => `<a href="#/students?batch_id=${b.id}">${b.student_count}${b.capacity ? ' / ' + b.capacity : ''}</a>` },
      { label: 'Fee', cls: 'text-end', render: (b) => b.monthly_fee_paise !== null ? `<span class="money">${money(b.monthly_fee_paise)}</span>` : '<span class="small text-muted">Activity fee</span>' },
      { label: '', cls: 'text-end', render: (b) => `${badge(b.status)} ${has('attendance') ? `<a class="btn btn-sm btn-light" href="#/attendance/${b.id}">Attendance</a>` : ''}` },
    ], all, { empty: 'No batches yet.', onRow: OWNER ? (b) => edit(b) : null, emptyIcon: 'bi-collection' });
    const edit = (b = {}) => {
      const m = formModal({
        title: b.id ? 'Edit batch' : 'Add batch', size: 'lg', values: { ...b, monthly_fee: b.monthly_fee_paise !== null && b.monthly_fee_paise !== undefined ? rupees(b.monthly_fee_paise) : '', weekdays: (b.weekdays || [1, 2, 3, 4, 5, 6]).map(String) },
        fields: [
          { name: 'activity_id', label: 'Activity', type: 'select', required: true, options: actOpts(), col: 'col-md-6', value: b.activity_id || p.activity_id || (LK.activities.length === 1 ? LK.activities[0].id : '') },
          { name: 'name', label: 'Batch name', required: true, placeholder: 'e.g. Morning, U-14, Beginners', col: 'col-md-6' },
          { name: 'start_time', label: 'Start time', type: 'time', col: 'col-md-3' }, { name: 'end_time', label: 'End time', type: 'time', col: 'col-md-3' },
          { name: 'teacher_id', label: 'Teacher', type: 'select', options: teachers.map((t) => [t.id, t.name]), col: 'col-md-6' },
          { name: 'weekdays', label: 'Class days', type: 'multicheck', options: [1, 2, 3, 4, 5, 6, 7].map((d) => [String(d), WEEK[d]]) },
          { name: 'capacity', label: 'Capacity', type: 'number', col: 'col-md-3' }, { name: 'location', label: 'Location / ground', col: 'col-md-5' },
          { name: 'monthly_fee', label: 'Batch fee (blank = activity fee)', type: 'money', col: 'col-md-4' },
          { name: 'start_date', label: 'Start date', type: 'date', col: 'col-md-4' }, { name: 'end_date', label: 'End date', type: 'date', col: 'col-md-4' },
          ...(b.id ? [{ name: 'status', label: 'Status', type: 'select', placeholder: false, options: [['active', 'Active'], ['inactive', 'Inactive'], ['completed', 'Completed']], col: 'col-md-4' }] : []),
        ],
        submit: async (d) => { d.weekdays = (d.weekdays || []).map(Number); const r = b.id ? await put('/batches/' + b.id, d) : await post('/batches', d); toast(r.message); LK = null; C.dispatch(); },
      });
    };
    if (OWNER) bindRows(v, all, edit);
    $('#add', v) && ($('#add', v).onclick = () => edit());
  }, 'Batches');

  route('/timetable', async (v) => {
    const rows = (await get('/timetable')).data;
    const byDay = {};
    rows.forEach((r) => (byDay[r.weekday] ||= []).push(r));
    v.innerHTML = head('Timetable') + `<div class="row g-3">${[1, 2, 3, 4, 5, 6, 7].map((d) => `<div class="col-md-6 col-xl-3"><div class="card h-100"><div class="card-header ${+new Date().getDay() === d % 7 ? 'text-primary' : ''}">${WEEK[d]}</div><div class="card-body py-1">${(byDay[d] || []).map((r) => `<div class="list-tile"><div><div class="fw-bold small">${time(r.start_time)} – ${time(r.end_time)}</div><div>${esc(r.batch_name)} <span class="text-muted small">${esc(r.activity_name)}</span></div><div class="small text-muted">${esc(r.teacher_name || '')} ${r.location ? '· ' + esc(r.location) : ''}</div></div></div>`).join('') || '<div class="text-muted small py-3">No classes</div>'}</div></div></div>`).join('')}</div>`;
  }, 'Timetable');

  route('/teachers', async (v) => {
    const [rows, perms] = await Promise.all([get('/teachers'), get('/permissions')]);
    await lookups();
    v.innerHTML = head('Teachers', 'Teachers see only their own batches. Fee access is off unless you allow it.', '<button class="btn btn-primary" id="add"><i class="bi bi-person-plus"></i> Add teacher</button>') + table([
      { label: 'Teacher', render: (t) => person(t.name, `${esc(t.country_code)} ${esc(t.mobile)}`, t.photo_url) },
      { label: 'Batches', render: (t) => `<span class="small">${esc(t.batch_names || '–')}</span>` },
      { label: 'Extra rights', render: (t) => t.permissions.length ? t.permissions.map((x) => `<span class="badge b-purple me-1">${esc(x)}</span>`).join('') : '<span class="small text-muted">Default</span>' },
      { label: 'App login', render: (t) => badge(t.login_status || 'invited') }, { label: 'Status', render: (t) => badge(t.status) },
    ], rows.data, { empty: 'No teachers yet.', onRow: (t) => edit(t), emptyIcon: 'bi-person-badge' });
    const extra = perms.data.filter((p) => !['dashboard.view', 'students.view', 'attendance.mark'].includes(p.perm_key));
    const edit = (t = {}) => {
      const assigned = t.id ? LK.batches.filter((b) => String(b.teacher_id) === String(t.id) || (b.extra_teacher_ids || []).includes(+t.id)).map((b) => String(b.id)) : [];
      const m = formModal({
        title: t.id ? 'Edit teacher' : 'Add teacher', size: 'lg', values: { ...t, salary: t.salary_paise ? rupees(t.salary_paise) : '', batch_ids: assigned, permissions: t.permissions || [] },
        fields: [
          { name: 'name', label: 'Name', required: true, col: 'col-md-6' }, { name: 'mobile', label: 'Mobile (login)', type: 'phone', required: true, col: 'col-md-6' },
          { name: 'email', label: 'Email', type: 'email', col: 'col-md-6' }, { name: 'joining_date', label: 'Joining date', type: 'date', col: 'col-md-6' },
          { name: 'batch_ids', label: 'Assigned batches', type: 'multicheck', options: LK.batches.map((b) => [String(b.id), b.name + ' · ' + b.activity_name]) },
          { name: 'specialization', label: 'Speciality', col: 'col-md-6' }, { name: 'address', label: 'Address', col: 'col-md-6' },
          { name: 'salary_type', label: 'Payment type (optional)', type: 'select', options: [['none', 'Not tracked'], ['monthly', 'Monthly salary'], ['per_class', 'Per class'], ['per_student', 'Per student'], ['other', 'Other']], col: 'col-md-6' },
          { name: 'salary', label: 'Amount', type: 'money', col: 'col-md-6' },
          { name: 'permissions', label: 'Extra permissions', type: 'multicheck', options: extra.map((p) => [p.perm_key, p.name + (+p.is_financial ? ' 💰' : '')]), help: '💰 = gives access to money. Leave off unless needed.' },
          ...(t.id ? [{ name: 'status', label: 'Status', type: 'select', placeholder: false, options: [['active', 'Active'], ['inactive', 'Inactive (blocks login)']], col: 'col-md-6' }] : [{ name: 'password', label: 'Set password now (optional)', type: 'password', col: 'col-md-6', help: 'Leave blank to send an activation link instead.' }]),
        ],
        submit: async (d) => { const r = t.id ? await put('/teachers/' + t.id, d) : await post('/teachers', d); toast(r.message); if (r.data.invite?.link) inviteResult([{ name: d.name, role: 'teacher', ...r.data.invite }]); LK = null; C.dispatch(); },
      });
      if (t.id && t.login_status !== 'active') {
        const b = document.createElement('button'); b.className = 'btn btn-light me-auto'; b.innerHTML = '<i class="bi bi-send"></i> Resend invite';
        b.onclick = async () => { try { const r = await post(`/teachers/${t.id}/invite`); inviteResult([{ name: t.name, role: 'teacher', ...r.data }]); } catch (e) { fail(e); } };
        m.footer.prepend(b);
      }
    };
    bindRows(v, rows.data, edit);
    $('#add', v).onclick = () => edit();
  }, 'Teachers');

  route('/parents', async (v) => {
    v.innerHTML = head('Parents', 'Parents are created automatically when you register students. One parent can have several children.') + '<div id="pl"></div>';
    listView({
      el: $('#pl', v), endpoint: '/parents', searchPlaceholder: 'Search parent name or number…',
      columns: [{ label: 'Parent', render: (x) => `<div class="fw-bold">${esc(x.name)}</div>` }, { label: 'WhatsApp', render: (x) => wa(x.country_code, x.whatsapp) }, { label: 'Children', render: (x) => esc(x.children || '–') }, { label: 'App', render: (x) => x.login_status ? badge(x.login_status) : '<span class="small text-muted">Not invited</span>' }, { label: 'WhatsApp msgs', render: (x) => +x.whatsapp_opt_in ? 'On' : '<span class="text-danger">Off</span>' }],
      onRow: (x) => formModal({ title: 'Edit parent', values: x, fields: [{ name: 'name', label: 'Name', required: true }, { name: 'whatsapp', label: 'WhatsApp', col: 'col-6' }, { name: 'email', label: 'Email', type: 'email', col: 'col-6' }, { name: 'occupation', label: 'Occupation' }, { name: 'whatsapp_opt_in', label: 'Send WhatsApp messages', type: 'switch' }], submit: async (d) => { const r = await put('/parents/' + x.id, d); toast(r.message); C.dispatch(); } }),
      empty: 'No parents yet.',
    });
  }, 'Parents');

  route('/admissions', async (v, p) => {
    await lookups();
    const st = p.status || 'pending';
    const r = (await get('/admissions?status=' + st)).data;
    const share = `Register at ${ME.academy.name} online: ${r.link}`;
    v.innerHTML = head('Online Admissions', 'Share your registration link. New applications wait here for your approval.') + `
      <div class="card mb-3"><div class="card-body d-flex flex-wrap gap-2 align-items-center"><i class="bi bi-link-45deg fs-4 text-primary"></i><input class="form-control" style="max-width:480px" value="${esc(r.link)}" readonly id="lnk"><button class="btn btn-light" id="cp">Copy</button><a class="btn btn-success" target="_blank" href="https://wa.me/?text=${encodeURIComponent(share)}"><i class="bi bi-whatsapp"></i> Share</a><a class="btn btn-light" target="_blank" href="${esc(r.link)}">Open</a></div></div>
      <ul class="nav nav-pills mb-3">${['pending', 'approved', 'rejected', 'all'].map((s) => `<li><a class="nav-link ${s === st ? 'active' : ''}" href="#/admissions?status=${s}">${label(s)}</a></li>`).join('')}</ul>` +
      table([
        { label: 'Applicant', render: (a) => person(a.student_name, `Parent: ${esc(a.parent_name || '–')} · ${esc(a.whatsapp)}`, a.photo_url) },
        { label: 'Wants', render: (a) => `${esc(a.activity_name || '–')}<div class="small text-muted">${esc(a.batch_name || '')}</div>` },
        { label: 'Applied', render: (a) => dateTime(a.created_at) }, { label: 'Status', render: (a) => badge(a.status) },
        { label: '', cls: 'text-end', render: (a) => a.status === 'pending' ? `<button class="btn btn-sm btn-primary" data-ap="${a.id}">Approve</button> <button class="btn btn-sm btn-light" data-rj="${a.id}">Reject</button>` : (a.student_id ? `<a class="btn btn-sm btn-light" href="#/students/${a.student_id}">View student</a>` : '') },
      ], r.items, { empty: 'No applications here.', emptyIcon: 'bi-inbox' });
    $('#cp', v).onclick = () => { navigator.clipboard.writeText(r.link); toast('Link copied'); };
    $$('[data-ap]', v).forEach((b) => b.onclick = () => {
      const a = r.items.find((x) => String(x.id) === b.dataset.ap);
      const m = formModal({ title: 'Approve ' + a.student_name, okText: 'Approve & register', values: a, intro: a.message ? `<div class="alert alert-light small">${esc(a.message)}</div>` : '', fields: [
        { name: 'activity_id', label: 'Activity', type: 'select', required: true, options: actOpts(), value: a.activity_id }, { name: 'batch_id', label: 'Batch', type: 'select', options: [] , value: a.batch_id },
        { name: 'is_minor', label: 'Under 18', type: 'switch', value: 1 }, { name: 'guardian_name', label: 'Guardian name', value: a.parent_name },
      ], submit: async (d) => { const res = await post(`/admissions/${a.id}/approve`, d); toast(res.message); go('#/students/' + res.data.student.id); } });
      linkSelects(m.el);
      if (a.batch_id) $('[name=batch_id]', m.el).value = a.batch_id;
    });
    $$('[data-rj]', v).forEach((b) => b.onclick = async () => { if (!(await confirm('Reject this application?', { danger: true, ok: 'Reject' }))) return; await post(`/admissions/${b.dataset.rj}/reject`).then((x) => { toast(x.message); C.dispatch(); }).catch(fail); });
  }, 'Online Admissions');

  route('/announcements', async (v) => {
    await lookups();
    const rows = (await get('/announcements')).data;
    v.innerHTML = head('Announcements', '', can('notifications.send') ? '<button class="btn btn-primary" id="add"><i class="bi bi-megaphone"></i> New announcement</button>' : '') + (rows.length ? rows.map((a) => `<div class="card mb-2"><div class="card-body"><div class="d-flex"><div class="fw-bold">${esc(a.title)}</div><div class="ms-auto small text-muted">${dateTime(a.publish_at)} ${OWNER ? `<a href="javascript:" class="text-danger ms-2" data-del="${a.id}">Delete</a>` : ''}</div></div><div class="small text-muted mb-1">${label(a.audience)}${a.batch_name ? ': ' + esc(a.batch_name) : ''}</div><div style="white-space:pre-wrap">${esc(a.body)}</div></div></div>`).join('') : '<div class="card"><div class="empty"><i class="bi bi-megaphone"></i>No announcements yet.</div></div>');
    $('#add', v) && ($('#add', v).onclick = () => formModal({ title: 'New announcement', fields: [
      { name: 'title', label: 'Title', required: true }, { name: 'body', label: 'Message', type: 'textarea', rows: 5, required: true },
      { name: 'audience', label: 'For', type: 'select', placeholder: false, options: OWNER ? [['all', 'Everyone'], ['students', 'Students & parents'], ['teachers', 'Teachers'], ['batch', 'One batch']] : [['batch', 'One batch']], col: 'col-md-6' },
      { name: 'batch_id', label: 'Batch', type: 'select', options: batchOpts(), col: 'col-md-6' },
      { name: 'publish_at', label: 'Schedule (optional)', type: 'text', attrs: 'type="datetime-local"', col: 'col-md-6' }, { name: 'notify', label: 'Notify on WhatsApp / app', type: 'switch', value: 1, col: 'col-md-6' },
    ], submit: async (d) => {
      const r = await post('/announcements', d); toast(r.message);
      if (has('whatsapp_manual') && !has('whatsapp') && OWNER && r.data?.id && d.audience !== 'teachers' && (await confirm('Also prepare this announcement for WhatsApp (click to send)?', { ok: 'Prepare WhatsApp' }))) await waPrepare({ purpose: 'announcement', announcement_id: r.data.id }, 'Announcement');
      C.dispatch();
    } }));
    $$('[data-del]', v).forEach((b) => b.onclick = async () => { if (await confirm('Delete this announcement?', { danger: true })) await del('/announcements/' + b.dataset.del).then(() => C.dispatch()).catch(fail); });
  }, 'Announcements');

  route('/holidays', async (v) => {
    await lookups();
    const rows = (await get('/holidays')).data;
    v.innerHTML = head('Holidays', '', OWNER ? '<button class="btn btn-primary" id="add"><i class="bi bi-plus-lg"></i> Add holiday</button>' : '') + table([
      { label: 'Date', render: (h) => `<strong>${date(h.holiday_date)}</strong>` }, { label: 'Holiday', render: (h) => esc(h.title) }, { label: 'Applies to', render: (h) => esc(h.batch_name || 'Whole academy') },
      { label: '', cls: 'text-end', render: (h) => OWNER ? `<button class="btn btn-sm btn-link text-danger" data-del="${h.id}">Remove</button>` : '' },
    ], rows, { empty: 'No upcoming holidays.', emptyIcon: 'bi-calendar-heart' });
    $('#add', v) && ($('#add', v).onclick = () => formModal({ title: 'Add holiday', fields: [{ name: 'holiday_date', label: 'Date', type: 'date', required: true, col: 'col-6' }, { name: 'title', label: 'Occasion', required: true, col: 'col-6' }, { name: 'batch_id', label: 'Batch (blank = all)', type: 'select', options: batchOpts() }, { name: 'notify', label: 'Inform students & parents', type: 'switch', value: 1 }], submit: async (d) => { const r = await post('/holidays', d); toast(r.message); C.dispatch(); } }));
    $$('[data-del]', v).forEach((b) => b.onclick = async () => { await del('/holidays/' + b.dataset.del).then(() => C.dispatch()).catch(fail); });
  }, 'Holidays');

  route('/fee-plans', async (v) => {
    await lookups();
    const [rows, disc] = await Promise.all([get('/fee-structures'), get('/discounts')]);
    v.innerHTML = head('Fee Plans', 'Simple monthly fees can be set on the activity itself. Use plans for quarterly/yearly fees, batch-specific fees or extra charges.', '<button class="btn btn-primary" id="add"><i class="bi bi-plus-lg"></i> New fee plan</button>') + table([
      { label: 'Plan', render: (f) => `<div class="fw-bold">${esc(f.name)}</div><div class="small text-muted">${f.batch_name ? 'Batch: ' + esc(f.batch_name) : f.activity_name ? 'Activity: ' + esc(f.activity_name) : 'All students'}</div>` },
      { label: 'Type', render: (f) => f.fee_type === 'recurring' ? `Every ${f.frequency_months == 1 ? 'month' : f.frequency_months + ' months'}` : label(f.fee_type) },
      { label: 'Amount', cls: 'text-end', render: (f) => `<span class="money">${money(f.amount_paise)}</span>` }, { label: 'Due day', render: (f) => f.due_day },
      { label: 'Late fee', render: (f) => +f.late_fee_paise ? `${money(f.late_fee_paise)} after ${f.late_fee_after_days}d` : '–' },
      { label: 'Auto', render: (f) => +f.auto_assign ? 'New admissions' : 'Manual' }, { label: '', render: (f) => badge(f.status) },
    ], rows.data, { empty: 'No fee plans. Activity fees are used.', onRow: (f) => edit(f) }) + `
      <div class="page-head mt-4"><div><h1 class="h5">Discounts &amp; scholarships</h1></div><div class="actions"><button class="btn btn-light" id="addd"><i class="bi bi-plus-lg"></i> Add</button></div></div>` + table([
      { label: 'Name', render: (d) => `<strong>${esc(d.name)}</strong> <span class="small text-muted">${label(d.kind)}</span>` }, { label: 'Value', render: (d) => d.value_type === 'percent' ? d.value / 100 + '%' : money(d.value) }, { label: '', render: (d) => badge(d.status) },
    ], disc.data, { empty: 'No discounts defined.', onRow: (d) => editD(d) });
    bindRows(v.querySelectorAll('.table-wrap')[0], rows.data, (f) => edit(f));
    bindRows(v.querySelectorAll('.table-wrap')[1], disc.data, (d) => editD(d));
    const edit = (f = {}) => {
      const m = formModal({
        title: f.id ? 'Edit fee plan' : 'New fee plan', size: 'lg', values: { ...f, amount: f.id ? rupees(f.amount_paise) : '', late_fee: f.id ? rupees(f.late_fee_paise) : '', auto_assign: f.id ? f.auto_assign : 1 },
        fields: [
          { name: 'name', label: 'Plan name', required: true, placeholder: 'e.g. Quarterly coaching fee', col: 'col-md-6' },
          { name: 'fee_type', label: 'Type', type: 'select', required: true, placeholder: false, options: [['recurring', 'Recurring'], ['admission', 'Admission (one-time)'], ['registration', 'Registration (one-time)'], ['one_time', 'One-time'], ['additional', 'Additional charge']], col: 'col-md-6' },
          { name: 'frequency_months', label: 'Repeats every', type: 'select', placeholder: false, options: [[1, 'Month'], [3, 'Quarter'], [6, 'Half year'], [12, 'Year']], col: 'col-md-4' },
          { name: 'amount', label: 'Amount', type: 'money', required: true, col: 'col-md-4' }, { name: 'due_day', label: 'Due day of month', type: 'number', min: 1, max: 28, value: f.due_day || 5, col: 'col-md-4' },
          { name: 'activity_id', label: 'For activity (optional)', type: 'select', options: actOpts(), col: 'col-md-6' }, { name: 'batch_id', label: 'For batch (optional)', type: 'select', options: batchOpts(), col: 'col-md-6' },
          { name: 'late_fee', label: 'Late fee', type: 'money', col: 'col-md-6' }, { name: 'late_fee_after_days', label: 'Late fee after (days)', type: 'number', value: f.late_fee_after_days || 0, col: 'col-md-6' },
          { name: 'auto_assign', label: 'Assign automatically to new admissions', type: 'switch', col: 'col-md-6' },
          ...(f.id ? [{ name: 'status', label: 'Status', type: 'select', placeholder: false, options: [['active', 'Active'], ['inactive', 'Inactive']], col: 'col-md-6' }] : []),
        ],
        submit: async (d) => { const r = f.id ? await put('/fee-structures/' + f.id, d) : await post('/fee-structures', d); toast(r.message); C.dispatch(); },
      });
      if (f.id) {
        const b = document.createElement('button'); b.className = 'btn btn-light me-auto'; b.textContent = 'Apply to existing students';
        b.onclick = async () => { if (!(await confirm('Assign this plan to all current students it covers (who do not have it yet)?'))) return; try { const r = await post(`/fee-structures/${f.id}/apply`); toast(r.message); } catch (e) { fail(e); } };
        m.footer.prepend(b);
      }
    };
    const editD = (d = {}) => formModal({ title: d.id ? 'Edit discount' : 'Add discount', values: { ...d, value: d.id ? (d.value / 100).toString() : '' }, fields: [{ name: 'name', label: 'Name', required: true }, { name: 'kind', label: 'Kind', type: 'select', placeholder: false, options: [['discount', 'Discount'], ['scholarship', 'Scholarship'], ['concession', 'Concession']], col: 'col-6' }, { name: 'value_type', label: 'Type', type: 'select', placeholder: false, options: [['percent', 'Percentage %'], ['flat', 'Flat ₹']], col: 'col-6' }, { name: 'value', label: 'Value', type: 'money', required: true, help: 'For percentage enter 10 for 10%.' }, ...(d.id ? [{ name: 'status', label: 'Status', type: 'select', placeholder: false, options: [['active', 'Active'], ['inactive', 'Inactive']] }] : [])], submit: async (x) => { const r = d.id ? await put('/discounts/' + d.id, x) : await post('/discounts', x); toast(r.message); C.dispatch(); } });
    $('#add', v).onclick = () => edit();
    $('#addd', v).onclick = () => editD();
  }, 'Fee Plans');

  // ============================================================== REPORTS
  route('/reports', async (v, p) => {
    await lookups();
    const list = (await get('/reports')).data.filter((r) => (!r.financial || can('reports.financial')));
    const key = p.r || list[0]?.key;
    const filt = { from: p.from || C.month() + '-01', to: p.to || C.today(), batch_id: p.batch_id || '', activity_id: p.activity_id || '', month: p.month || C.month(), status: p.status || '', branch_id: p.branch_id || '' };
    v.innerHTML = head('Reports') + `<div class="row g-3"><div class="col-lg-3"><div class="list-group">${list.map((r) => `<a class="list-group-item list-group-item-action ${r.key === key ? 'active' : ''}" href="#/reports?r=${r.key}">${esc(r.title)}</a>`).join('')}</div></div>
      <div class="col-lg-9"><form class="filters" id="rf">
        ${key === 'monthly_attendance' ? `<input type="month" class="form-control" name="month" value="${filt.month}">` : `<input type="date" class="form-control" name="from" value="${filt.from}"><input type="date" class="form-control" name="to" value="${filt.to}">`}
        ${LK.branches.length && key !== 'branch_summary' ? `<select class="form-select" name="branch_id"><option value="">All branches (combined)</option>${branchOpts().map(([i, n]) => `<option value="${i}" ${String(i) === filt.branch_id ? 'selected' : ''}>${esc(n)}</option>`).join('')}</select>` : ''}
        <select class="form-select" name="activity_id"><option value="">All activities</option>${actOpts().map(([i, n]) => `<option value="${i}" ${String(i) === filt.activity_id ? 'selected' : ''}>${esc(n)}</option>`).join('')}</select>
        <select class="form-select" name="batch_id"><option value="">All batches</option>${batchOpts().map(([i, n]) => `<option value="${i}" ${String(i) === filt.batch_id ? 'selected' : ''}>${esc(n)}</option>`).join('')}</select>
        <button class="btn btn-primary">Show</button>
        <div class="btn-group ms-auto"><button type="button" class="btn btn-light" data-x="pdf"><i class="bi bi-filetype-pdf"></i> PDF</button><button type="button" class="btn btn-light" data-x="xlsx"><i class="bi bi-file-earmark-excel"></i> Excel</button><button type="button" class="btn btn-light" data-x="csv">CSV</button><button type="button" class="btn btn-light" onclick="window.print()"><i class="bi bi-printer"></i></button></div>
      </form><div id="rr"><div class="loading-block"><div class="spinner-border text-primary"></div></div></div></div></div>`;
    const f = $('#rf', v);
    const params = () => ({ ...readForm(f) });
    f.onsubmit = (e) => { e.preventDefault(); go('#/reports' + qs({ r: key, ...params() })); };
    $$('[data-x]', f).forEach((b) => b.onclick = () => C.download(`/reports/${key}` + qs({ ...params(), format: b.dataset.x }), `${key}.${b.dataset.x}`));
    try {
      const r = (await get(`/reports/${key}` + qs(params()))).data;
      $('#rr', v).innerHTML = `<div class="d-flex align-items-baseline mb-2"><h2 class="h6 fw-bold mb-0">${esc(r.title)}</h2><span class="ms-auto small text-muted">${r.rows.length} rows · ${esc(r.generated_at)}</span></div>
        ${Object.keys(r.totals).length ? `<div class="d-flex gap-3 mb-2">${Object.entries(r.totals).map(([k, x]) => `<div class="stat py-2"><div><div class="lbl">${esc(k)}</div><div class="val fs-5">${esc(x)}</div></div></div>`).join('')}</div>` : ''}
        ${table(r.columns.map((c, i) => ({ label: c, render: (row) => esc(row[i] ?? '') })), r.rows, { empty: 'No data for these filters.' })}`;
    } catch (e) { $('#rr', v).innerHTML = `<div class="alert alert-warning">${esc(e.message)}</div>`; }
  }, 'Reports');


  // ============================================================== BRANCHES
  // Unlimited branches under one academy account; all share one subscription and one student quota.
  function branchTable(rows, onRow) {
    const pct = (x) => x === null || x === undefined ? '–' : x + '%';
    return table([
      { label: 'Branch', render: (b) => `<strong>${esc(b.name)}</strong>${b.status === 'inactive' ? ' ' + badge('inactive') : ''}` },
      { label: 'Students', cls: 'text-end', render: (b) => b.students },
      { label: 'Teachers', cls: 'text-end', render: (b) => b.teachers },
      { label: 'Attendance', cls: 'text-end', render: (b) => pct(b.attendance_pct) },
      ...(has('fees') && can('fees.view') ? [{ label: 'Collected', cls: 'text-end', render: (b) => `<span class="money">${money(b.collected_paise)}</span>` }, { label: 'Pending', cls: 'text-end', render: (b) => `<span class="money ${+b.pending_paise ? 'text-danger' : ''}">${money(b.pending_paise)}</span>` }] : []),
    ], rows, { empty: 'No branches yet.', onRow });
  }
  route('/branches', async (v) => {
    if (!OWNER) throw new Error('Only the Class Admin manages branches.');
    const [sum, dash] = await Promise.all([get('/branches/summary'), get('/dashboard')]);
    const q = dash.data.student_quota || {};
    v.innerHTML = head('Branches', 'Add as many branches as you need. All branches share one subscription and one student limit.', '<button class="btn btn-primary" id="ab"><i class="bi bi-plus-lg"></i> Add branch</button>') + `
      <div class="alert alert-light border d-flex flex-wrap gap-3 align-items-center"><i class="bi bi-people fs-4 text-primary"></i>
        <div><strong>${q.active ?? sum.data.total.students}</strong> active students across all branches${q.limit ? ` · package allows <strong>${q.limit}</strong>` : ' · no student limit'}</div>
        ${q.limit ? `<div class="progress flex-grow-1" style="height:8px;min-width:120px"><div class="progress-bar ${q.active >= q.limit ? 'bg-danger' : ''}" style="width:${Math.min(100, Math.round((q.active / q.limit) * 100))}%"></div></div><a class="btn btn-sm btn-outline-primary" href="#/subscription">Upgrade Package</a>` : ''}</div>
      <div class="card mb-3"><div class="card-header">This month · combined academy view</div><div class="card-body p-0" id="bt"></div></div>
      <div class="small text-muted">Tap a branch to see details, assign a manager, teachers, batches or students.</div>`;
    const rows = [...sum.data.branches, { ...sum.data.total, name: 'All branches (total)', status: 'active', branch_id: null }];
    $('#bt', v).innerHTML = branchTable(rows, true);
    bindRows($('#bt', v), rows, (b) => b.branch_id && go('#/branches/' + b.branch_id));
    $('#ab', v).onclick = () => branchForm();
  }, 'Branches');

  async function branchForm(b = {}) {
    const teachers = (await get('/teachers?status=active&per_page=500')).data;
    formModal({
      title: b.id ? 'Edit branch' : 'Add branch', values: b,
      intro: b.id ? '' : '<p class="small text-muted">Branches are free and unlimited. They do not change your subscription.</p>',
      fields: [
        { name: 'name', label: 'Branch name', required: true, placeholder: 'e.g. Vijay Nagar' },
        { name: 'address', label: 'Address', type: 'textarea', rows: 2 },
        { name: 'contact_number', label: 'Contact number', col: 'col-6' }, { name: 'email', label: 'Email', type: 'email', col: 'col-6' },
        { name: 'manager_teacher_id', label: 'Branch manager (teacher)', type: 'select', placeholder: 'None', options: teachers.map((t) => [t.id, t.name]), col: 'col-6' },
        { name: 'manager_name', label: 'Or manager name', col: 'col-6' },
        ...(b.id ? [{ name: 'status', label: 'Status', type: 'select', placeholder: false, options: [['active', 'Active'], ['inactive', 'Inactive (history kept)']] }] : []),
      ],
      submit: async (d) => { const r = b.id ? await put('/branches/' + b.id, d) : await post('/branches', d); toast(r.message); await lookups(true); C.dispatch(); },
    });
  }


  // Bulk assign / transfer students to a branch. Server-side search + paging, so it works with thousands of students.
  // "Select all matching" sends the filter instead of IDs. Student IDs and the academy student count never change.
  function bulkMove(fixedBranch = null) {
    const st = { q: '', branch_id: '', activity_id: '', batch_id: '', page: 1, not_branch_id: fixedBranch?.id || '' };
    const sel = new Set();
    let allMatching = false;
    let total = 0;
    const m = modal({
      title: fixedBranch ? `Assign students to ${fixedBranch.name}` : 'Move students to a branch', size: 'lg',
      body: `<div class="filters mb-2"><input class="form-control" type="search" data-f="q" placeholder="Name, Student ID or WhatsApp">
          <select class="form-select" data-f="branch_id"><option value="">Current branch: any</option>${branchOpts().map(([i, n]) => `<option value="${i}">${esc(n)}</option>`).join('')}<option value="none">Main / no branch</option></select>
          <select class="form-select" data-f="activity_id"><option value="">All activities</option>${LK.activities.map((a) => `<option value="${a.id}">${esc(a.name)}</option>`).join('')}</select>
          <select class="form-select" data-f="batch_id"><option value="">All batches</option>${batchOpts().map(([i, n]) => `<option value="${i}">${esc(n)}</option>`).join('')}</select></div>
        <div class="d-flex align-items-center gap-2 small mb-2"><label class="form-check mb-0"><input class="form-check-input" type="checkbox" id="pgAll"> This page</label>
          <button type="button" class="btn btn-link btn-sm p-0" id="allM"></button><span class="ms-auto fw-bold" id="selN">0 selected</span></div>
        <div id="pl"><div class="loading-block"><div class="spinner-border text-primary"></div></div></div>
        <div class="row g-2 mt-2 border-top pt-3">
          ${fixedBranch ? '' : `<div class="col-md-6"><label class="form-label small fw-bold">Move to branch</label><select class="form-select" id="toBr"><option value="">Choose…</option>${branchOpts().map(([i, n]) => `<option value="${i}">${esc(n)}</option>`).join('')}</select></div>`}
          <div class="col-md-6"><label class="form-label small fw-bold">Also move into batch (optional)</label><select class="form-select" id="toBt"></select></div></div>`,
      footer: '<button class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" id="go" disabled><i class="bi bi-arrow-left-right"></i> Assign selected</button>',
    });
    const el = m.el;
    const target = () => fixedBranch?.id || $('#toBr', el)?.value || '';
    const fillBatches = () => { const br = target(); $('#toBt', el).innerHTML = '<option value="">Keep current batch</option>' + LK.batches.filter((x) => br && String(x.branch_id || '') === String(br)).map((x) => `<option value="${x.id}">${esc(x.name)} (${esc(x.activity_name)})</option>`).join(''); };
    const count = () => (allMatching ? total : sel.size);
    const paintCount = () => {
      $('#selN', el).textContent = `${count()} selected`;
      $('#allM', el).textContent = allMatching ? 'Clear selection' : (total > 0 ? `Select all ${total} matching` : '');
      $('#go', el).disabled = !count() || !target();
    };
    const load = async () => {
      const json = await get('/students' + qs({ ...st, per_page: 25 }));
      total = json.meta?.total || 0;
      const rows = json.data || [];
      $('#pl', el).innerHTML = rows.length ? `<div class="list-group">${rows.map((x) => `<label class="list-group-item d-flex gap-2 align-items-center"><input class="form-check-input" type="checkbox" value="${x.id}" ${allMatching || sel.has(x.id) ? 'checked' : ''} ${allMatching ? 'disabled' : ''}>
          <span class="flex-grow-1">${esc(x.name)} <span class="small text-muted">${esc(x.student_code)} · ${esc(x.whatsapp || '')}</span></span><span class="small text-muted">${esc(x.branch_name || 'Main')}</span></label>`).join('')}</div>
        <div class="pager"><span>${total} students · page ${json.meta.page} of ${json.meta.pages || 1}</span><span class="btn-group"><button type="button" class="btn btn-sm btn-light" data-p="-1" ${st.page <= 1 ? 'disabled' : ''}><i class="bi bi-chevron-left"></i></button><button type="button" class="btn btn-sm btn-light" data-p="1" ${st.page >= json.meta.pages ? 'disabled' : ''}><i class="bi bi-chevron-right"></i></button></span></div>`
        : '<div class="empty py-4">No students match.</div>';
      $$('input[type=checkbox]', $('#pl', el)).forEach((c) => c.onchange = () => { c.checked ? sel.add(+c.value) : sel.delete(+c.value); paintCount(); });
      $$('[data-p]', el).forEach((btn) => btn.onclick = () => { st.page += +btn.dataset.p; load(); });
      $('#pgAll', el).checked = false;
      paintCount();
    };
    let t;
    $$('[data-f]', el).forEach((f) => f.addEventListener(f.type === 'search' ? 'input' : 'change', () => { clearTimeout(t); t = setTimeout(() => { st[f.dataset.f] = f.value; st.page = 1; allMatching = false; load(); }, f.type === 'search' ? 300 : 0); }));
    $('#pgAll', el).onchange = (e) => { $$('#pl input[type=checkbox]', el).forEach((c) => { c.checked = e.target.checked; e.target.checked ? sel.add(+c.value) : sel.delete(+c.value); }); paintCount(); };
    $('#allM', el).onclick = () => { allMatching = !allMatching; if (!allMatching) sel.clear(); load(); };
    $('#toBr', el) && ($('#toBr', el).onchange = () => { fillBatches(); paintCount(); });
    fillBatches();
    $('#go', el).onclick = async () => {
      const filter = { q: st.q, branch_id: st.branch_id, activity_id: st.activity_id, batch_id: st.batch_id, not_branch_id: st.not_branch_id };
      const body = { branch_id: target(), batch_id: $('#toBt', el).value || null, ...(allMatching ? { filter } : { student_ids: [...sel] }) };
      try {
        const pre = await post('/students/bulk-branch', { ...body, preview: 1 });
        const brName = fixedBranch?.name || $('#toBr option:checked', el).textContent;
        if (!(await confirm(`Move ${pre.data.count} student(s) to ${brName}${body.batch_id ? ' and into the selected batch' : ''}? They keep the same Student ID, fees and attendance history. Your student count does not change.`, { title: 'Confirm bulk move', ok: `Move ${pre.data.count} student(s)` }))) return;
        const r = await post('/students/bulk-branch', body);
        toast(r.message);
        m.close();
        C.dispatch();
      } catch (e) { fail(e); }
    };
    load();
  }

  route('/branches/:id', async (v, p) => {
    const b = (await get('/branches/' + p.id)).data;
    const f = b.figures;
    v.innerHTML = head(esc(b.name) + (b.status === 'inactive' ? ' ' + badge('inactive') : ''), esc([b.address, b.contact_number, b.manager_teacher_name || b.manager_name ? 'Manager: ' + (b.manager_teacher_name || b.manager_name) : ''].filter(Boolean).join(' · ')),
      `<button class="btn btn-light" id="eb"><i class="bi bi-pencil"></i> Edit</button><button class="btn btn-light" id="as"><i class="bi bi-people"></i> Assign students</button><button class="btn btn-light" id="at"><i class="bi bi-person-badge"></i> Teachers & batches</button><a class="btn btn-light" href="#/reports?r=students&branch_id=${b.id}"><i class="bi bi-bar-chart"></i> Reports</a>`) + `
      <div class="row g-3 mb-3">
        ${[['Active students', f.students], ['Teachers', f.teachers], ['Batches', f.batches], ['Attendance (month)', f.attendance_pct === null ? '–' : f.attendance_pct + '%'], ...(has('fees') && can('fees.view') ? [['Collected (month)', money(f.collected_paise)], ['Pending', money(f.pending_paise)]] : [])].map(([l, x]) => `<div class="col-6 col-md-4 col-xl-2"><div class="stat"><div><div class="lbl">${l}</div><div class="val fs-5">${x}</div></div></div></div>`).join('')}
      </div>
      <div class="row g-3"><div class="col-lg-7"><div class="card"><div class="card-header">Batches</div><div class="card-body p-0">${table([{ label: 'Batch', render: (x) => `<strong>${esc(x.name)}</strong><div class="small text-muted">${esc(x.activity_name)}${x.start_time ? ' · ' + time(x.start_time) : ''}</div>` }, { label: 'Teacher', render: (x) => esc(x.teacher_name || '–') }, { label: 'Students', cls: 'text-end', render: (x) => x.students }, { label: 'Attendance', cls: 'text-end', render: (x) => x.attendance_pct === null ? '–' : x.attendance_pct + '%' }], b.batches, { empty: 'No batches at this branch yet.' })}</div></div></div>
      <div class="col-lg-5"><div class="card"><div class="card-header">Teachers</div><div class="card-body py-1">${b.teachers.length ? b.teachers.map((t) => `<div class="list-tile"><div class="flex-grow-1">${esc(t.name)}</div>${badge(t.status)}</div>`).join('') : '<div class="empty py-4">No teachers assigned.</div>'}</div></div></div></div>
      <div class="d-flex gap-2 mt-3 flex-wrap"><a class="btn btn-light" href="#/students?branch_id=${b.id}">Students</a>${has('fees') ? `<a class="btn btn-light" href="#/reports?r=fee_collection&branch_id=${b.id}">Fee collection</a><a class="btn btn-light" href="#/reports?r=pending_fees&branch_id=${b.id}">Pending fees</a>` : ''}<a class="btn btn-light" href="#/reports?r=attendance&branch_id=${b.id}">Attendance</a></div>`;
    $('#eb', v).onclick = () => branchForm(b);
    $('#as', v).onclick = () => bulkMove(b);
    $('#at', v).onclick = async () => {
      const [t, bt] = await Promise.all([get('/teachers?status=active'), get('/batches?status=active')]);
      const opt = (rows, sel) => rows.filter((x) => String(x.branch_id || '') !== String(b.id)).map((x) => [x.id, sel(x)]);
      formModal({
        title: 'Assign teachers & batches to ' + b.name, okText: 'Assign',
        fields: [
          { name: 'teacher_ids', label: 'Teachers', type: 'multicheck', options: opt(t.data, (x) => x.name) },
          { name: 'batch_ids', label: 'Batches', type: 'multicheck', options: opt(bt.data, (x) => `${x.name} (${x.activity_name})`) },
        ],
        submit: async (d) => { const r = await post(`/branches/${b.id}/assign`, d); toast(r.message); await lookups(true); C.dispatch(); },
      });
    };
  }, 'Branch');

  // ========================================================== SUBSCRIPTION
  route('/subscription', async (v) => {
    const s = (await get('/subscription')).data;
    const c = s.current;
    v.innerHTML = head('Subscription') + `<div class="row g-3"><div class="col-lg-5"><div class="card h-100"><div class="card-body">
      <div class="small text-muted">Current plan</div><div class="fs-3 fw-bold">${esc(c.package_name || 'None')} ${badge(c.status)}</div>
      <div class="text-muted">${c.ends_on ? (c.days_left >= 0 ? `Valid till <strong>${date(c.ends_on)}</strong> (${c.days_left} days left)` : `Expired on ${date(c.ends_on)}`) : 'Lifetime'}</div>
      <hr><h6 class="fw-bold">Usage</h6>${Object.entries(s.usage.usage).filter(([k]) => k !== 'whatsapp_month' || has('whatsapp')).map(([k, u]) => `<div class="mb-2"><div class="d-flex small"><span>${label(k.replace('_mb', ' (MB)').replace('whatsapp_month', 'WhatsApp this month'))}</span><span class="ms-auto">${u.used} / ${u.limit ?? '∞'}</span></div>${u.limit ? `<div class="progress"><div class="progress-bar ${u.used / u.limit > 0.9 ? 'bg-danger' : ''}" style="width:${Math.min(100, (u.used * 100) / u.limit)}%"></div></div>` : ''}</div>`).join('')}
      <h6 class="fw-bold mt-3">Included</h6><div class="d-flex flex-wrap gap-1">${s.usage.features.map((f) => `<span class="badge b-success">${esc(s.feature_labels[f] || f)}</span>`).join('')}</div>
    </div></div></div><div class="col-lg-7"><div class="row g-3">${s.packages.map((p) => `<div class="col-md-6"><div class="card h-100 ${p.id === c.package_id ? 'border-primary' : ''}"><div class="card-body d-flex flex-column"><div class="fw-bold fs-5">${esc(p.name)}</div><div class="fs-3 fw-bold">${money(p.price_paise)}<span class="fs-6 text-muted fw-normal">/${label(p.billing_cycle)}</span></div><div class="small text-muted mb-2">${esc(p.description || '')}</div><div class="small mb-3">Up to ${p.student_limit ?? 'unlimited'} students · ${p.teacher_limit ?? 'unlimited'} teachers</div>
      ${s.online_payment ? `<button class="btn ${p.id === c.package_id ? 'btn-primary' : 'btn-outline-primary'} mt-auto" data-buy="${p.id}">${p.id === c.package_id ? 'Renew' : 'Switch to ' + esc(p.name)}</button>` : '<div class="small text-muted mt-auto">Contact ClassNex support to renew or upgrade.</div>'}</div></div></div>`).join('')}</div></div></div>
    <h6 class="fw-bold mt-4">Payments</h6>${table([{ label: 'Date', render: (x) => dateTime(x.paid_at || x.created_at) }, { label: 'Plan', render: (x) => esc(x.package_name) }, { label: 'Amount', render: (x) => money(x.amount_paise) }, { label: 'Period', render: (x) => x.period_from ? `${date(x.period_from)} – ${date(x.period_to)}` : '–' }, { label: 'Status', render: (x) => badge(x.status) }], s.payments, { empty: 'No payments yet.' })}`;
    $$('[data-buy]', v).forEach((b) => b.onclick = async () => { try { const o = (await post('/subscription/checkout', { package_id: +b.dataset.buy })).data; C.razorpay(o, () => setTimeout(() => location.reload(), 800)); } catch (e) { fail(e); } });
  }, 'Subscription');

  // ============================================================== SETTINGS
  route('/settings', async (v, p) => {
    const tab = p.tab || 'profile';
    const tabs = [['profile', 'Academy profile'], ['notify', 'Notifications & reminders'], ['receipts', 'Receipts & IDs'], ['admission', 'Online admission'], ...(has('online_payments') ? [['payments', 'Online payments']] : []), ['audit', 'Activity log']];
    v.innerHTML = head('Settings') + `<ul class="nav nav-pills mb-3 flex-wrap gap-1">${tabs.map(([k, l]) => `<li><a class="nav-link ${k === tab ? 'active' : ''}" href="#/settings?tab=${k}">${l}</a></li>`).join('')}</ul><div id="st"></div>`;
    const el = $('#st', v);
    const a = (await get('/academy')).data;
    const saveSettings = async (patch) => { const r = await post('/academy', { settings: { ...a.settings, ...patch } }); toast(r.message); };
    if (tab === 'profile') {
      el.innerHTML = `<form class="card" id="f"><div class="card-body">
        <div class="d-flex gap-4 flex-wrap mb-3"><div class="text-center"><img src="${esc(a.logo_url || C.BOOT.root + '/assets/img/classnex-icon.png')}" style="height:90px;width:90px;object-fit:contain" class="border rounded p-1 mb-1" id="lg"><br><label class="btn btn-sm btn-light">Change logo<input type="file" accept="image/*" name="logo" hidden></label></div>
        <div class="text-center"><div style="height:90px;width:160px" class="border rounded d-flex align-items-center justify-content-center">${a.signature_url ? `<img src="${esc(a.signature_url)}" style="max-height:80px;max-width:150px">` : '<span class="small text-muted">No signature</span>'}</div><label class="btn btn-sm btn-light mt-1">Signature for receipts<input type="file" accept="image/*" name="signature" hidden></label></div></div>
        ${form([
          { name: 'name', label: 'Academy name', required: true, col: 'col-md-6' }, { name: 'contact_person', label: 'Contact person', col: 'col-md-6' },
          { name: 'mobile', label: 'Mobile', col: 'col-md-4' }, { name: 'whatsapp', label: 'WhatsApp', col: 'col-md-4' }, { name: 'email', label: 'Email', type: 'email', col: 'col-md-4' },
          { name: 'address', label: 'Address', type: 'textarea', rows: 2 }, { name: 'city', label: 'City', col: 'col-md-4' }, { name: 'state', label: 'State', col: 'col-md-4' }, { name: 'pincode', label: 'PIN code', col: 'col-md-4' },
          { name: 'website', label: 'Website', col: 'col-md-6' }, { name: 'gstin', label: 'GSTIN (optional)', col: 'col-md-6' }, { name: 'business_details', label: 'About / business details', type: 'textarea', rows: 2 },
        ], a)}<button class="btn btn-primary mt-3">Save</button></div></form>`;
      const f = $('#f', el);
      $('[name=logo]', f).onchange = (e) => { if (e.target.files[0]) $('#lg', f).src = URL.createObjectURL(e.target.files[0]); };
      f.onsubmit = async (e) => {
        e.preventDefault();
        const fd = new FormData();
        Object.entries(readForm(f)).forEach(([k, x]) => fd.append(k, x));
        ['logo', 'signature'].forEach((k) => { const fl = $(`[name=${k}]`, f).files[0]; if (fl) fd.append(k, fl); });
        try { const r = await post('/academy', fd); toast(r.message); } catch (er) { showErrors(f, er.errors); fail(er); }
      };
    } else if (tab === 'notify') {
      const n = (await get('/notification-settings')).data;
      const s = a.settings;
      el.innerHTML = `<div class="card mb-3"><div class="card-header">Who gets what</div><div class="card-body"><p class="small text-muted">Choose which messages are sent and on which channel. Greyed-out channels are not in your plan.</p><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Event</th>${n.channels.map((c) => `<th class="text-center">${label(c === 'inapp' ? 'App' : c)}</th>`).join('')}</tr></thead><tbody>
        ${Object.entries(n.events).map(([ev, l]) => `<tr><td>${esc(l)}</td>${n.channels.map((c) => `<td class="text-center"><input type="checkbox" class="form-check-input" data-ev="${ev}" data-ch="${c}" ${n.matrix[ev][c] ? 'checked' : ''} ${n.available[c] ? '' : 'disabled'}></td>`).join('')}</tr>`).join('')}</tbody></table></div>
        <div class="row g-2 mt-1"><div class="col-12 fw-bold small">Attendance alerts to parents</div>${['present', 'absent', 'late', 'corrected'].map((k) => `<div class="col-6 col-md-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" data-att="${k}" ${s.attendance_notify[k] ? 'checked' : ''}><label class="form-check-label">${label(k)}</label></div></div>`).join('')}</div>
        <button class="btn btn-primary mt-3" id="sv">Save</button></div></div>
        <div class="card"><div class="card-header">Automatic fee reminder schedule</div><div class="card-body">${n.auto_reminders ? `<p class="small text-muted">Days relative to the due date (negative = before). Default: 7, 3 and 1 day before, on the due date, and 3 and 7 days after.</p>
          <div class="d-flex flex-wrap gap-2">${[-7, -5, -3, -2, -1, 0, 1, 3, 5, 7, 10, 15].map((d) => `<label class="btn btn-sm btn-outline-primary ${n.reminder_rules.includes(d) ? 'active' : ''}"><input type="checkbox" class="d-none" data-off="${d}" ${n.reminder_rules.includes(d) ? 'checked' : ''}>${d < 0 ? -d + ' days before' : d === 0 ? 'On due date' : d + ' days after'}</label>`).join('')}</div><button class="btn btn-primary mt-3" id="svr">Save schedule</button>` : 'Automatic reminders are not in your plan. <a href="#/subscription">Upgrade</a>'}</div></div>`;
      $$('[data-off]', el).forEach((c) => c.onchange = () => c.closest('label').classList.toggle('active', c.checked));
      $('#sv', el).onclick = async () => {
        const matrix = {};
        $$('[data-ev]', el).forEach((c) => { (matrix[c.dataset.ev] ||= {})[c.dataset.ch] = c.checked; });
        const att = Object.fromEntries($$('[data-att]', el).map((c) => [c.dataset.att, c.checked]));
        try { await post('/notification-settings', { matrix }); await saveSettings({ attendance_notify: att }); } catch (e) { fail(e); }
      };
      $('#svr', el) && ($('#svr', el).onclick = async () => { try { const r = await post('/notification-settings', { reminder_rules: $$('[data-off]:checked', el).map((c) => +c.dataset.off) }); toast(r.message); } catch (e) { fail(e); } });
    } else if (tab === 'receipts') {
      el.innerHTML = `<form class="card" id="f"><div class="card-body">${form([
        { name: 'receipt_prefix', label: 'Receipt number prefix', col: 'col-md-4', help: 'e.g. RCPT → RCPT/2026-27/00001' }, { name: 'invoice_prefix', label: 'Invoice prefix', col: 'col-md-4' }, { name: 'student_id_prefix', label: 'Student ID prefix', col: 'col-md-4', help: 'Blank = academy code (' + esc(a.code) + ')' },
        { name: 'receipt_footer', label: 'Receipt footer text', value: a.receipt_footer, col: 'col-12' },
        { name: 'prorate_first_month', label: 'Charge only for remaining days when a student joins mid-month', type: 'switch' },
        { name: 'send_admission_message', label: 'Send admission confirmation', type: 'switch' }, { name: 'send_payment_message', label: 'Send payment confirmation with receipt link', type: 'switch' },
      ], a.settings)}<button class="btn btn-primary mt-3">Save</button></div></form>`;
      $('#f', el).onsubmit = async (e) => { e.preventDefault(); const d = readForm(e.target); const footer = d.receipt_footer; delete d.receipt_footer; d.prorate_first_month = !!d.prorate_first_month; d.send_admission_message = !!d.send_admission_message; d.send_payment_message = !!d.send_payment_message; try { await post('/academy', { receipt_footer: footer }); await saveSettings(d); } catch (er) { fail(er); } };
    } else if (tab === 'admission') {
      el.innerHTML = `<form class="card" id="f"><div class="card-body"><p>Your public registration link: <a href="${esc(a.admission_link)}" target="_blank">${esc(a.admission_link)}</a></p>${has('online_admission') ? form([
        { name: 'online_admission_enabled', label: 'Accept online applications', type: 'switch', value: a.online_admission_enabled },
        { type: 'html', name: 'h', html: '<div class="fw-bold small mt-2">Optional fields to show</div>' },
        ...['dob', 'address', 'email', 'photo', 'document'].map((k) => ({ name: 'f_' + k, label: label(k === 'dob' ? 'Date of birth' : k), type: 'switch', col: 'col-md-4', value: a.settings.online_admission_fields[k] ? 1 : 0 })),
      ]) + '<button class="btn btn-primary mt-3">Save</button>' : 'Online admission is not in your plan.'}</div></form>`;
      $('#f', el).onsubmit = async (e) => { e.preventDefault(); const d = readForm(e.target); try { await post('/academy', { online_admission_enabled: d.online_admission_enabled }); await saveSettings({ online_admission_fields: Object.fromEntries(['dob', 'address', 'email', 'photo', 'document'].map((k) => [k, !!d['f_' + k]])) }); } catch (er) { fail(er); } };
    } else if (tab === 'payments') {
      const g = (await get('/payment-gateway')).data;
      el.innerHTML = `<form class="card" id="f"><div class="card-body"><p class="small text-muted">Parents can pay fees online through <strong>Razorpay</strong>. The money goes directly to your Razorpay account. Payments are verified on the server before they are recorded.</p>${form([
        { name: 'key_id', label: 'Key ID', col: 'col-md-6' }, { name: 'key_secret', label: 'Key Secret', type: 'password', col: 'col-md-6', placeholder: g.key_secret || '' },
        { name: 'webhook_secret', label: 'Webhook secret', type: 'password', col: 'col-md-6', placeholder: g.webhook_secret || '' }, { name: 'is_enabled', label: 'Enable online payments', type: 'switch', col: 'col-md-6' },
        { type: 'html', name: 'w', html: `<div class="small">Webhook URL (Razorpay Dashboard → Webhooks, events <code>payment.captured</code>, <code>order.paid</code>):<pre class="code mt-1">${esc(g.webhook_url)}</pre></div>` },
      ], g)}<button class="btn btn-primary mt-2">Save</button></div></form>`;
      $('#f', el).onsubmit = async (e) => { e.preventDefault(); try { const r = await post('/payment-gateway', readForm(e.target)); toast(r.message); } catch (er) { fail(er); } };
    } else if (tab === 'audit') {
      listView({ el, endpoint: '/audit-logs', search: false, columns: [{ label: 'When', render: (l) => dateTime(l.created_at) }, { label: 'Who', render: (l) => esc(l.user_name || 'System') }, { label: 'Action', render: (l) => `<code>${esc(l.action)}</code>` }, { label: 'Details', render: (l) => `<div class="small text-muted" style="max-width:420px;word-break:break-all">${esc(l.new_values || l.old_values || '')}</div>` }], empty: 'No activity yet.' });
    }
  }, 'Settings');

  // ============================================================== TEACHER
  route('/leaves', async (v) => {
    const rows = (await get('/leaves')).data;
    v.innerHTML = head(OWNER ? 'Teacher leave requests' : 'My leave', '', !OWNER ? '<button class="btn btn-primary" id="ap"><i class="bi bi-plus-lg"></i> Apply for leave</button>' : '') + table([
      ...(OWNER ? [{ label: 'Teacher', render: (l) => esc(l.teacher_name) }] : []),
      { label: 'From', render: (l) => date(l.from_date) }, { label: 'To', render: (l) => date(l.to_date) }, { label: 'Reason', render: (l) => esc(l.reason || '') }, { label: 'Status', render: (l) => badge(l.status) },
      { label: '', cls: 'text-end', render: (l) => OWNER && l.status === 'pending' ? `<button class="btn btn-sm btn-success" data-s="approved" data-id="${l.id}">Approve</button> <button class="btn btn-sm btn-light" data-s="rejected" data-id="${l.id}">Reject</button>` : '' },
    ], rows, { empty: 'No leave requests.' });
    $('#ap', v) && ($('#ap', v).onclick = () => formModal({ title: 'Apply for leave', fields: [{ name: 'from_date', label: 'From', type: 'date', required: true, col: 'col-6' }, { name: 'to_date', label: 'To', type: 'date', required: true, col: 'col-6' }, { name: 'reason', label: 'Reason', type: 'textarea' }], submit: async (d) => { const r = await post('/leaves', d); toast(r.message); C.dispatch(); } }));
    $$('[data-s]', v).forEach((b) => b.onclick = async () => { await put('/leaves/' + b.dataset.id, { status: b.dataset.s }).then((r) => { toast(r.message); C.dispatch(); }).catch(fail); });
  }, 'Leave');

  route('/support', C.supportView, 'Help & Support');
  route('/support/:id', C.supportView, 'Support request');

  // global student search in the top bar
  const gs = $('#globalSearch');
  if (gs && can('students.view')) {
    $('#globalSearchWrap').hidden = false;
    gs.addEventListener('keydown', (e) => { if (e.key === 'Enter' && gs.value.trim()) { go('#/students?q=' + encodeURIComponent(gs.value.trim())); gs.value = ''; } });
  }
  if (readOnly) document.body.classList.add('read-only');
  C.start();
})();
