# Bentorm

Ride-share platform for Barbados — rider app, driver app, admin panel and backend, built and owned in-house by Caritech Solutions Inc.

## Layout

```
backend/      PHP REST API + WebSocket server (live location, trip events), PostgreSQL/PostGIS
apps/rider/   Flutter — Android, iOS, Web
apps/driver/  Flutter — Android, iOS
apps/admin/   Flutter Web — operations dashboard
docs/         Architecture, API contract, decisions
```

## Stack

- **Backend:** Laravel 13 (PHP 8.3), MySQL 8, Redis (driver presence via GEO, queues, cache), Laravel Reverb WebSockets
- **Clients:** Flutter (single codebase per app, three targets for rider)
- **Payments:** local Barbados gateway (TBD), cash fallback
- **Hosting:** self-hosted on Proxmox behind Nginx Proxy Manager

## Install / update the backend (Ubuntu 22.04 / 24.04)

```bash
curl -fsSL "https://raw.githubusercontent.com/caritechsolutions/bentorm/main/scripts/bootstrap.sh?$(date +%s)" | sudo ADMIN_PHONE=12461234567 API_HOST=api.example.com WS_HOST=ws.example.com ADMIN_HOST=admin.example.com bash
```

Brings up the API on `:8080`, the admin panel on `:8082`, WebSockets on `:8081`, plus queue worker and scheduler as systemd services. Re-run to deploy the latest `main`. The first run prints the admin panel password; change it with `php artisan bentorm:admin-password <phone>`.

Live end-to-end check (run on the server): `curl -fsSL ".../scripts/smoke.sh?$(date +%s)" | sudo API_HOST=api.example.com ADMIN_PHONE=... bash`

## Admin panel (Filament)

Sign in with the admin phone number and password at `https://<ADMIN_HOST>/admin`.

- **Operations** — live map of online drivers and active trips; trips with fare breakdown, payments, cancel / waive
- **Drivers** — applications with a "what is blocking this driver" list, document previews, approve / suspend / reject (approval enforces required documents, with an explicit override); document review queue; vehicles with capacity and features
- **Riders & payments** — users (activate / deactivate, roles, admin passwords); payments with refunds
- **Pricing & areas** — map editor for service areas, zones and blocked areas; rate cards and fare rules per area; vehicle types
- **Settings** — required documents; payment / SMS / email gateways (forms generated from each plugin, secrets encrypted, test button)

## Status

- **Backend:** API complete for MVP — OTP auth, driver onboarding with documents, geofences, rate engine, dispatch, trip lifecycle, card holds, plugins. See [`docs/api.md`](docs/api.md).
- **Admin panel:** Filament 5 at `/admin`.
- **Apps:** not started.
