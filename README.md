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

- **Backend:** PHP 8.x, PostgreSQL + PostGIS, Redis (driver presence / pub-sub), WebSockets for live location
- **Clients:** Flutter (single codebase per app, three targets for rider)
- **Payments:** local Barbados gateway (TBD), cash fallback
- **Hosting:** self-hosted on Proxmox behind Nginx Proxy Manager

## Status

Scaffold only. See `docs/` for the build plan as it develops.
