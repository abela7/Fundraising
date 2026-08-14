(function () {
  const welcome = document.getElementById('payWelcome');
  const info = document.getElementById('payInfo');
  const button = document.getElementById('payContinue');
  if (!welcome || !info || !button) {
    return;
  }

  button.addEventListener('click', function () {
    welcome.classList.remove('is-active');
    welcome.hidden = true;
    info.hidden = false;
    info.classList.add('is-active');
    window.scrollTo(0, 0);
  });
})();
