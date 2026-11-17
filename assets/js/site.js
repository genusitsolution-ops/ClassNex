/* ClassNex website: AJAX forms posting to the REST API */
(() => {
  const api = document.querySelector('meta[name=cnx-api]')?.content || 'api/v1';
  document.querySelectorAll('form[data-api]').forEach((form) => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = form.querySelector('[type=submit]');
      const out = form.querySelector('[data-result]');
      form.querySelectorAll('.is-invalid').forEach((i) => i.classList.remove('is-invalid'));
      const data = {};
      new FormData(form).forEach((v, k) => { data[k] = typeof v === 'string' ? v.trim() : v; });
      form.querySelectorAll('input[type=checkbox]').forEach((c) => { data[c.name] = c.checked ? 1 : 0; });
      const hasFile = [...form.querySelectorAll('input[type=file]')].some((i) => i.files.length);
      let body, headers = {};
      if (hasFile) { body = new FormData(form); form.querySelectorAll('input[type=checkbox]').forEach((c) => body.set(c.name, c.checked ? 1 : 0)); }
      else { body = JSON.stringify(data); headers['Content-Type'] = 'application/json'; }
      btn.disabled = true;
      const old = btn.innerHTML;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Please wait…';
      try {
        const res = await fetch(api + form.dataset.api, { method: 'POST', body, headers, credentials: 'same-origin' });
        const json = await res.json();
        if (!res.ok || !json.success) {
          Object.entries(json.errors || {}).forEach(([k, m]) => {
            const i = form.querySelector(`[name="${k}"]`);
            if (i) { i.classList.add('is-invalid'); const fb = i.parentElement.querySelector('.invalid-feedback') || i.closest('div').querySelector('.invalid-feedback'); if (fb) fb.textContent = m; }
          });
          throw new Error(json.message || 'Something went wrong');
        }
        if (form.dataset.onSuccess === 'redirect' && json.data?.redirect) { location.href = json.data.redirect; return; }
        if (form.dataset.onSuccess === 'accounts' && json.data?.choose_account) {
          const sel = form.querySelector('[data-accounts]');
          sel.innerHTML = '<label class="form-label">This number has more than one account. Which one?</label>' + json.data.accounts.map((a, i) => `<label class="d-flex gap-2 align-items-center border rounded-3 p-2 mb-2"><input type="radio" name="account_id" value="${a.id}" ${i ? '' : 'checked'}> <span><strong>${a.academy}</strong><br><small class="text-muted">${a.role.replace('_', ' ')} · ${a.name}</small></span></label>`).join('');
          sel.hidden = false;
          btn.innerHTML = old; btn.disabled = false;
          return;
        }
        if (json.data?.redirect) {
          const nx = form.dataset.next || '';
          const panel = json.data.redirect.replace(/\/$/, '').split('/').pop();
          location.href = nx.startsWith('/') && !nx.startsWith('//') && nx.includes('/' + panel + '/') ? nx : json.data.redirect;
          return;
        }
        form.innerHTML = `<div class="text-center py-4"><i class="bi bi-check-circle-fill text-success" style="font-size:3rem"></i><h4 class="mt-3 fw-bold">${json.message}</h4>${json.data?.reference ? `<p>Reference: <strong>${json.data.reference}</strong></p>` : ''}${form.dataset.successHtml || ''}</div>`;
      } catch (err) {
        if (out) { out.className = 'alert alert-danger mt-3'; out.textContent = err.message; out.hidden = false; }
        btn.innerHTML = old; btn.disabled = false;
      }
    });
  });
})();
