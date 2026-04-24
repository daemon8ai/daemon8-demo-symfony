/**
 * Welcome page entry. Wires the live observation panel, health probe,
 * "Try it" buttons, and scenario form into one orchestrated boot.
 */

import { initHealthProbe } from './health.js';
import { LivePanel } from './live-panel.js';
import { initScenario } from './scenario.js';

const cfg = window.__daemon8Demo || {};
const streamUrl = cfg.streamUrl || '/api/observations-stream';

document.addEventListener('DOMContentLoaded', () => {
  wireHealthProbe();
  const panel = wireLivePanel();
  wireTryButtons();
  initScenario();
  /** Expose for debugging in the browser console — not part of any public surface. */
  window.__daemon8LivePanel = panel;
});

function wireHealthProbe() {
  const dot = document.getElementById('status-dot');
  const label = document.getElementById('status-label');
  const tooltip = document.getElementById('status-tooltip');
  const troubleshoot = document.getElementById('daemon-troubleshoot');

  initHealthProbe({
    onStatusChange(status, detail) {
      dot.className = `status-dot status-dot--${status}`;
      label.textContent = status;
      tooltip.setAttribute(
        'content',
        status === 'healthy'
          ? 'Daemon reachable at ' + (cfg.daemonBaseUrl || '')
          : 'Daemon offline: ' + (detail?.error || 'unknown')
      );
      if (troubleshoot) troubleshoot.hidden = status !== 'offline';
    },
  });
}

function wireLivePanel() {
  const panel = new LivePanel({
    streamUrl,
    streamEl: document.getElementById('live-stream'),
    emptyEl: document.getElementById('live-empty'),
    filtersEl: document.getElementById('live-filters'),
    pauseEl: document.getElementById('live-pause'),
    clearEl: document.getElementById('live-clear'),
  });
  panel.connect();
  return panel;
}

function wireTryButtons() {
  document.querySelectorAll('[data-try]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const path = btn.getAttribute('data-try');
      btn.loading = true;
      try {
        const res = await fetch(path, { cache: 'no-store' });
        await res.text();
      } catch (err) {
        console.warn('route fetch failed', path, err);
      } finally {
        btn.loading = false;
      }
    });
  });
}
