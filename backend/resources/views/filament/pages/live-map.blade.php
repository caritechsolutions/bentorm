<x-filament-panels::page>
    <div
        wire:ignore
        x-data="bentormLiveMap(@js(route('admin.live')), @js(url('/admin/trips')))"
        x-init="boot($refs.map)"
        class="grid gap-4 lg:grid-cols-4"
    >
        <div class="lg:col-span-3 rounded-xl overflow-hidden border border-gray-200 dark:border-white/10">
            <div x-ref="map" style="height: 70vh; min-height: 420px; width: 100%; position: relative; z-index: 0; isolation: isolate;"></div>
        </div>
        <div class="space-y-3 text-sm">
            <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 bg-white dark:bg-gray-900">
                <div class="text-gray-500 dark:text-gray-400">Drivers online</div>
                <div class="text-3xl font-semibold" x-text="drivers.length"></div>
                <div class="mt-2 flex gap-3 text-xs">
                    <span><span class="inline-block w-2.5 h-2.5 rounded-full" style="background:#16a34a"></span> available <b x-text="drivers.filter(d => d.state !== 'busy').length"></b></span>
                    <span><span class="inline-block w-2.5 h-2.5 rounded-full" style="background:#2563eb"></span> on a trip <b x-text="drivers.filter(d => d.state === 'busy').length"></b></span>
                </div>
            </div>
            <div class="rounded-xl border border-gray-200 dark:border-white/10 p-4 bg-white dark:bg-gray-900">
                <div class="text-gray-500 dark:text-gray-400 mb-2">Active trips <b x-text="trips.length"></b></div>
                <template x-if="trips.length === 0"><div class="text-gray-400">None right now.</div></template>
                <ul class="space-y-2">
                    <template x-for="t in trips" :key="t.id">
                        <li>
                            <a :href="tripsUrl + '/' + t.id" class="block rounded-lg px-2 py-1.5 hover:bg-gray-50 dark:hover:bg-white/5">
                                <span class="font-mono text-xs" x-text="t.id.slice(-8)"></span>
                                <span class="ml-1 text-xs rounded px-1.5 py-0.5 bg-gray-100 dark:bg-white/10" x-text="t.status.replace('_', ' ')"></span>
                                <div class="text-xs text-gray-500" x-text="(t.rider || '') + (t.driver ? ' · ' + t.driver : ' · waiting for driver')"></div>
                            </a>
                        </li>
                    </template>
                </ul>
            </div>
            <div class="text-xs text-gray-400">Updates every 5 seconds · <span x-text="updated"></span></div>
            <div x-show="ready === false" class="text-xs font-medium text-warning-600">Map library didn't load — check your connection.</div>
        </div>
    </div>

    @once
    <script>
    window.bentormLiveMap = window.bentormLiveMap || function (endpoint, tripsUrl) {
        return {
            endpoint, tripsUrl, drivers: [], trips: [], updated: '—', ready: null, map: null, markers: {}, pins: null,
            boot(el) {
                let tries = 0;
                const start = () => {
                    if (!window.L) { this.ready = false; this.poll(); return; }
                    this.ready = true;
                    this.map = L.map(el).setView([13.17, -59.55], 11);
                    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(this.map);
                    this.pins = L.layerGroup().addTo(this.map);
                    this.poll();
                    setTimeout(() => this.map.invalidateSize(), 200);
                };
                const wait = () => window.L || tries++ > 40 ? start() : setTimeout(wait, 100);
                wait();
            },
            async poll() {
                try {
                    const r = await fetch(this.endpoint, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                    if (r.ok) { const d = await r.json(); this.drivers = d.drivers; this.trips = d.trips; this.updated = new Date().toLocaleTimeString(); this.draw(); }
                } catch (e) {}
                setTimeout(() => this.poll(), 5000);
            },
            draw() {
                if (!this.map) return;
                const seen = {};
                this.drivers.forEach(d => {
                    seen[d.driver_id] = true;
                    const color = d.state === 'busy' ? '#2563eb' : '#16a34a';
                    const label = `<b>${d.name}</b><br>${d.vehicle ? d.vehicle.plate + ' · ' + d.vehicle.make + ' ' + d.vehicle.model : ''}<br>${d.phone}`;
                    if (this.markers[d.driver_id]) {
                        this.markers[d.driver_id].setLatLng([d.lat, d.lng]).setStyle({ color, fillColor: color }).setPopupContent(label);
                    } else {
                        this.markers[d.driver_id] = L.circleMarker([d.lat, d.lng], { radius: 8, color, fillColor: color, fillOpacity: 0.85, weight: 2 }).bindPopup(label).addTo(this.map);
                    }
                });
                Object.keys(this.markers).forEach(id => { if (!seen[id]) { this.map.removeLayer(this.markers[id]); delete this.markers[id]; } });
                this.pins.clearLayers();
                this.trips.forEach(t => {
                    L.circleMarker([t.pickup.lat, t.pickup.lng], { radius: 5, color: '#d97706', fillColor: '#d97706', fillOpacity: 1 })
                        .bindTooltip('Pickup · ' + t.status.replace('_', ' ')).addTo(this.pins);
                    if (t.status === 'in_progress') {
                        L.circleMarker([t.dropoff.lat, t.dropoff.lng], { radius: 5, color: '#7c3aed', fillColor: '#7c3aed', fillOpacity: 1 }).bindTooltip('Drop-off').addTo(this.pins);
                    }
                });
            },
        };
    };
    </script>
    @endonce
</x-filament-panels::page>
