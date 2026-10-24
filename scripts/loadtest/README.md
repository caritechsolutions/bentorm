# Load test (k6)

Measures how the server copes with many drivers and riders at once. **Run it against a copy of the server
(a test install), never production** — it creates bookings.

What it simulates (the same calls the apps make):

- **Drivers** — go online, then every 4 s send their position and check for offers. This is most of the traffic:
  200 online drivers ≈ 100 requests a second.
- **Riders** — open the app, price a ride, book it (cash), watch it for 20 s, cancel. `RIDERS_PER_MIN` sets how many.

## 1. Make test accounts (on the test server)

```bash
cd /var/www/bentorm/backend
sudo -u www-data php artisan bentorm:loadtest-users --drivers=200 --riders=100
# tokens are written to storage/app/loadtest-tokens.json — copy that file to the machine running k6
```

## 2. Run k6 (from another machine, so k6 doesn't steal the server's CPU)

Install k6 (https://grafana.com/docs/k6/latest/set-up/install-k6/), then:

```bash
k6 run -e BASE=https://your-test-server -e TOKENS=loadtest-tokens.json \
       -e DRIVERS=200 -e RIDERS_PER_MIN=60 -e DURATION=5m scripts/loadtest/ridecoop.js
```

Options: `DRIVERS`, `RIDERS_PER_MIN`, `DURATION` (e.g. `10m`), `PING_SECONDS` (default 4), `BOOK=0` (price only, no bookings),
`LAT`/`LNG` (centre of the area).

Raise the numbers step by step (50 → 100 → 200 → 400 drivers) and watch where it stops passing.

## 3. Read the result

k6 prints a summary. It **passes** when:

| Check | Target |
|---|---|
| `http_req_failed` | under 1% errors |
| `http_req_duration` p(95) | 95% of requests under 0.8 s |
| `driver_ping_ms` p(95) | under 0.5 s |
| `rider_estimate_ms` p(95) | under 1.5 s (includes the route lookup) |

While it runs, watch the server: `htop` (CPU), `redis-cli info stats`, `mysqladmin processlist`, and
`sudo tail -f /var/www/bentorm/backend/storage/logs/laravel.log`.

## 4. Clean up

```bash
sudo -u www-data php artisan bentorm:loadtest-users --clean
```

## Notes

- With route lookups on (Google), every estimate costs a Google call — use `BOOK=0` and a short run, or turn route
  lookups off on the test copy.
- Each user may make 120 API calls a minute; the test stays well under that per account.
