/**
 * Daemon health probe. Fires a same-origin GET against the PHP proxy
 * (/health-proxy) so CORS never matters. Updates the header status dot +
 * tooltip + the offline alert's visibility based on the result.
 *
 * Re-probes every 15 seconds so the UI stays honest without a refresh.
 */

const PROBE_INTERVAL_MS = 15_000;

export function initHealthProbe({ onStatusChange }) {
  let current = 'unknown';

  async function probe() {
    try {
      const res = await fetch('/health-proxy', { cache: 'no-store' });
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      const body = await res.json();
      const next = body.status === 'ok' ? 'healthy' : 'offline';
      if (next !== current) {
        current = next;
        onStatusChange(current, body);
      }
    } catch (err) {
      if (current !== 'offline') {
        current = 'offline';
        onStatusChange(current, { error: err.message });
      }
    }
  }

  probe();
  const timer = setInterval(probe, PROBE_INTERVAL_MS);
  return () => clearInterval(timer);
}
