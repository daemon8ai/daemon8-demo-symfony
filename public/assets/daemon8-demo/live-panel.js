/**
 * Live observation panel. EventSource-subscribes to the daemon's /api/stream
 * and renders each observation as a Shoelace card. Supports:
 *   - kind filter pills (click to toggle)
 *   - pause switch (stops rendering, keeps the stream alive)
 *   - clear button
 *   - auto-scroll lock when the user scrolls away from the top
 *
 * No framework. Just DOM, custom elements (Shoelace), and EventSource.
 */

const MAX_OBSERVATIONS = 200;

export class LivePanel {
  constructor({ streamUrl, streamEl, emptyEl, filtersEl, pauseEl, clearEl }) {
    this.streamUrl = streamUrl;
    this.streamEl = streamEl;
    this.emptyEl = emptyEl;
    this.filtersEl = filtersEl;
    this.pauseEl = pauseEl;
    this.clearEl = clearEl;
    this.filters = new Set();
    this.kindsSeen = new Set();
    this.paused = false;
    this.queued = [];
    this.es = null;

    this.pauseEl.addEventListener('sl-change', () => {
      this.paused = this.pauseEl.checked;
      if (!this.paused) this.drainQueue();
    });
    this.clearEl.addEventListener('click', () => this.clear());
  }

  connect() {
    if (this.es) return;
    this.es = new EventSource(this.streamUrl);

    this.es.addEventListener('error', () => {
      // EventSource auto-reconnects; nothing to do.
    });

    this.es.onmessage = (event) => {
      let payload;
      try { payload = JSON.parse(event.data); } catch { return; }
      if (this.paused) {
        this.queued.push(payload);
        if (this.queued.length > MAX_OBSERVATIONS) this.queued.shift();
        return;
      }
      this.render(payload);
    };
  }

  disconnect() {
    if (this.es) { this.es.close(); this.es = null; }
  }

  clear() {
    this.streamEl.querySelectorAll('.observation').forEach(el => el.remove());
    this.emptyEl.hidden = false;
    this.streamEl.appendChild(this.emptyEl);
  }

  drainQueue() {
    const drained = this.queued.splice(0);
    drained.forEach(p => this.render(p));
  }

  render(observation) {
    const kind = observation.kind || 'unknown';
    this.ensureKindFilter(kind);
    if (this.filters.size > 0 && !this.filters.has(kind)) {
      return;
    }

    if (this.emptyEl && !this.emptyEl.hidden) {
      this.emptyEl.hidden = true;
    }

    const card = document.createElement('sl-card');
    card.className = 'observation observation--' + (observation.severity || 'info') + ' entering';
    card.setAttribute('data-kind', kind);

    const capturedAt = observation.captured_at
      ? new Date(observation.captured_at).toISOString()
      : new Date().toISOString();
    const channel = observation.channel
      || observation.origin?.kind
      || 'stream';

    card.innerHTML = `
      <div>
        <sl-badge variant="primary" pill>${escapeHtml(kind)}</sl-badge>
        <sl-badge variant="neutral" pill>${escapeHtml(channel)}</sl-badge>
        <small><sl-relative-time date="${capturedAt}"></sl-relative-time></small>
      </div>
      <pre>${escapeHtml(JSON.stringify(observation.data ?? observation, null, 2))}</pre>
    `;

    this.streamEl.prepend(card);

    const overflow = this.streamEl.querySelectorAll('.observation');
    if (overflow.length > MAX_OBSERVATIONS) {
      for (let i = MAX_OBSERVATIONS; i < overflow.length; i++) overflow[i].remove();
    }
  }

  ensureKindFilter(kind) {
    if (this.kindsSeen.has(kind)) return;
    this.kindsSeen.add(kind);

    const badge = document.createElement('sl-badge');
    badge.setAttribute('pill', '');
    badge.setAttribute('variant', 'neutral');
    badge.setAttribute('data-active', 'true');
    badge.textContent = kind;
    badge.addEventListener('click', () => {
      const active = badge.getAttribute('data-active') === 'true';
      badge.setAttribute('data-active', active ? 'false' : 'true');
      if (active) this.filters.add(kind);
      else this.filters.delete(kind);
    });
    this.filtersEl.appendChild(badge);
  }
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
