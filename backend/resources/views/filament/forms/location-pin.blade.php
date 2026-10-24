@php
    $latPath = $getStatePath();
    $lngPath = \Illuminate\Support\Str::beforeLast($latPath, '.').'.lng';
@endphp
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:ignore
        x-data="bentormPin({ lat: $wire.$entangle(@js($latPath)), lng: $wire.$entangle(@js($lngPath)) })"
        x-init="boot($refs.map)"
        class="rounded-xl overflow-hidden border border-gray-200 dark:border-white/10"
        style="position: relative; z-index: 0; isolation: isolate;"
    >
        <div x-ref="map" style="height: 340px; width: 100%;"></div>
        <div class="px-3 py-2 text-xs text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-white/5">
            <span x-show="!lat">Tap the map where your store is. Drivers come to this exact spot.</span>
            <span x-show="lat">Pinned at <span x-text="Number(lat).toFixed(5) + ', ' + Number(lng).toFixed(5)"></span>. Drag the pin to adjust.</span>
        </div>
    </div>
</x-dynamic-component>

@once
<script>
window.bentormPin = window.bentormPin || function ({ lat, lng }) {
    return {
        lat, lng, marker: null,
        boot(el) {
            const start = () => {
                if (!window.L) return;
                const has = this.lat && this.lng;
                const map = L.map(el).setView(has ? [this.lat, this.lng] : [13.17, -59.55], has ? 16 : 10);
                el._pinMap = map;
                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
                const place = (ll) => {
                    if (!this.marker) {
                        this.marker = L.marker(ll, { draggable: true }).addTo(map);
                        this.marker.on('dragend', () => { const p = this.marker.getLatLng(); this.lat = +p.lat.toFixed(7); this.lng = +p.lng.toFixed(7); });
                    } else {
                        this.marker.setLatLng(ll);
                    }
                    this.lat = +ll.lat.toFixed(7); this.lng = +ll.lng.toFixed(7);
                };
                if (has) place(L.latLng(this.lat, this.lng));
                map.on('click', (e) => place(e.latlng));
                setTimeout(() => map.invalidateSize(), 200);
            };
            let tries = 0;
            const wait = () => window.L || tries++ > 40 ? start() : setTimeout(wait, 100);
            wait();
        },
    };
};
</script>
@endonce
