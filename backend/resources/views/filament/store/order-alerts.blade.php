{{-- New-order alert for the store portal: polls every 8 s on every page, rings and shows a banner when an order arrives. --}}
<div x-data="bentormOrderAlerts(@js($url), @js($ordersUrl))" x-init="start()" x-cloak>
    <style>@keyframes rcPulse{0%,100%{opacity:1}50%{opacity:.75}}</style>
    <div style="position:fixed;left:16px;right:16px;bottom:16px;z-index:60;display:flex;flex-direction:column;align-items:flex-end;gap:8px;pointer-events:none">
        <a x-show="pending > 0" :href="ordersUrl" style="pointer-events:auto;background:#dc2626;color:#fff;padding:12px 20px;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,.25);font-weight:600;text-decoration:none;animation:rcPulse 1.2s ease-in-out infinite">
            <span x-text="pending === 1 ? '1 new order — tap to answer' : pending + ' new orders — tap to answer'"></span>
        </a>
        <div x-show="!soundOn" style="pointer-events:auto;display:flex;align-items:center;gap:12px;background:#111827;color:#fff;padding:8px 8px 8px 16px;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,.25);font-size:14px;max-width:100%">
            <span>Turn on the order sound so you never miss an order.</span>
            <button type="button" x-on:click="enableSound()" style="background:#fbbf24;color:#111827;font-weight:600;padding:6px 12px;border-radius:8px;border:0;cursor:pointer;white-space:nowrap">Turn on sound</button>
        </div>
    </div>
</div>
@once
<script>
window.bentormOrderAlerts = window.bentormOrderAlerts || function (url, ordersUrl) {
    return {
        url, ordersUrl, pending: 0, soundOn: false, ctx: null, seen: new Set(), timer: null, baseTitle: document.title,
        start() {
            try { this.soundOn = localStorage.getItem('rc_order_sound') === '1'; } catch (e) {}
            try { JSON.parse(sessionStorage.getItem('rc_seen_orders') || '[]').forEach(id => this.seen.add(id)); } catch (e) {}
            this.check();
            this.timer = setInterval(() => this.check(), 8000);
        },
        enableSound() {
            this.soundOn = true;
            try { localStorage.setItem('rc_order_sound', '1'); } catch (e) {}
            this.beep(1); // a user tap unlocks audio in the browser
        },
        async check() {
            try {
                const r = await fetch(this.url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                if (!r.ok) return;
                const d = await r.json();
                this.pending = d.placed.length;
                const fresh = d.placed.filter(id => !this.seen.has(id));
                fresh.forEach(id => this.seen.add(id));
                try { sessionStorage.setItem('rc_seen_orders', JSON.stringify([...this.seen].slice(-200))); } catch (e) {}
                if (fresh.length) this.ring();
                document.title = this.pending ? '(' + this.pending + ') New order — ' + this.baseTitle : this.baseTitle;
            } catch (e) {}
        },
        ring() {
            this.beep(4);
            if (window.Notification && Notification.permission === 'granted') {
                try { new Notification('New Ride Coop order', { body: 'Open the store portal to accept it.' }); } catch (e) {}
            } else if (window.Notification && Notification.permission === 'default') {
                Notification.requestPermission().catch(() => {});
            }
        },
        beep(times) {
            if (!this.soundOn) return;
            try {
                this.ctx = this.ctx || new (window.AudioContext || window.webkitAudioContext)();
                const t0 = this.ctx.currentTime;
                for (let i = 0; i < times; i++) {
                    [880, 1320].forEach((f, k) => {
                        const o = this.ctx.createOscillator(), g = this.ctx.createGain();
                        o.frequency.value = f; o.type = 'sine';
                        const s = t0 + i * 0.9 + k * 0.18;
                        g.gain.setValueAtTime(0.0001, s); g.gain.exponentialRampToValueAtTime(0.4, s + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, s + 0.16);
                        o.connect(g).connect(this.ctx.destination); o.start(s); o.stop(s + 0.18);
                    });
                }
            } catch (e) {}
        },
    };
};
</script>
@endonce
