/*
 * Mobile shell behaviour — one delegated click handler for every page that
 * uses the shared `.dashboard-container` shell.
 *
 *  - Clicking any `[data-menu-toggle]` button opens/closes the drawer.
 *  - While the drawer is open, clicking outside it (the dimmed backdrop)
 *    closes it. Clicks inside the drawer or on dialog overlays are ignored
 *    so navigation and modals keep working.
 *
 * Included by main.jsx; nothing needs to be wired per page.
 */
function isInside(target, selector) {
  return Boolean(target && typeof target.closest === 'function' && target.closest(selector));
}

function initMobileShell() {
  document.addEventListener('click', (event) => {
    if (isInside(event.target, '[data-menu-toggle]')) {
      const container = event.target.closest('.dashboard-container');
      if (container) container.classList.toggle('sidebar-open');
      return;
    }

    const container = isInside(event.target, '.dashboard-container')
      ? event.target.closest('.dashboard-container')
      : null;

    if (container && container.classList.contains('sidebar-open')) {
      const insideDrawer = isInside(event.target, '.sidebar');
      const insideDialog = isInside(event.target, '.settings-modal-overlay, .um-modal-overlay, .sr-modal-overlay');
      if (!insideDrawer && !insideDialog) {
        container.classList.remove('sidebar-open');
      }
    }
  });
}

initMobileShell();