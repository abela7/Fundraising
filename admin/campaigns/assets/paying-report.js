(function () {
  const COLSPAN = 6;
  const FILTERS = [
    'all',
    'sent',
    'not_opened',
    'opened',
    'answered',
    'booked',
    'pending',
    'contacted',
    'not_answering',
  ];
  const config = window.PAY_REPORT || {};
  let filter = FILTERS.indexOf(String(config.filter || 'all')) >= 0
    ? String(config.filter)
    : 'all';
  let page = Math.max(1, Number(config.page || 1) || 1);
  window.PAY_REPORT = config;
  window.PAY_REPORT.onStatusSaved = function () {
    load(page);
  };

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
    if (config.group) params.set('group', config.group);
    return 'api/paying-report.php?' + params.toString();
  }

  function syncPageUrl() {
    const params = new URLSearchParams();
    if (filter !== 'all') params.set('filter', filter);
    if (page > 1) params.set('page', String(page));
    const perPage = document.getElementById('perPage').value;
    if (perPage && perPage !== '25') params.set('per_page', perPage);
    const donor = document.getElementById('filterDonor').value.trim();
    if (donor) params.set('donor', donor);
    if (config.group) params.set('group', config.group);
    const qs = params.toString();
    const next = qs ? window.location.pathname + '?' + qs : window.location.pathname;
    window.history.replaceState({}, '', next);
  }

  function answerCell(row) {
    let html = answerBadge(row);
    const extras = [];
    if (row.answer === 'no' && row.reported_paid_label) {
      extras.push(escapeHtml(row.reported_paid_label) + ' paid so far');
    }
    if (row.answer === 'no' && row.paid_method_label) {
      extras.push(escapeHtml(row.paid_method_label));
    }
    if (row.answer === 'no' && row.cash_when_label) {
      extras.push('Paid ' + escapeHtml(row.cash_when_label));
    }
    if (row.answer === 'no' && row.cash_whom) {
      extras.push('To ' + escapeHtml(row.cash_whom));
    }
    if (row.answer === 'no' && row.cash_remember_label === 'I do not remember') {
      extras.push('Does not remember cash details');
    }
    if (row.answer === 'no' && row.send_proof_label) {
      extras.push(row.send_proof_label === 'Yes' ? 'Screenshot yes' : 'Screenshot no');
    }
    if (row.answer === 'no' && row.has_proof) {
      extras.push('Screenshot attached');
    }
    if (row.answer === 'no' && row.paid_date_label) {
      extras.push('Paid ' + escapeHtml(row.paid_date_label));
    }
    if (row.answer === 'no' && row.paid_remember_label === 'I do not remember') {
      extras.push('Does not remember the date');
    }
    if (row.phone_corrected && (row.call_phone || row.contact_phone)) {
      extras.push('Corrected ' + escapeHtml(row.call_phone || row.contact_phone));
    }
    if (extras.length) {
      html += '<div><small class="text-muted">' + extras.join(' · ') + '</small></div>';
    }
    return html;
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
    setText('kpiPending', Number(summary.call_pending || 0).toLocaleString());
    setText('kpiContacted', Number(summary.call_contacted || 0).toLocaleString());
    setText('kpiNotAnswering', Number(summary.call_not_answering || 0).toLocaleString());
  }

  function isCallFilter(value) {
    return value === 'booked'
      || value === 'pending'
      || value === 'contacted'
      || value === 'not_answering';
  }

  function renderFilters() {
    const callRow = document.getElementById('callStatusFilters');
    if (callRow) {
      callRow.classList.toggle('is-open', isCallFilter(filter));
    }
    document.querySelectorAll('[data-filter]').forEach(function (btn) {
      const key = btn.getAttribute('data-filter');
      let active = key === filter;
      if (key === 'booked' && isCallFilter(filter)) {
        active = true;
      }
      if (key === 'pending' || key === 'contacted' || key === 'not_answering') {
        active = key === filter;
      }
      btn.classList.toggle('active', active);
      btn.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  }

  function callStatusCell(row) {
    if (!row.booked) {
      return '<span class="text-muted">—</span>';
    }
    const status = row.call_status === 'contacted' || row.call_status === 'not_answering'
      ? row.call_status
      : 'pending';
    const id = Number(row.donor_id || row.id || 0);
    const options = [
      ['pending', 'Pending'],
      ['contacted', 'Contacted'],
      ['not_answering', 'Not answering'],
    ];
    return '<select class="form-select form-select-sm dvc-call-status" data-call-status-select data-donor-id="'
      + id
      + '" data-status="'
      + escapeHtml(status)
      + '" aria-label="Call status">'
      + options.map(function (opt) {
        return '<option value="' + opt[0] + '"' + (opt[0] === status ? ' selected' : '') + '>'
          + opt[1]
          + '</option>';
      }).join('')
      + '</select>';
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
        ? '<a class="dvc-donor-link" href="' + escapeHtml(config.activityUrl || 'pledge-paying-activity.php') + '?id=' + id + '">' + name + '</a>'
        : name;
      const storedPhone = r.phone
        ? escapeHtml(r.phone)
        : '<span class="dvc-no-phone">No phone</span>';
      const phone = r.phone_corrected && (r.call_phone || r.contact_phone)
        ? escapeHtml(r.call_phone || r.contact_phone)
          + ' <span class="dvc-badge dvc-badge-old">Corrected</span>'
        : storedPhone;
      const booked = r.booked && r.booking_label
        ? escapeHtml(r.booking_label)
        : '<span class="text-muted">—</span>';
      return '<tr>'
        + '<td class="dvc-col-num" data-label="#">' + (startNum + i) + '</td>'
        + '<td data-label="Donor"><div>' + link + '</div><small class="text-muted">' + phone + '</small></td>'
        + '<td data-label="Opened">' + openedCell(r) + '</td>'
        + '<td data-label="Answer">' + answerCell(r) + '</td>'
        + '<td data-label="Booked time">' + booked + '</td>'
        + '<td data-label="Status">' + callStatusCell(r) + '</td>'
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
      syncPageUrl();
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
