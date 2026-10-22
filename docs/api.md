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
| POST | `/me/avatar` | multipart `photo` | Downscaled to 800 px |
| GET | `/me/outstanding` | | Trips with `amount_due > 0` (blocks new requests) |
| GET | `/files/avatars/{userId}` · `/files/documents/{id}` | | Authenticated file streaming. Documents: owner, admin, or a rider on a current/recent trip (rider-visible types only) |

## Public

| Method | Path | Notes |
|---|---|---|
| GET | `/health` | |
| GET | `/service-areas` | Operating regions with GeoJSON polygons (coverage map, driver sign-up) |
| GET | `/geo/check?lat=&lng=` | `{served, service_area, zones[], blocked}` |
| GET | `/vehicle-types?service_area_id=` | Types offered in an area |
| GET | `/fares?service_area_id=` | Rate cards (amounts exclude tax) |
| POST | `/fares/estimate` | `pickup_lat, pickup_lng, dropoff_lat, dropoff_lng, vehicle_type?` → `quotes[]` with `fare, subtotal, tax, currency, surge_multiplier, breakdown.lines[]`. 422 `pickup`/`dropoff` when outside service or blocked. |

## Rider

| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `/trips` | | History, paginated |
| GET | `/trips/active` | | Current trip + live `driver_position` |
| POST | `/trips` | `pickup_*, dropoff_*, vehicle_type, payment_method? (cash\|card), passengers?, rider_note?` | 201; dispatch starts immediately. 422 if a trip is already active. |
| GET | `/trips/{id}` | | `id` is the trip's public ULID |
| POST | `/trips/{id}/cancel` | `reason?` | Free while `requested`; cancellation fee after a driver accepted |
| POST | `/trips/{id}/rate` | `score 1–5, comment?` | Completed trips only |
| POST | `/trips/{id}/pay` | | Card: returns `redirect_url` to open in a browser/webview. After the gateway, the browser lands on `/payments/{pid}/return`, which deep-links to `ridecoop://payment?id=&status=` |
| GET | `/payments/{pid}` | | Poll payment status |

## Driver (`role=driver`)

| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `/driver/profile` | | `{profile, can_go_online, issues[]}` — issues explain exactly what's blocking |
| PATCH | `/driver/profile` | `service_area_id, licence_number, licence_expiry, psv_permit_number?` | |
| GET | `/driver/documents` | | Checklist: each document type with `state` = missing / pending / approved / expiring_soon / expired / rejected |
| POST | `/driver/documents` | multipart `document_type, file, number?, expires_at?` | jpg/png/webp/heic/pdf ≤ 15 MB; photos downscaled to 1600 px |
| DELETE | `/driver/documents/{id}` | | Unapproved uploads only |
| GET/POST | `/driver/vehicles` | `make, model, plate, vehicle_type, year?, colour?, seats?` | |
| PATCH | `/driver/vehicles/{id}` | | Edits send an approved vehicle back for review |
| GET/POST | `/driver/vehicles/{id}/documents` | as above | Vehicle checklist / upload (registration, insurance, photos) |
| GET | `/driver/earnings` | | Today / week / month: trips, earnings (after commission, ex tax), cash collected |
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
| GET | `/admin/drivers/{id}` | Full application: profile, vehicles, document checklists, blocking issues |
| PATCH | `/admin/drivers/{id}/status` | `status, note?, force?` — approval returns 422 + `issues[]` until required documents are approved (`force=true` overrides) |
| PATCH | `/admin/vehicles/{id}/status` | `status (pending\|approved\|rejected\|suspended), note?, force?` — same rule for vehicle documents |
| GET/POST/PATCH | `/admin/document-types` | `key, name, applies_to (driver\|vehicle), service_area_id?, is_required, requires_expiry, requires_number, is_image_only, visible_to_riders` |
| GET | `/admin/documents?status=pending&document_type=&driver_id=` | Review queue (oldest first) |
| POST | `/admin/documents/{id}/review` | `decision (approve\|reject), note (required to reject), expires_at?` |
| GET/POST | `/admin/service-areas` · GET/PATCH/DELETE `/admin/service-areas/{id}` | See *Geofences* |
| POST | `/admin/geo/check` | `lat, lng` |
| GET · PUT | `/admin/vehicle-types` · `/admin/vehicle-types/{key}` | |
| GET · PUT | `/admin/service-areas/{id}/fares` · `/admin/service-areas/{id}/fares/{vehicle_type}` | Rate card (ex tax) |
| GET/POST · PATCH/DELETE | `/admin/fare-rules` · `/admin/fare-rules/{id}` | See *Pricing* |
| POST | `/admin/fares/simulate` | `pickup_*, dropoff_*, at?` → quotes incl. platform fee and driver share, at any date/time |
| GET | `/admin/plugins?type=` | Installed plugins with config forms |
| GET/POST · PATCH/DELETE | `/admin/gateways` · `/admin/gateways/{id}` | See *Plugins* |
| POST | `/admin/gateways/{id}/test` | `to?` (phone / email) |
| GET | `/admin/payments` · POST `/admin/payments/{id}/refund` | |
| POST | `/admin/trips/{id}/waive` | `reason` — forgive an amount owed |
| GET | `/admin/trips?status=&driver_id=&rider_id=&from=&to=` · GET `/admin/trips/{id}` | Detail includes offers, route breadcrumbs, payments |
| POST | `/admin/trips/{id}/cancel` | `reason` |

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

