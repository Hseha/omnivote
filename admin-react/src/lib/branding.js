import { useEffect, useState } from 'react';
import api from './api';

/*
 * Public branding (site name, colors, logo, favicon) served by GET /api/branding.
 *
 * The login screen and global chrome render before authentication, so this is
 * fetched once at boot and applied straight to the document (browser title,
 * favicon, CSS variables). Components subscribe through useBranding().
 */

export const DEFAULT_BRANDING = {
  siteName: 'OmniVote',
  logoUrl: '',
  primaryColor: '#2563eb',
  secondaryColor: '#64748b',
  faviconUrl: '',
  headerText: 'Secure Election Platform',
  footerText: 'Powered by OmniVote Administration Console',
};

let currentBranding = { ...DEFAULT_BRANDING };
let listeners = [];
let loadPromise = null;

function emit() {
  listeners.forEach((fn) => fn(currentBranding));
}

/** Apply branding to the browser chrome: favicon + theme colors.
 * The document <title> is deliberately NOT set here — the app shell owns it
 * and composes per-route titles ("Dashboard · OmniVote"), so a late-arriving
 * branding response must not overwrite the current page title with a bare
 * site name. */
function applyToDocument(branding) {
  let icon = document.querySelector('link[rel="icon"]');
  if (icon && branding.faviconUrl) {
    icon.href = branding.faviconUrl;
  }

  const root = document.documentElement;
  if (branding.primaryColor) root.style.setProperty('--brand-primary', branding.primaryColor);
  if (branding.secondaryColor) root.style.setProperty('--brand-secondary', branding.secondaryColor);
}

/** Fetch branding once; failures fall back to the hardcoded defaults. */
export function loadBranding() {
  if (!loadPromise) {
    loadPromise = api
      .get('/branding')
      .then((res) => {
        currentBranding = {
          ...DEFAULT_BRANDING,
          ...(res.data?.branding ?? {}),
        };
        applyToDocument(currentBranding);
        emit();
        return currentBranding;
      })
      .catch(() => {
        currentBranding = { ...DEFAULT_BRANDING };
        return currentBranding;
      });
  }
  return loadPromise;
}

export function getBranding() {
  return currentBranding;
}

/** Re-fetch branding (e.g. after saving Settings), then re-apply to the chrome. */
export function refreshBranding() {
  loadPromise = null;
  return loadBranding();
}

export function useBranding() {
  const [branding, setBranding] = useState(currentBranding);

  useEffect(() => {
    listeners.push(setBranding);
    return () => {
      listeners = listeners.filter((fn) => fn !== setBranding);
    };
  }, []);

  return branding;
}