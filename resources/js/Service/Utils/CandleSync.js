import { ref, onBeforeUnmount } from 'vue';
import DBService from '@/Service/Utils/DBService.js';

// Starts a candle sync (the background job shared by the Charts page, the chart popup, Trades and the Stock Scanner), watches it
// until it ends, and tells you how it went. Only one sync runs at a time, so a second start just says so.
const POLL_MS = 1500;

export function useCandleSync(onFinished = null, maxMinutes = 30) {
    const syncing = ref(false);
    const note    = ref('');
    const noteOk  = ref(true);
    let timer = null;
    let tries = 0;

    function stop() { if (timer) { clearInterval(timer); timer = null; } }

    function watch() {
        tries = 0;
        stop();
        syncing.value = true;
        timer = setInterval(poll, POLL_MS);
    }

    function poll() {
        tries++;
        DBService.postData('/api/charts/sync-status', {}).then((st) => {
            if (!st.success) return;
            if (st.running && tries < (maxMinutes * 60000) / POLL_MS) return;
            stop();
            syncing.value = false;
            if (st.running) { note.value = 'Still running — check again in a moment'; noteOk.value = false; return; }

            // the run's outcome: a "- NSE:SYM: reason" line is a symbol that failed; no "Done:" line means it never finished
            const lines  = (st.log || '').split('\n').map(l => l.trim()).filter(Boolean);
            const done   = lines.find(l => l.startsWith('Done:'));
            const failed = lines.find(l => l.startsWith('- '));
            const bad    = !done ? (lines[lines.length - 1] || 'The sync did not finish') : (failed ? failed.replace(/^-\s*/, '') : null);
            noteOk.value = !bad;
            note.value   = bad ? bad : (done || 'Candles updated');
            if (onFinished) onFinished({ ok: !bad });
        });
    }

    /** Start a sync through `url` ('/api/charts/sync' for everything, '/api/trades/sync' for the active trades…). */
    function start(url = '/api/charts/sync', payload = {}) {
        if (syncing.value) return;
        syncing.value = true;
        note.value = '';
        DBService.postData(url, payload).then((data) => {
            if (!data.success) { syncing.value = false; note.value = data.message; noteOk.value = false; return; }
            watch();
        }).catch(() => { syncing.value = false; note.value = 'Could not start the sync'; noteOk.value = false; });
    }

    /** A sync may already be running (started elsewhere): pick it up. */
    function resume() {
        DBService.postData('/api/charts/sync-status', {}).then((st) => { if (st.success && st.running) watch(); });
    }

    onBeforeUnmount(stop);

    return { syncing, note, noteOk, start, resume };
}