## Geofences

One table, three kinds, all GeoJSON (`Polygon` or `MultiPolygon`, positions `[lng, lat]`; a list of `{lat, lng}` is also accepted):

| type | Purpose | Notes |
|---|---|---|
| `service` | An operating region (island/country) | Requires `currency`, `timezone`. Carries `tax_rate` (0.175), `tax_label`, `commission_rate` (0.20), `rounding_increment` (0.50) |
| `zone` | Pricing zone inside a service area (airport, port) | `parent_id` = service area. Used by zone fees and fixed routes |
| `blocked` | No pickups or drop-offs | Optional `parent_id`; `message` is shown to the rider |

Rules: pickup must be inside an active service area and not blocked; drop-off must be in the **same** service area and not blocked. Areas are cached and the cache is flushed on every edit.

## Pricing

Rate cards (per service area × vehicle type, **ex tax**) plus fare rules (per service area, optionally per vehicle type):

| kind | Fields | Effect |
|---|---|---|
| `time_multiplier` | `multiplier, days? [0=Sun..6], start_time, end_time` | Local time of the area; windows may wrap midnight (23:00–05:00). Highest matching wins |
| `surge` | `multiplier, starts_at?, ends_at?` | Manual surge. Multiplies with the time multiplier |
| `zone_fee` | `amount, pickup_zone_id (the zone), zone_applies_to (pickup\|dropoff\|either)` | Flat fee, e.g. airport |
| `fixed_route` | `amount, pickup_zone_id, dropoff_zone_id, bidirectional` | Replaces the metered fare (no multipliers, no zone fees) |

```
metered  = max((base + km×per_km + min×per_minute) × multipliers, minimum_fare)   — or the fixed-route amount
net      = metered + waiting (minutes beyond free_wait × wait_per_minute) + zone fees + booking fee
total    = roundUp(net × (1 + tax_rate), rounding_increment)        subtotal = total / (1 + tax_rate)
platform = subtotal × commission_rate                               driver   = subtotal − platform
```

The multiplier (or fixed route) is locked when the trip is requested and reused for the final fare; distance, time and waiting use actuals. Cancellation fee (rider cancels after acceptance) gets VAT and commission the same way.
Distance: haversine × 1.3 road factor by default; set `DISTANCE_PROVIDER=osrm` for real routing.

## Plugins (payment, SMS, email)

A **plugin** is a PHP class (listed in `config/plugins.php`); a **gateway** is a configured instance of one, created from the admin API. Credentials are stored encrypted; `secret` fields come back as `••••••••` and are kept if sent back unchanged.

| Plugin key | Type | Config |
|---|---|---|
| `log_sms` | sms | — (writes to the log; default in development) |
| `http_sms` | sms | endpoint, format (json/form), auth header/value, field names, sender ID, extra fields |
| `twilio` | sms | account_sid, auth_token, from / messaging_service_sid |
| `log_email` | email | — |
| `smtp` | email | host, port, encryption, username, password, from_address, from_name |
| `test_payment` | payment | mode: hosted_page / auto_approve / auto_decline |

Selection: the active gateway of the needed type whose `service_area_id` matches (area-specific beats global), then highest `priority`. So each island can have its own card processor and SMS sender.

Adding a gateway: implement `App\Plugins\Contracts\{PaymentPlugin|SmsPlugin|EmailPlugin}` (extend `AbstractPlugin`), add the class to `config/plugins.php`. Payment plugins implement `initiate` (usually return a hosted-page redirect), `verifyReturn`, `handleWebhook` (verify signatures!) and `refund`. Webhook URL per gateway: `POST /api/v1/webhooks/payments/{gatewayId}` (shown in the gateway's admin record).

Card flow (sale at the end of the trip): trip completes → `amount_due` set → app calls `POST /trips/{id}/pay` → opens `redirect_url` → gateway → `/payments/{pid}/return` → trip `payment_status=paid`, `trip.updated` broadcast. Unpaid amounts block new ride requests until paid or waived.

## Documents

Default types (editable): driver photo (rider-visible), driver's licence (number + expiry), Police Certificate of Character, PSV/taxi permit (Barbados only); vehicle registration, insurance (expiry), front (rider-visible) / rear / side / interior photos, inspection certificate (optional).
Files live in private storage (`storage/app/private/documents/...`) and are only served through `/files/documents/{id}`. Re-uploading replaces unapproved versions; an approved document stays valid until its replacement is approved. Hourly `documents:expire` marks lapsed documents and takes non-compliant drivers offline.

## Local testing

Set `OTP_STATIC_CODE=123456` and `OTP_EXPOSE_CODE=true` in `.env` to log in without an SMS gateway.

Run the test suite on the server (clones `main` into a temp dir, never touches `/opt/bentorm`):

```bash
curl -fsSL "https://raw.githubusercontent.com/caritechsolutions/bentorm/main/scripts/test.sh?$(date +%s)" | sudo bash
```
