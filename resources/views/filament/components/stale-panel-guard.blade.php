{{-- Stale-panel guard (every panel page, via the panels::body.end render hook).

     A tab left open across a deploy keeps posting its OLD Livewire snapshot:
     $wire.getBotData() every 30s on «مانیتورینگ زنده», wire:poll on the
     database-notifications bell (30s), widget polling, … The server answers
     with a 4xx/5xx (locked property, type mismatch on hydrate, checksum, 419)
     every time, which filled the error log (~230 ERRORs/week).

     On the first such failure this guard:
       - suppresses Livewire's error modal for it,
       - sets window.__atPanelStale and dispatches `at-panel-stale` so page
         scripts stop their intervals,
       - stops every wire:poll on the page,
       - shows ONE small non-blocking banner with a reload button.
     It never reloads by itself. --}}
<div id="at-stale-banner" class="at-stale-banner" role="status" aria-live="polite" hidden>
    <span class="at-stale-banner__text">نسخه جدید پنل منتشر شد — صفحه را تازه کنید</span>
    <button type="button" class="at-btn at-btn--accent" onclick="window.location.reload()">تازه‌سازی</button>
</div>

<style>
    .at-stale-banner {
        position: fixed;
        inset-inline: 16px;
        bottom: 16px;
        z-index: 60;
        margin-inline: auto;
        max-width: 520px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: var(--at-gap-md, 8px);
        padding: var(--at-gap-md, 8px) var(--at-gap-lg, 11px);
        background: var(--at-surface, #101a2a);
        border: 1px solid var(--at-warn, #D9A441);
        border-radius: var(--at-radius-sm, 11px);
        color: var(--at-text, #edf4ff);
        font-size: var(--at-fs-body, 13.5px);
        line-height: 1.5;
        box-shadow: 0 6px 24px rgba(0, 0, 0, 0.35);
    }
    .at-stale-banner[hidden] { display: none; }
    .at-stale-banner__text { min-width: 0; }
    .at-stale-banner .at-btn { flex: none; }
</style>

<script>
    (function () {
        if (window.__atStaleGuardInstalled) return;
        window.__atStaleGuardInstalled = true;
        window.__atPanelStale = false;

        function stopWirePolls() {
            // Livewire's poll directive re-checks on every tick whether the
            // element still carries wire:poll and pauses while it does not;
            // removing the attribute therefore stops each poll for good.
            document.querySelectorAll('*').forEach(function (el) {
                for (const attr of Array.from(el.attributes)) {
                    if (attr.name === 'wire:poll' || attr.name.startsWith('wire:poll.')) {
                        el.removeAttribute(attr.name);
                    }
                }
            });
        }

        function markStale(reason) {
            if (window.__atPanelStale) return;
            window.__atPanelStale = true;
            try { console.warn('[stale-panel] Livewire request failed; polling stopped', reason); } catch (e) {}
            stopWirePolls();
            window.dispatchEvent(new CustomEvent('at-panel-stale', { detail: reason }));
            const banner = document.getElementById('at-stale-banner');
            if (banner) banner.hidden = false;
        }

        // Exposed for manual checks and for page scripts.
        window.atMarkPanelStale = markStale;

        function install() {
            if (!window.Livewire || typeof window.Livewire.hook !== 'function') return false;
            window.Livewire.hook('request', function ({ fail }) {
                fail(function ({ status, content, preventDefault }) {
                    // Livewire reports a fetch() that never reached the server
                    // (offline, DNS) as status 503 with content === null: that
                    // is a network blip, not a stale snapshot — leave it alone.
                    if (content === null || typeof status !== 'number' || status < 400) return;
                    // The banner is the one message: no Livewire error modal,
                    // no "page expired" confirm (419), now or later.
                    preventDefault();
                    markStale({ status: status });
                });
            });
            return true;
        }

        if (!install()) {
            document.addEventListener('livewire:init', install, { once: true });
        }
    })();
</script>
