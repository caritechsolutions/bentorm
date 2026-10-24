<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex">
@include('partials.favicon')
<title>{{ $trip->rider?->firstName() }}'s {{ $trip->order_id ? 'delivery' : 'ride' }} · Ride Coop</title>
<link rel="stylesheet" href="{{ asset('vendor/leaflet/leaflet.css') }}">
<script src="{{ asset('vendor/leaflet/leaflet.js') }}"></script>
<style>
  :root { --bg:#fff; --ink:#1f2328; --muted:#6b7280; --brand:#0e7bb6; --red:#ea483b; }
  @media (prefers-color-scheme: dark) { :root { --bg:#141a22; --ink:#e5e7eb; --muted:#9ca3af; } }
  html, body { margin:0; height:100%; font:16px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color:var(--ink); background:var(--bg); }
  #map { position:fixed; inset:0 0 168px 0; }
  .panel { position:fixed; left:0; right:0; bottom:0; min-height:168px; background:var(--bg); border-radius:18px 18px 0 0; box-shadow:0 -4px 20px rgba(0,0,0,.15);
           padding:16px 16px calc(16px + env(safe-area-inset-bottom)); box-sizing:border-box; }
  h1 { font-size:20px; margin:0 0 4px; } .muted { color:var(--muted); font-size:14px; } .row { margin-top:8px; font-size:15px; }
  .dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:8px; }
</style>
</head>
<body>
<div id="map"></div>
<div class="panel">
  <h1 id="title">Loading…</h1>
  <div class="muted" id="sub"></div>
  <div class="row"><span class="dot" style="background:var(--brand)"></span><span id="from"></span></div>
  <div class="row"><span class="dot" style="background:var(--red)"></span><span id="to"></span></div>
  <div class="muted" style="margin-top:10px">Shared from the Ride Coop app. This page stops updating an hour after the trip ends.</div>
</div>
<script>
const url = @json(url('/t/'.$token.'.json'));
const map = L.map('map', { zoomControl: false }).setView([13.1, -59.6], 12);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
const pin = (c) => L.circleMarker([0, 0], { radius: 8, color: '#fff', weight: 3, fillColor: c, fillOpacity: 1 });
const from = pin('#0e7bb6').addTo(map), to = pin('#ea483b').addTo(map), car = pin('#1f2328');
let fitted = false;
const titles = { scheduled: 'Booked for later', requested: 'Finding a driver', pending_payment: 'Finding a driver', accepted: 'Driver on the way',
  arrived: 'Driver has arrived', in_progress: 'On the trip', completed: 'Arrived safely', cancelled: 'Trip cancelled', no_drivers: 'Trip cancelled' };
async function tick() {
  try {
    const r = await fetch(url, { cache: 'no-store' });
    if (!r.ok) { document.getElementById('title').textContent = 'This link has ended'; return; }
    const t = await r.json();
    const who = t.rider ? t.rider + "'s " + t.kind : 'This ' + t.kind;
    document.getElementById('title').textContent = (titles[t.status] || t.status) + (t.status === 'in_progress' ? '' : '');
    document.getElementById('sub').textContent = who + (t.driver ? ' · Driver ' + t.driver : '') + (t.vehicle ? ' · ' + t.vehicle : '');
    document.getElementById('from').textContent = t.pickup.address || 'Pickup';
    document.getElementById('to').textContent = t.dropoff.address || 'Drop-off';
    from.setLatLng([t.pickup.lat, t.pickup.lng]); to.setLatLng([t.dropoff.lat, t.dropoff.lng]);
    const pts = [[t.pickup.lat, t.pickup.lng], [t.dropoff.lat, t.dropoff.lng]];
    if (t.driver_position) { car.setLatLng([t.driver_position.lat, t.driver_position.lng]).addTo(map); pts.push([t.driver_position.lat, t.driver_position.lng]); }
    else { car.remove(); }
    if (!fitted) { map.fitBounds(pts, { padding: [40, 40] }); fitted = true; }
  } catch (e) { /* offline: try again */ }
}
tick(); setInterval(tick, 5000);
</script>
</body>
</html>
