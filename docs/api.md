# Bentorm API — v1

Base URL: `https://<host>/api/v1` · JSON in, JSON out · Auth: `Authorization: Bearer <token>` (Laravel Sanctum).

## Auth (phone + OTP)

| Method | Path | Body | Notes |
|---|---|---|---|
| POST | `/auth/otp/request` | `phone` | Sends a 6-digit code. 7-digit Barbados numbers are normalised to `+1246…`. 30 s cooldown per phone. |
| POST | `/auth/otp/verify` | `phone, code, name?, role? (rider\|driver), device_name?` | Returns `token` + `user`. First-time phones need `name` (422 with `needs_name: true` otherwise). |
| GET | `/me` | | Current user incl. `driver_profile` |
| PATCH | `/me` | `name?, email?, avatar_url?` | |
| POST | `/auth/logout` | | Revokes the current token |
| POST | `/devices` | `token, platform (android\|ios\|web)` | Push token registration |

## Public

| Method | Path | Notes |
|---|---|---|
| GET | `/health` | |
| GET | `/fares` | Active rate card |
| POST | `/fares/estimate` | `pickup_lat, pickup_lng, dropoff_lat, dropoff_lng, vehicle_type?` → `quotes[]` for all types (or one) |

## Rider

| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `/trips` | | History, paginated |
| GET | `/trips/active` | | Current trip + live `driver_position` |
| POST | `/trips` | `pickup_*, dropoff_*, vehicle_type, payment_method? (cash\|card), passengers?, rider_note?` | 201; dispatch starts immediately. 422 if a trip is already active. |
| GET | `/trips/{id}` | | `id` is the trip's public ULID |
| POST | `/trips/{id}/cancel` | `reason?` | Free while `requested`; cancellation fee after a driver accepted |
| POST | `/trips/{id}/rate` | `score 1–5, comment?` | Completed trips only |

## Driver (`role=driver`)

| Method | Path | Body | Notes |
|---|---|---|---|
| GET/PATCH | `/driver/profile` | `licence_number, licence_expiry, licence_image_url?, psv_permit_number?` | Status: pending → approved by admin |
| GET/POST | `/driver/vehicles` | `make, model, plate, vehicle_type, year?, colour?, seats?, documents?` | Vehicle also needs admin approval |
| POST | `/driver/vehicles/{id}/select` | | Set current vehicle (must be offline) |
| POST | `/driver/online` · `/driver/offline` | | Requires approved profile + approved current vehicle |
| POST | `/driver/location` | `lat, lng, heading?, speed?` | Send every 3–5 s while online. Doubles as heartbeat (90 s TTL). Returns `active_trip` for resync. |
| GET | `/driver/offers` | | Pending offers (resync after reconnect) |
| GET | `/driver/trips` · `/driver/trips/active` | | |
| POST | `/driver/trips/{id}/accept` | | 409 if taken/expired |
| POST | `/driver/trips/{id}/decline` | | |
| POST | `/driver/trips/{id}/arrive` → `/start` → `/complete` | `complete: lat?, lng?` | |
| POST | `/driver/trips/{id}/cancel` | `reason` | |

## Admin (`role=admin`)

| Method | Path | Notes |
|---|---|---|
| GET | `/admin/stats` | Counts + today's revenue |
| GET | `/admin/live` | Online drivers with positions + active trips (ops map) |
| GET | `/admin/users?role=&q=` · PATCH `/admin/users/{id}` | `is_active, role, name` |
| GET | `/admin/drivers?status=&q=` · GET `/admin/drivers/{id}` | |
| PATCH | `/admin/drivers/{id}/status` | `status (pending\|approved\|suspended\|rejected), note?` |
| PATCH | `/admin/vehicles/{id}/status` | `status (pending\|approved\|rejected)` |
| GET | `/admin/trips?status=&driver_id=&rider_id=&from=&to=` · GET `/admin/trips/{id}` | Detail includes offers, route breadcrumbs, payments |
| POST | `/admin/trips/{id}/cancel` | `reason` |
| GET | `/admin/fares` · PUT `/admin/fares/{vehicle_type}` | Upsert rate card row |

## Trip state machine

```
requested ──accept──► accepted ──arrive──► arrived ──start──► in_progress ──complete──► completed
    │                     │                   │                    │
    └─ cancel / no_drivers└── cancel ─────────┴── cancel ──────────┴── cancel (admin only)
```

## Dispatch

1. Rider requests → trip `requested`, `DispatchTripJob` queued.
2. Each wave offers the trip to the `DISPATCH_BATCH_SIZE` (3) nearest idle drivers of that vehicle type within `DISPATCH_RADIUS_M` (8 km), via Redis GEO. Offers expire after `DISPATCH_OFFER_TTL` (20 s).
3. First driver to `accept` wins (row lock); other offers are withdrawn. Declines by everyone trigger the next wave immediately.
4. After `DISPATCH_MAX_WAVES` (4) with no acceptance → `no_drivers`.

## Realtime (Laravel Reverb / Pusher protocol)

- WebSocket: `wss://<ws-host>` · app key from `REVERB_APP_KEY`.
- Auth endpoint for private channels: `POST /api/broadcasting/auth` with the Bearer token.
- Channels and events:

| Channel | Event | Audience |
|---|---|---|
| `private-user.{userId}` | `trip.offered` `{offer, trip}` | driver |
| `private-user.{userId}` | `offer.withdrawn` `{offer_id, trip_id, reason}` | driver |
| `private-user.{userId}` | `trip.updated` `{trip}` | rider + driver |
| `private-trip.{tripId}` | `trip.updated` `{trip}` | rider, driver, admin |
| `private-trip.{tripId}` | `driver.location` `{lat, lng, heading, speed, at}` | rider, admin |

## Pricing

`fare = max(base + km×per_km + min×per_minute, minimum) × surge + booking_fee`, rounded up to BBD 0.50.
Distance: haversine × 1.3 road factor by default; set `DISTANCE_PROVIDER=osrm` for real routing.

## Local testing

Set `OTP_STATIC_CODE=123456` and `OTP_EXPOSE_CODE=true` in `.env` to log in without an SMS gateway.
