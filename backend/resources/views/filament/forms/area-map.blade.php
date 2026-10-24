@php
    $record = $getRecord();
    $others = \App\Models\ServiceArea::query()
        ->when($record, fn ($q) => $q->where('id', '!=', $record->id))
        ->get(['id', 'name', 'type', 'polygon'])
        ->map(fn ($a) => ['name' => $a->name, 'type' => $a->type->value, 'polygon' => $a->polygon])
        ->values();
@endphp
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:ignore
        x-data="bentormAreaMap({ state: $wire.$entangle(@js($getStatePath())), others: @js($others) })"
        x-init="boot($refs.map)"
        class="rounded-xl overflow-hidden border border-gray-200 dark:border-white/10"
    >
        <div x-ref="map" style="height: 520px; width: 100%; position: relative; z-index: 0; isolation: isolate;"></div>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 px-3 py-2 text-xs text-gray-600 dark:text-gray-400 bg-gray-50 dark:bg-white/5">
            <span>Draw with the polygon or rectangle tool (top left). Drag corners to adjust. Several shapes = one area with several parts.</span>
            <span x-show="!state" class="font-medium text-danger-600">No shape drawn yet.</span>
            <span x-show="ready === false" class="font-medium text-warning-600">Map library didn't load — check your connection.</span>
        </div>
    </div>
</x-dynamic-component>

@once
<script>
window.bentormAreaMap = window.bentormAreaMap || function ({ state, others }) {
    return {
        state, others, ready: null, map: null, group: null,
        boot(el) {
            const start = () => {
                if (!window.L || !window.L.PM) { this.ready = false; return; }
                this.ready = true;
                const map = this.map = L.map(el, { zoomControl: true }).setView([13.17, -59.55], 10);
                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap contributors' }).addTo(map);
                const styles = { service: '#0d9488', zone: '#d97706', blocked: '#dc2626' };
                (this.others || []).forEach(o => {
                    if (!o.polygon) return;
                    L.geoJSON(o.polygon, { pmIgnore: true, interactive: true, style: { color: styles[o.type] || '#6b7280', weight: 1, dashArray: '4 4', fillOpacity: 0.05 } })
                        .bindTooltip(o.name + ' (' + o.type + ')').addTo(map);
                });
                this.group = L.featureGroup().addTo(map);
                if (this.state && this.state.type) {
                    L.geoJSON(this.state, { style: { color: '#2563eb', weight: 2 } }).eachLayer(l => this.group.addLayer(l));
                    try { map.fitBounds(this.group.getBounds(), { padding: [20, 20] }); } catch (e) {}
                }
                map.pm.setGlobalOptions({ layerGroup: this.group, allowSelfIntersection: false, pathOptions: { color: '#2563eb', weight: 2 } });
                map.pm.addControls({ position: 'topleft', drawMarker: false, drawCircleMarker: false, drawPolyline: false, drawCircle: false,
                    drawText: false, cutPolygon: false, rotateMode: false, drawPolygon: true, drawRectangle: true, editMode: true, dragMode: true, removalMode: true });
                const sync = () => this.sync();
                map.on('pm:create', e => { this.group.addLayer(e.layer); e.layer.on('pm:edit pm:dragend', sync); sync(); });
                map.on('pm:remove', sync);
                this.group.eachLayer(l => l.on('pm:edit pm:dragend', sync));
                setTimeout(() => map.invalidateSize(), 200);
            };
            // Leaflet may still be loading on first paint.
            let tries = 0;
            const wait = () => (window.L && window.L.PM) || tries++ > 40 ? start() : setTimeout(wait, 100);
            wait();
        },
        sync() {
            const polys = [];
            this.group.eachLayer(l => {
                const g = l.toGeoJSON().geometry;
                if (g.type === 'Polygon') polys.push(g.coordinates);
                if (g.type === 'MultiPolygon') polys.push(...g.coordinates);
            });
            this.state = polys.length === 0 ? null
                : polys.length === 1 ? { type: 'Polygon', coordinates: polys[0] }
                : { type: 'MultiPolygon', coordinates: polys };
        },
    };
};
</script>
@endonce
