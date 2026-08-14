(function () {
  const config = window.DVC_PAGE || {};
  const GROUP = config.group || 'pledge_not_started';
  const AMOUNT_KEY = config.amount_key || 'pledged';
  const CURRENCY = config.currency || 'GBP';
  const CAMPAIGN = config.campaign === true;
  const COLSPAN = CAMPAIGN ? 8 : 7;
  let sortState = {
    sortBy: config.sort_by || 'name',
    sortOrder: config.sort_order || 'asc'
  };

  function fmtMoney(amount) {
    const n = Number(amount || 0);
    try {
      return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: CURRENCY,
        maximumFractionDigits: 2
      }).format(n);
    } catch (_) {
      return CURRENCY + ' ' + n.toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    }
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function updateSortIcons() {
    document.querySelectorAll('.dvc-sortable').forEach((th) => {
      const active = th.dataset.sortBy === sortState.sortBy;
      th.classList.toggle('dvc-sort-active', active);
      const icon = th.querySelector('.dvc-sort-icon');
      if (!icon) return;
      if (!active) {
        icon.innerHTML = '';
        return;
      }
      icon.innerHTML = sortState.sortOrder === 'asc'
        ? '<i class="fas fa-sort-up"></i>'
        : '<i class="fas fa-sort-down"></i>';
    });
  }

  function buildUrl(page) {
    const params = new URLSearchParams();
    params.set('group', GROUP);
    params.set('page', String(page));
    params.set('per_page', document.getElementById('perPage').value);
    params.set('sort_by', sortState.sortBy);
    params.set('sort_order', sortState.sortOrder);
    const donor = document.getElementById('filterDonor').value.trim();
    const source = document.getElementById('filterSource').value;
    if (donor) params.set('donor', donor);
    if (source) params.set('source', source);
    return 'api/donors.php?' + params.toString();
  }

  function renderKpis(summary) {
    const group = summary[GROUP] || {};
    const amount = group[AMOUNT_KEY] || 0;
    document.getElementById('kpiDonors').textContent = Number(group.donors || 0).toLocaleString();
    document.getElementById('kpiAmount').textContent = fmtMoney(amount);
    document.getElementById('kpiPledged').textContent = fmtMoney(group.pledged);
    document.getElementById('kpiPaid').textContent = fmtMoney(group.paid);
    document.getElementById('kpiRemaining').textContent = fmtMoney(group.remaining);
  }

  function sourceBadge(source) {
    if (source === 'old_system') {
      return '<span class="dvc-badge dvc-badge-old">Old</span>';
    }
    return '<span class="dvc-badge dvc-badge-new">New</span>';
  }

  function renderTable(data) {
    const body = document.getElementById('dataBody');
    const rows = data.rows || [];
    if (rows.length === 0) {
      body.innerHTML = '<tr><td colspan="' + COLSPAN + '"><div class="dvc-empty-state"><i class="fas fa-inbox"></i><p>No donors in this group.</p></div></td></tr>';
      return;
    }
    const startNum = (data.page - 1) * data.per_page + 1;
    const campaign = window.DVC_CAMPAIGN;
    body.innerHTML = rows.map((r, i) => {
      const name = escapeHtml(r.name || 'Unknown');
      const id = Number(r.donor_id || r.id || 0);
      const link = r.donor_id
        ? `<a class="dvc-donor-link" href="../donor-management/view-donor.php?id=${r.donor_id}">${name}</a>`
        : name;
      const check = CAMPAIGN
        ? `<td class="dvc-col-check" data-label="Select"><input type="checkbox" class="dvc-row-check" data-donor-id="${id}" ${campaign && campaign.isSelected(id) ? 'checked' : ''} ${campaign && campaign.canTick() ? '' : 'disabled'}></td>`
        : '';
      return `<tr>
        ${check}
        <td class="dvc-col-num" data-label="#">${startNum + i}</td>
        <td data-label="Donor"><div>${link}</div><small class="text-muted">${escapeHtml(r.phone || '')}</small></td>
        <td data-label="Reference"><code class="small">${escapeHtml(r.reference || '—')}</code></td>
        <td data-label="Source">${sourceBadge(r.data_source)}</td>
        <td class="text-end" data-label="Pledged">${escapeHtml(fmtMoney(r.pledged))}</td>
        <td class="text-end text-success" data-label="Paid">${escapeHtml(fmtMoney(r.paid))}</td>
        <td class="text-end fw-semibold" data-label="Remaining">${escapeHtml(fmtMoney(r.balance))}</td>
      </tr>`;
    }).join('');
    if (CAMPAIGN && window.DVC_CAMPAIGN && typeof window.DVC_CAMPAIGN.onRows === 'function') {
      window.DVC_CAMPAIGN.onRows(rows);
    }
  }

  function renderPagination(data) {
    const ul = document.getElementById('pagination');
    const info = document.getElementById('paginationInfo');
    const total = data.total_count || 0;
    const from = total === 0 ? 0 : (data.page - 1) * data.per_page + 1;
    const to = Math.min(data.page * data.per_page, total);
    info.innerHTML = `Showing <strong>${from}</strong>–<strong>${to}</strong> of <strong>${total}</strong>`;
    if (data.total_pages <= 1) {
      ul.innerHTML = '';
      return;
    }
    let html = '';
    const page = data.page;
    if (page > 1) {
      html += `<li class="page-item"><a class="page-link" href="#" data-page="${page - 1}" aria-label="Previous"><i class="fas fa-angle-left"></i></a></li>`;
    }
    const start = Math.max(1, page - 2);
    const end = Math.min(data.total_pages, page + 2);
    for (let i = start; i <= end; i++) {
      html += `<li class="page-item ${i === page ? 'active' : ''}"><a class="page-link" href="#" data-page="${i}">${i}</a></li>`;
    }
    if (page < data.total_pages) {
      html += `<li class="page-item"><a class="page-link" href="#" data-page="${page + 1}" aria-label="Next"><i class="fas fa-angle-right"></i></a></li>`;
    }
    ul.innerHTML = html;
    ul.querySelectorAll('a[data-page]').forEach((a) => {
      a.addEventListener('click', (e) => {
        e.preventDefault();
        load(parseInt(a.dataset.page, 10));
      });
    });
  }

  async function load(page) {
    const body = document.getElementById('dataBody');
    body.innerHTML = '<tr><td colspan="' + COLSPAN + '" class="text-center py-4" style="color:var(--gray-500)"><div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading...</td></tr>';
    try {
      const res = await fetch(buildUrl(page), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Request failed');
      renderKpis(data.summary || {});
      renderTable(data);
      renderPagination(data);
    } catch (err) {
      body.innerHTML = '<tr><td colspan="' + COLSPAN + '" class="text-center py-4" style="color:var(--danger)">Failed to load donors.</td></tr>';
    }
  }

  document.querySelectorAll('.dvc-sortable').forEach((th) => {
    th.addEventListener('click', () => {
      const key = th.dataset.sortBy;
      if (sortState.sortBy === key) {
        sortState.sortOrder = sortState.sortOrder === 'asc' ? 'desc' : 'asc';
      } else {
        sortState.sortBy = key;
        sortState.sortOrder = ['pledged', 'paid', 'balance'].includes(key) ? 'desc' : 'asc';
      }
      updateSortIcons();
      load(1);
    });
  });
  document.getElementById('applyFilters').addEventListener('click', () => load(1));
  document.getElementById('clearFilters').addEventListener('click', () => {
    document.getElementById('filterDonor').value = '';
    document.getElementById('filterSource').value = '';
    load(1);
  });
  document.getElementById('perPage').addEventListener('change', () => load(1));
  document.getElementById('filterDonor').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') load(1);
  });

  updateSortIcons();
  load(1);
})();
