(function () {
  const config = window.PAY_SYNC || {};
  const steps = Array.isArray(config.steps) && config.steps.length
    ? config.steps
    : ['welcome', 'info'];
  const screens = {};
  document.querySelectorAll('[data-pay-step]').forEach(function (el) {
    screens[el.getAttribute('data-pay-step')] = el;
  });
  if (!screens.welcome) {
    return;
  }

  const state = {
    token: config.token || '',
    sign: config.sign || '',
    saveUrl: config.saveUrl || '',
    step: steps.indexOf(config.step) >= 0 ? config.step : steps[0],
    answers: config.answers && typeof config.answers === 'object' ? config.answers : {},
    revision: Number(config.revision || 0)
  };

  let saveTimer = 0;
  let saving = false;
  let saveAgain = false;

  function currentIndex() {
    const idx = steps.indexOf(state.step);
    return idx < 0 ? 0 : idx;
  }

  function applyFields() {
    document.querySelectorAll('[data-pay-field]').forEach(function (el) {
      const key = el.getAttribute('data-pay-field');
      if (!key || !Object.prototype.hasOwnProperty.call(state.answers, key)) {
        return;
      }
      const value = state.answers[key];
      if (el.type === 'checkbox') {
        el.checked = value === true || value === 1 || value === '1';
        return;
      }
      if (el.type === 'radio') {
        el.checked = String(el.value) === String(value);
        return;
      }
      el.value = value == null ? '' : String(value);
    });
  }

  function readFields() {
    document.querySelectorAll('[data-pay-field]').forEach(function (el) {
      const key = el.getAttribute('data-pay-field');
      if (!key) {
        return;
      }
      if (el.type === 'checkbox') {
        state.answers[key] = !!el.checked;
        return;
      }
      if (el.type === 'radio') {
        if (el.checked) {
          state.answers[key] = el.value;
        }
        return;
      }
      state.answers[key] = el.value;
    });
  }

  function updateNav() {
    const idx = currentIndex();
    document.querySelectorAll('[data-pay-back]').forEach(function (btn) {
      btn.hidden = idx <= 0;
    });
    document.querySelectorAll('[data-pay-next]').forEach(function (btn) {
      btn.hidden = idx >= steps.length - 1;
    });
  }

  function showStep(step, fromHistory) {
    if (steps.indexOf(step) < 0) {
      step = steps[0];
    }
    state.step = step;
    steps.forEach(function (name) {
      const screen = screens[name];
      if (!screen) {
        return;
      }
      const active = name === step;
      screen.hidden = !active;
      screen.classList.toggle('is-active', active);
    });
    updateNav();
    if (!fromHistory) {
      const hash = '#' + step;
      if (location.hash !== hash) {
        history.pushState({ step: step }, '', hash);
      }
    }
    window.scrollTo(0, 0);
    queueSave();
  }

  function payload() {
    readFields();
    return {
      token: state.token,
      sign: state.sign,
      step: state.step,
      answers: state.answers,
      revision: state.revision
    };
  }

  function queueSave() {
    window.clearTimeout(saveTimer);
    saveTimer = window.setTimeout(function () {
      flushSave(false);
    }, 400);
  }

  function flushSave(useBeacon) {
    if (!state.token || !state.sign || !state.saveUrl) {
      return;
    }
    readFields();
    const body = JSON.stringify(payload());
    if (useBeacon === true && navigator.sendBeacon) {
      navigator.sendBeacon(state.saveUrl, new Blob([body], { type: 'application/json' }));
      return;
    }
    if (saving) {
      saveAgain = true;
      return;
    }
    saving = true;
    fetch(state.saveUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: body,
      keepalive: true
    }).then(function (res) {
      return res.json().then(function (data) {
        return { res: res, data: data };
      });
    }).then(function (result) {
      if (result.res.ok && result.data && result.data.success) {
        state.revision = Number(result.data.revision || state.revision);
        if (result.data.answers && typeof result.data.answers === 'object') {
          state.answers = result.data.answers;
        }
      }
    }).catch(function () {
      // Keep local answers; the next change retries.
    }).then(function () {
      saving = false;
      if (saveAgain) {
        saveAgain = false;
        flushSave(false);
      }
    });
  }

  document.querySelectorAll('[data-pay-next]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const next = steps[currentIndex() + 1];
      if (next) {
        showStep(next, false);
      }
    });
  });
  document.querySelectorAll('[data-pay-back]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const prev = steps[currentIndex() - 1];
      if (prev) {
        showStep(prev, false);
      }
    });
  });
  document.querySelectorAll('[data-pay-field]').forEach(function (el) {
    el.addEventListener('input', queueSave);
    el.addEventListener('change', queueSave);
  });

  window.addEventListener('popstate', function (event) {
    const step = (event.state && event.state.step)
      || (location.hash ? location.hash.replace('#', '') : steps[0]);
    showStep(step, true);
  });
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      flushSave(true);
    }
  });
  window.addEventListener('pagehide', function () {
    flushSave(true);
  });

  applyFields();
  const hashStep = location.hash ? location.hash.replace('#', '') : '';
  const start = steps.indexOf(hashStep) >= 0 ? hashStep : state.step;
  history.replaceState({ step: steps[0] }, '', '#' + steps[0]);
  if (start !== steps[0]) {
    history.pushState({ step: start }, '', '#' + start);
  }
  showStep(start, true);
})();
