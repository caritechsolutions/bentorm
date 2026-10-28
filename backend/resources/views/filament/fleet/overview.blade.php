<x-filament-panels::page>
    @php $s = $this->status(); $fleet = $this->fleet(); $cur = $this->currency(); @endphp
    <x-filament::section>
        <div style="font-size:18px;font-weight:600;color:{{ $s['color'] }}">{{ $s['text'] }}</div>
        @foreach ($s['todo'] as $t)
            <div style="margin-top:6px">• {{ $t }}</div>
        @endforeach
    </x-filament::section>

    <div
        wire:ignore
        x-data="fleetLiveMap(@js(route('fleet.live', $fleet)), @js($cur))"
        x-init="boot($refs.map)"
        style="display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(280px,1fr))"
    >
        <div style="grid-column:1/-1;display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
            <x-filament::section>
                <div style="opacity:.7;font-size:13px">Cars on the road</div>
                <div style="font-size:28px;font-weight:700" x-text="cars.filter(c => c.state !== 'offline').length + ' of ' + cars.length"></div>
            </x-filament::section>
            <x-filament::section>
                <div style="opacity:.7;font-size:13px">Trips today</div>
                <div style="font-size:28px;font-weight:700" x-text="today.trips"></div>
            </x-filament::section>
            <x-filament::section>
                <div style="opacity:.7;font-size:13px">Earned today</div>
                <div style="font-size:28px;font-weight:700" x-text="currency + ' ' + Number(today.earnings).toFixed(2)"></div>
            </x-filament::section>
        </div>
        <div style="grid-column:span 2;min-width:0;border-radius:12px;overflow:hidden;border:1px solid rgba(127,127,127,.25)">
            <div x-ref="map" style="height:60vh;min-height:360px;width:100%;position:relative;z-index:0;isolation:isolate"></div>
        </div>
        <x-filament::section>
            <x-slot name="heading">Your cars</x-slot>
            <template x-if="cars.length === 0"><div style="opacity:.7">No cars yet.</div></template>
            <ul style="display:grid;gap:10px">
                <template x-for="c in cars" :key="c.id">
                    <li style="display:flex;gap:10px;align-items:flex-start">
                        <span :style="'margin-top:5px;width:10px;height:10px;border-radius:50%;flex:none;background:' + color(c.state)"></span>
                        <div>
                            <b x-text="c.plate"></b> <span style="opacity:.7" x-text="c.car"></span>
                            <div style="font-size:13px;opacity:.8" x-text="c.driver ? (c.driver + (c.state === 'busy' ? ' · on a trip' : ' · available')) : 'Not on the road'"></div>
                        </div>
                    </li>
                </template>
            </ul>
            <div style="font-size:12px;opacity:.6;margin-top:10px">Updates every 10 seconds · <span x-text="updated"></span></div>
        </x-filament::section>
    </div>

    @once
    <script>
    window.fleetLiveMap = window.fleetLiveMap || function (endpoint, currency) {
        return {
            endpoint, currency, cars: [], today: { trips: 0, earnings: 0 }, updated: '—', map: null, markers: {}, fitted: false,
            color(state) { return state === 'busy' ? '#2563eb' : (state === 'idle' ? '#16a34a' : '#9ca3af'); },
            boot(el) {
                let tries = 0;
                const start = () => {
                    if (window.L) {
                        this.map = L.map(el).setView([13.17, -59.55], 11);
                        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(this.map);
                        setTimeout(() => this.map.invalidateSize(), 200);
                    }
                    this.poll();
                };
                const wait = () => window.L || tries++ > 40 ? start() : setTimeout(wait, 100);
                wait();
            },
            async poll() {
                try {
                    const r = await fetch(this.endpoint, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                    if (r.ok) { const d = await r.json(); this.cars = d.cars; this.today = d.today; this.updated = new Date().toLocaleTimeString(); this.draw(); }
                } catch (e) {}
                setTimeout(() => this.poll(), 10000);
            },
            draw() {
                if (!this.map) return;
                const seen = {}, pts = [];
                this.cars.filter(c => c.lat !== null).forEach(c => {
                    seen[c.id] = true; pts.push([c.lat, c.lng]);
                    const color = this.color(c.state);
                    const label = `<b>${c.plate}</b> ${c.car}<br>${c.driver || ''}${c.phone ? '<br>' + c.phone : ''}`;
                    if (this.markers[c.id]) {
                        this.markers[c.id].setLatLng([c.lat, c.lng]).setStyle({ color, fillColor: color }).setPopupContent(label);
                    } else {
                        this.markers[c.id] = L.circleMarker([c.lat, c.lng], { radius: 9, color, fillColor: color, fillOpacity: 0.85, weight: 2 })
                            .bindPopup(label).bindTooltip(c.plate, { permanent: true, direction: 'top', offset: [0, -8] }).addTo(this.map);
                    }
                });
                Object.keys(this.markers).forEach(id => { if (!seen[id]) { this.map.removeLayer(this.markers[id]); delete this.markers[id]; } });
                if (!this.fitted && pts.length) { this.map.fitBounds(pts, { padding: [40, 40], maxZoom: 14 }); this.fitted = true; }
            },
        };
    };
    </script>
    @endonce
</x-filament-panels::page>
