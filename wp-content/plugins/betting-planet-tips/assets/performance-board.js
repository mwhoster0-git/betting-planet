(() => {
  'use strict';
  function close(details) {
    details.open = false;
    details.querySelector('summary').focus({ preventScroll: true });
  }
  document.addEventListener('click', event => {
    const button = event.target.closest('[data-bpt-performance-board] .bpt-analysis-close');
    if (button) close(button.closest('details'));
  });
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const details = event.target.closest('[data-bpt-performance-board] details[open]');
    if (details) {
      event.preventDefault();
      close(details);
    }
  });
})();
