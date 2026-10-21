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
curl -fsSL "https://raw.githubusercontent.com/caritechsolutions/bentorm/main/scripts/bootstrap.sh?$(date +%s)" | sudo ADMIN_PHONE=12461234567 API_HOST=api.example.com WS_HOST=ws.example.com bash
```

Brings up the API on `:8080` and WebSockets on `:8081`, plus queue worker and scheduler as systemd services. Re-run to deploy the latest `main`.

## Status

- **Backend:** API complete for MVP — OTP auth, driver onboarding, dispatch, trip lifecycle, fares, admin. See [`docs/api.md`](docs/api.md).
- **Apps:** not started.
