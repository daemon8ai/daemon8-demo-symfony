/**
 * Chaos/fixer scenario driver. POSTs the form to /scenario/start, pipes the
 * SSE response into two panes (chaos vs fixer) based on the event's `phase`.
 *
 * Uses fetch + ReadableStream to consume the SSE — EventSource doesn't
 * support POST. This keeps the key in the request body rather than the URL.
 */

import { loadKey, saveKey, clearKey } from './byok.js';

const ROLE_CHAOS = 'chaos';
const ROLE_FIXER = 'fixer';

export function initScenario() {
  const form = document.getElementById('scenario-form');
  const apiKeyInput = document.getElementById('scenario-api-key');
  const providerSelect = document.getElementById('scenario-provider');
  const scenarioSelect = document.getElementById('scenario-select');
  const runBtn = document.getElementById('scenario-run');
  const clearBtn = document.getElementById('scenario-clear');
  const logChaos = document.getElementById('scenario-log-chaos');
  const logFixer = document.getElementById('scenario-log-fixer');

  const saved = loadKey();
  if (saved?.apiKey) apiKeyInput.value = saved.apiKey;
  if (saved?.provider) providerSelect.value = saved.provider;
  if (saved?.scenario) scenarioSelect.value = saved.scenario;

  clearBtn.addEventListener('click', () => {
    apiKeyInput.value = '';
    clearKey();
  });

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const provider = providerSelect.value;
    const apiKey = apiKeyInput.value.trim();
    const scenario = scenarioSelect.value;
    if (!apiKey) {
      appendEntry(logChaos, 'error', 'API key required');
      return;
    }
    saveKey({ provider, apiKey, scenario });
    resetLog(logChaos);
    resetLog(logFixer);
    runBtn.loading = true;
    try {
      await streamScenario({ provider, apiKey, scenario }, logChaos, logFixer);
    } catch (err) {
      appendEntry(logChaos, 'error', err.message || String(err));
    } finally {
      runBtn.loading = false;
    }
  });
}

async function streamScenario(payload, logChaos, logFixer) {
  const res = await fetch('/scenario/start', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });

  if (!res.ok) {
    const body = await res.text();
    appendEntry(logChaos, 'error', `HTTP ${res.status} — ${body}`);
    return;
  }

  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';

  while (true) {
    const { value, done } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });
    let idx;
    while ((idx = buffer.indexOf('\n\n')) !== -1) {
      const frame = buffer.slice(0, idx);
      buffer = buffer.slice(idx + 2);
      const lines = frame.split('\n').filter(l => l.startsWith('data: '));
      if (lines.length === 0) continue;
      const dataLine = lines.map(l => l.slice(6)).join('\n');
      try {
        const event = JSON.parse(dataLine);
        routeEvent(event, logChaos, logFixer);
      } catch {
        // skip malformed frames
      }
    }
  }
}

function routeEvent(event, logChaos, logFixer) {
  const role = detectRole(event.phase);
  const target = role === ROLE_FIXER ? logFixer : logChaos;

  switch (event.phase) {
    case 'starting':
      appendEntry(logChaos, 'phase', `▶ scenario: ${event.scenario} (provider: ${event.provider})`);
      break;
    case 'observing':
      appendEntry(logChaos, 'phase', `… ${event.note}`);
      break;
    case 'observation':
      appendEntry(target, 'text', '→ observation: ' + summarize(event.observation));
      break;
    case 'chaos-text':
    case 'fixer-text':
      if ((event.text || '').trim()) appendEntry(target, 'text', event.text);
      break;
    case 'chaos-tool':
    case 'fixer-tool':
      appendEntry(target, 'tool', `🔧 ${event.tool} ← ${JSON.stringify(event.input)}`);
      break;
    case 'chaos-result':
    case 'fixer-result':
      appendEntry(target, 'result', `↩ ${event.tool}: ${JSON.stringify(event.response)}`);
      break;
    case 'resolved':
      appendEntry(logFixer, 'phase', '✓ resolved');
      break;
    case 'timeout':
    case 'tool-limit':
      appendEntry(target, 'error', `${event.phase}: ${event.reason}`);
      break;
    case 'error':
      appendEntry(logChaos, 'error', `error: ${event.message}`);
      break;
    default:
      appendEntry(target, 'phase', JSON.stringify(event));
  }
}

function detectRole(phase) {
  if (typeof phase !== 'string') return ROLE_CHAOS;
  if (phase.startsWith('fixer')) return ROLE_FIXER;
  if (phase === 'resolved') return ROLE_FIXER;
  return ROLE_CHAOS;
}

function summarize(observation) {
  if (!observation) return '';
  const kind = observation.kind || 'unknown';
  const sev = observation.severity || 'info';
  const msg = observation.data?.message || '';
  return `[${sev}] ${kind} — ${msg}`;
}

function resetLog(el) {
  el.innerHTML = '';
}

function appendEntry(el, variant, text) {
  const empty = el.querySelector('.scenario-log__empty');
  if (empty) empty.remove();
  const div = document.createElement('div');
  div.className = `entry entry--${variant}`;
  div.textContent = text;
  el.appendChild(div);
  el.scrollTop = el.scrollHeight;
}
