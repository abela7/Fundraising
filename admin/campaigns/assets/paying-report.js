(function () {
  const COLSPAN = 5;
  let filter = 'all';
  let page = 1;

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
  }

  function buildUrl() {
    const params = new URLSearchParams();
    params.set('filter', filter);
    params.set('page', String(page));
    params.set('per_page', document.getElementById('perPage').value);
    const donor = document.getElementById('filterDonor').value.trim();
    if (donor) params.set('donor', donor);
    return 'api/paying-report.php?' + params.toString();
  }

  function answerBadge(row) {
    if (row.answer === 'yes') {
      return '<span class="dvc-badge dvc-badge-new">Yes</span>';
    }
    if (row.answer === 'no') {
      return '<span class="dvc-badge dvc-badge-old">No</span>';
    }
    return '<span class="text-muted">—</span>';
  }

  function openedCell(row) {
    if (row.opened && row.opened_label) {
      return escapeHtml(row.opened_label);
    }
    if (row.opened) {
      return 'Opened';
    }
    if (row.sent) {
      return '<span class="text-muted">Not opened</span>';
    }
    return '<span class="text-muted">Link not sent</span>';
  }

  function renderKpis(summary) {
    const sent = Number(summary.sent || 0);
    const opened = Number(summary.opened || 0);
    const yes = Number(summary.answered_yes || 0);
    const no = Number(summary.answered_no || 0);
    setText('kpiDonors', Number(summary.donors || 0).toLocaleString());
    setText('kpiSent', sent.toLocaleString());
    setText('kpiOpened', opened.toLocaleString());
    setText('kpiNotOpened', Number(summary.not_opened || 0).toLocaleString());
    setText('kpiAnswered', Number(summary.answered || 0).toLocaleString());
    setText('kpiAnsweredMeta', yes.toLocaleString() + ' yes · ' + no.toLocaleString() + ' no');
    setText('kpiBooked', Number(summary.booked || 0).toLocaleString());
  }

  function renderFilters() {
    document.querySelectorAll('[data-filter]').forEach(function (btn) {
      const active = btn.getAttribute('data-filter') === filter;
      btn.classList.toggle('active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  }

  function renderTable(data) {
    const body = document.getElementById('dataBody');
    const rows = data.rows || [];
    if (rows.length === 0) {
      body.innerHTML = '<tr><td colspan="' + COLSPAN + '"><div class="dvc-empty-state"><i class="fas fa-inbox"></i><p>No donors match this view.</p></div></td></tr>';
      return;
    }
    const startNum = (data.page - 1) * data.per_page + 1;
    body.innerHTML = rows.map(function (r, i) {
      const name = escapeHtml(r.name || 'Unknown');
      const id = Number(r.donor_id || r.id || 0);
      const link = id
        ? '<a class="dvc-donor-link" href="../donor-management/view-donor.php?id=' + id + '">' + name + '</a>'
        : name;
      const phone = r.phone
        ? escapeHtml(r.phone)
        : '<span class="dvc-no-phone">No phone</span>';
      const booked = r.booked && r.booking_label
        ? escapeHtml(r.booking_label)
        : '<span class="text-muted">—</span>';
      return '<tr>'
        + '<td class="dvc-col-num" data-label="#">' + (startNum + i) + '</td>'
        + '<td data-label="Donor"><div>' + link + '</div><small class="text-muted">' + phone + '</small></td>'
        + '<td data-label="Opened">' + openedCell(r) + '</td>'
        + '<td data-label="Answer">' + answerBadge(r) + '</td>'
        + '<td data-label="Booked time">' + booked + '</td>'
        + '</tr>';
    }).join('');
  }

  function renderPagination(data) {
    const ul = document.getElementById('pagination');
    const info = document.getElementById('paginationInfo');
    const total = data.total_count || 0;
    const from = total === 0 ? 0 : (data.page - 1) * data.per_page + 1;
    const to = Math.min(data.page * data.per_page, total);
    info.innerHTML = 'Showing <strong>' + from + '</strong>–<strong>' + to + '</strong> of <strong>' + total + '</strong>';
    if (data.total_pages <= 1) {
      ul.innerHTML = '';
      return;
    }
    let html = '';
    if (data.page > 1) {
      html += '<li class="page-item"><a class="page-link" href="#" data-page="' + (data.page - 1) + '" aria-label="Previous"><i class="fas fa-angle-left"></i></a></li>';
    }
    const start = Math.max(1, data.page - 2);
    const end = Math.min(data.total_pages, data.page + 2);
    for (let i = start; i <= end; i++) {
      html += '<li class="page-item ' + (i === data.page ? 'active' : '') + '"><a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
    }
    if (data.page < data.total_pages) {
      html += '<li class="page-item"><a class="page-link" href="#" data-page="' + (data.page + 1) + '" aria-label="Next"><i class="fas fa-angle-right"></i></a></li>';
    }
    ul.innerHTML = html;
    ul.querySelectorAll('a[data-page]').forEach(function (a) {
      a.addEventListener('click', function (e) {
        e.preventDefault();
        load(parseInt(a.getAttribute('data-page'), 10));
      });
    });
  }

  async function load(nextPage) {
    page = nextPage || 1;
    const body = document.getElementById('dataBody');
    body.innerHTML = '<tr><td colspan="' + COLSPAN + '" class="text-center py-4" style="color:var(--gray-500)"><div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading...</td></tr>';
    renderFilters();
    try {
      const res = await fetch(buildUrl(), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const data = await res.json();
      if (!res.ok || data.success === false) {
        throw new Error(data.error || 'Request failed');
      }
      renderKpis(data.summary || {});
      renderTable(data);
      renderPagination(data);
    } catch (err) {
      body.innerHTML = '<tr><td colspan="' + COLSPAN + '" class="text-center py-4" style="color:var(--danger)">Failed to load the report.</td></tr>';
    }
  }

  document.querySelectorAll('[data-filter]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      filter = btn.getAttribute('data-filter') || 'all';
      load(1);
    });
  });
  document.getElementById('applyFilters').addEventListener('click', function () {
    load(1);
  });
  document.getElementById('clearFilters').addEventListener('click', function () {
    document.getElementById('filterDonor').value = '';
    filter = 'all';
    load(1);
  });
  document.getElementById('perPage').addEventListener('change', function () {
    load(1);
  });
  document.getElementById('filterDonor').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') load(1);
  });

  load(1);
})();
