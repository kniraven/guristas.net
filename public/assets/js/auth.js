'use strict';
(() => {
  const dialog = document.getElementById('eveLoginDialog');
  if (!dialog) return;
  let opener = null;
  for (const button of document.querySelectorAll('[data-eve-login-open]')) {
    button.addEventListener('click', () => { opener = button; dialog.showModal(); });
  }
  dialog.querySelector('[data-eve-login-close]')?.addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
  dialog.addEventListener('close', () => opener?.focus());
})();
