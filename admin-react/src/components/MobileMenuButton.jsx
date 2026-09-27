import { Menu } from 'lucide-react';

/*
 * Mobile-only hamburger button. On phones (<768px) it toggles the
 * `sidebar-open` class on the page's `.dashboard-container`, sliding the
 * sidebar drawer in/out. Hidden on desktop via CSS (`.menu-toggle`).
 */
export default function MobileMenuButton() {
  return (
    <button
      type="button"
      className="menu-toggle"
      data-menu-toggle
      aria-label="Open navigation menu"
      title="Open menu"
    >
      <Menu size={20} />
    </button>
  );
}