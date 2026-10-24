// Ride Coop load test (k6 — https://k6.io). Simulates the traffic the apps really make:
//   drivers: online → a location ping and an offers check every few seconds (the biggest load by far)
//   riders:  open the app (config) → price a ride → book it → watch it for a while → cancel
// Run against a COPY of the server, never production. See scripts/loadtest/README.md.
//
//   k6 run -e BASE=https://test.ridecoop.net -e TOKENS=tokens.json -e DRIVERS=200 -e RIDERS_PER_MIN=60 ridecoop.js
import http from 'k6/http';
import { check, sleep } from 'k6';
import { SharedArray } from 'k6/data';
import { Trend } from 'k6/metrics';

const BASE = (__ENV.BASE || 'http://127.0.0.1:8099').replace(/\/$/, '') + '/api/v1';
const tokens = JSON.parse(open(__ENV.TOKENS || 'tokens.json'));
const driverTokens = new SharedArray('drivers', () => tokens.drivers);
const riderTokens = new SharedArray('riders', () => tokens.riders);
const DRIVERS = Math.min(Number(__ENV.DRIVERS || driverTokens.length), driverTokens.length);
const RIDERS_PER_MIN = Number(__ENV.RIDERS_PER_MIN || 30);
const DURATION = __ENV.DURATION || '5m';
const PING_SECONDS = Number(__ENV.PING_SECONDS || 4); // the driver app pings about every 4 s
const BOOK = (__ENV.BOOK || '1') === '1';
// Centre of the area to spread drivers around (default Bridgetown).
const LAT = Number(__ENV.LAT || 13.0975), LNG = Number(__ENV.LNG || -59.6130);

const pingTime = new Trend('driver_ping_ms', true);
const offersTime = new Trend('driver_offers_ms', true);
const estimateTime = new Trend('rider_estimate_ms', true);
const bookTime = new Trend('rider_book_ms', true);

export const options = {
  scenarios: {
    drivers: DRIVERS > 0 ? { executor: 'constant-vus', vus: DRIVERS, duration: DURATION, exec: 'driver' } : undefined,
    riders: riderTokens.length > 0 && RIDERS_PER_MIN > 0 ? {
      executor: 'constant-arrival-rate', rate: RIDERS_PER_MIN, timeUnit: '1m', duration: DURATION,
      preAllocatedVUs: Math.min(riderTokens.length, 50), maxVUs: riderTokens.length, exec: 'rider',
    } : undefined,
  },
  thresholds: {
    http_req_failed: ['rate<0.01'],          // fewer than 1% errors
    http_req_duration: ['p(95)<800'],        // 95% of requests under 0.8 s
    driver_ping_ms: ['p(95)<500'],
    rider_estimate_ms: ['p(95)<1500'],
  },
};
for (const k of Object.keys(options.scenarios)) if (!options.scenarios[k]) delete options.scenarios[k];

const headers = (t) => ({ headers: { Authorization: `Bearer ${t}`, 'Content-Type': 'application/json', Accept: 'application/json' } });
const jitter = (d) => (Math.random() - 0.5) * d;

export function driver() {
  const t = driverTokens[(__VU - 1) % driverTokens.length];
  const h = headers(t);
  // Each driver drifts around their own spot.
  let lat = LAT + jitter(0.08), lng = LNG + jitter(0.08);
  if (__ITER === 0) {
    http.post(`${BASE}/driver/location`, JSON.stringify({ lat, lng, heading: 90, speed: 0 }), h);
    const r = http.post(`${BASE}/driver/online`, '{}', h);
    check(r, { 'driver online': (x) => x.status === 200 });
  }
  lat += jitter(0.002); lng += jitter(0.002);
  const p = http.post(`${BASE}/driver/location`, JSON.stringify({ lat, lng, heading: Math.round(Math.random() * 360), speed: 8 }), h);
  pingTime.add(p.timings.duration);
  check(p, { 'ping ok': (x) => x.status === 200 });
  const o = http.get(`${BASE}/driver/offers`, h);
  offersTime.add(o.timings.duration);
  check(o, { 'offers ok': (x) => x.status === 200 });
  sleep(PING_SECONDS);
}

export function rider() {
  const t = riderTokens[Math.floor(Math.random() * riderTokens.length)];
  const h = headers(t);
  http.get(`${BASE}/config`);
  const trip = {
    pickup_lat: LAT + jitter(0.02), pickup_lng: LNG + Math.random() * 0.02, pickup_address: 'Load test pickup',
    dropoff_lat: LAT + 0.01 + Math.random() * 0.05, dropoff_lng: LNG + 0.01 + Math.random() * 0.03, dropoff_address: 'Load test drop-off',
    passengers: 1, luggage: 0,
  };
  const e = http.post(`${BASE}/fares/estimate`, JSON.stringify(trip), h);
  estimateTime.add(e.timings.duration);
  check(e, { 'estimate ok': (x) => x.status === 200 });
  if (!BOOK) return;
  // One ride at a time per rider: clear any left over first.
  const active = http.get(`${BASE}/trips/active`, h).json('trip');
  if (active && active.id) http.post(`${BASE}/trips/${active.id}/cancel`, JSON.stringify({ reason: 'load test' }), h);
  const b = http.post(`${BASE}/trips`, JSON.stringify({ ...trip, vehicle_type: 'standard', payment_method: 'cash' }), h);
  bookTime.add(b.timings.duration);
  if (!check(b, { 'booked': (x) => x.status === 201 })) return;
  const id = b.json('id');
  for (let i = 0; i < 5; i++) { sleep(4); http.get(`${BASE}/trips/${id}`, h); }
  http.post(`${BASE}/trips/${id}/cancel`, JSON.stringify({ reason: 'load test' }), h);
}
