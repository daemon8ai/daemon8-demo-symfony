/**
 * Session-scoped BYOK storage. Keeps the provider API key in sessionStorage
 * only — it never lands in localStorage, a cookie, or outbound telemetry.
 * Cleared on tab close, cleared by the "Clear key" button.
 */

const STORAGE_KEY = 'daemon8-demo.byok';

export function loadKey() {
  try {
    const raw = sessionStorage.getItem(STORAGE_KEY);
    if (!raw) return null;
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

export function saveKey(record) {
  try {
    sessionStorage.setItem(STORAGE_KEY, JSON.stringify(record));
  } catch {
    // sessionStorage can be blocked in private browsing — best effort only.
  }
}

export function clearKey() {
  try {
    sessionStorage.removeItem(STORAGE_KEY);
  } catch {
    // ignore
  }
}
