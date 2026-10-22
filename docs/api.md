# Bentorm API — v1

Base URL: `https://<host>/api/v1` · JSON in, JSON out · Auth: `Authorization: Bearer <token>` (Laravel Sanctum).

Response shapes: single objects are returned bare; paginated lists are `{data, links, meta}`; unpaginated lists are bare arrays.

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
| GET | `/config` | Runtime settings for the apps: `realtime {key, host, port, scheme, auth_endpoint}`, `address_search` (bool), `app_scheme`, `otp_length` |
| GET | `/service-areas` | Operating regions with GeoJSON polygons (coverage map, driver sign-up) |
| GET | `/geo/check?lat=&lng=` | `{served, service_area, zones[], blocked}` |
| GET | `/vehicle-types?service_area_id=` | Types offered in an area, with `max_passengers`, `max_luggage` |
| GET | `/vehicle-features` | `[{key, label}]` — wheelchair_accessible, child_seat, pet_friendly, extra_luggage |
| GET | `/fares?service_area_id=` | Rate cards (amounts exclude tax) |
| POST | `/fares/estimate` | `pickup_lat, pickup_lng, dropoff_lat, dropoff_lng, vehicle_type?, passengers?, luggage?` → `quotes[]` with `fare, hold_amount, subtotal, tax, currency, surge_multiplier, breakdown.lines[]`. Only types that fit the party are quoted. Also returns `route {distance_m, duration_s, polyline: [[lat, lng], …] \| null}` for drawing. 422 `pickup`/`dropoff` when outside service or blocked. |

## Rider

| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `/trips` | | History, paginated |
| GET | `/trips/active` | | Current trip + live `driver_position` |
| POST | `/trips` | `pickup_*, dropoff_*, vehicle_type, payment_method? (cash\|card), payment_method_id? (saved card; default card if omitted), passengers?, luggage?, features[]?, rider_note?` | **201** `requested` — searching for a driver · **202** `pending_payment` — card needs 3-D Secure: open `payment_action.url` · **402** card hold declined (trip cancelled, nothing owed) · 422 active trip / amount owed / party too big |
| GET | `/trips/{id}` | | `id` is the trip's public ULID |
| POST | `/trips/{id}/cancel` | `reason?` | Free while `requested`; cancellation fee after a driver accepted |
| POST | `/trips/{id}/rate` | `score 1–5, comment?` | Completed trips only |
| POST | `/trips/{id}/pay` | `payment_method_id?` | Settle an amount owed. With a saved card it's charged directly; otherwise returns `redirect_url` for a hosted page, which deep-links back to `ridecoop://payment?id=&status=` |
| GET | `/me/payment-methods` | | Saved cards (`brand, last4, exp_month, exp_year, is_default`) |
| POST | `/me/payment-methods` | `service_area_id?` or `lat, lng` | Start adding a card → `redirect_url` (hosted card page). Returns to `ridecoop://card?id=&status=`; then poll `GET /me/payment-methods/{id}` |
| POST | `/me/payment-methods/{id}/default` · DELETE `/me/payment-methods/{id}` | | Delete is refused while the card is on an active ride |
| GET | `/payments/{pid}` | | Poll payment status |

## Address search (any signed-in user)

Google Places, called from the server so the key never ships for search and web clients avoid CORS. 503 when `GOOGLE_MAPS_SERVER_KEY` is not set or Google refuses. Throttled to 90/min per user.

| Method | Path | Notes |
|---|---|---|
| GET | `/places/autocomplete?q=&lat=&lng=&session=` | Suggestions inside the active service areas: `[{place_id, main_text, secondary_text, distance_m}]`. Pass the rider's position for distances, and one `session` token per search (until a place is picked) for Google's session pricing |
| GET | `/places/{place_id}?session=` | `{place_id, name, address, lat, lng}` (cached a day) |
| GET | `/places/reverse?lat=&lng=` | Address for a point (current location, dragged pin); 404 if none |

## Driver (`role=driver`)

| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `/driver/profile` | | `{profile, can_go_online, issues[]}` — issues explain exactly what's blocking |
| PATCH | `/driver/profile` | `service_area_id, licence_number, licence_expiry, psv_permit_number?` | |
| GET | `/driver/documents` | | Checklist: each document type with `state` = missing / pending / approved / expiring_soon / expired / rejected |
| POST | `/driver/documents` | multipart `document_type, file, number?, expires_at?` | jpg/png/webp/heic/pdf ≤ 15 MB; photos downscaled to 1600 px |
| DELETE | `/driver/documents/{id}` | | Unapproved uploads only |
| GET/POST | `/driver/vehicles` | `make, model, plate, vehicle_type, year?, colour?, seats?, luggage_capacity?, features[]?` | Seats = passenger seats; luggage = standard suitcases |
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
pending_payment ──hold authorised──► requested     (card rides only; declined → cancelled)
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
Distance: haversine × 1.3 road factor by default; `DISTANCE_PROVIDER=google` (set by the installer when `GOOGLE_MAPS_KEY` is given) uses the Google Routes API — cached 10 min, falls back to haversine on error; `osrm` is also supported.

## Card payments: holds (Uber-style)

| When | What happens to the money |
|---|---|
| Rider adds a card | Hosted card page at the gateway → we store only a token + brand/last4/expiry |
| Ride requested (card) | **Hold** `hold_amount = estimate × (1 + hold_buffer_rate)` (20% default, rounded up) on the saved card. Trip is `pending_payment` until authorised; dispatch starts only after. 3-D Secure → 202 + `payment_action.url`. Declined → trip cancelled, nothing owed |
| Trip completed | **Capture** the final fare from the hold (partial capture releases the rest). If the final fare exceeds the hold, the difference is charged to the same card (`top_up`). Anything that can't be collected becomes `amount_due` |
| Rider cancels before a driver accepts / no drivers / driver or admin cancels | **Void** — the whole hold is released |
| Rider cancels after a driver accepted | Cancellation fee (+VAT) **captured** from the hold; rest released |
| Hold never confirmed (abandoned 3-D Secure) | Trip cancelled after 15 minutes |

Cash rides have no hold and are paid in the car. Saved cards are tied to the gateway that created them; the gateway serving the pickup area must match.

Test gateway cards: `4242 4242 4242 4242` approves · `4000 0000 0000 0002` declines holds/charges · `4000 0000 0000 3220` requires 3-D Secure.

## Capacity and features

Vehicle types have `max_passengers` and `max_luggage`; quotes only include types that fit. Each vehicle declares `seats`, `luggage_capacity` and `features` (checked by admin at vehicle approval — edits send it back for review). Dispatch only offers a trip to drivers whose **current vehicle** fits the party, the luggage and every requested feature.

## Plugins (payment, SMS, email)

A **plugin** is a PHP class (listed in `config/plugins.php`); a **gateway** is a configured instance of one, created from the admin API. Credentials are stored encrypted; `secret` fields come back as `••••••••` and are kept if sent back unchanged.

| Plugin key | Type | Config |
|---|---|---|
| `log_sms` | sms | — (writes to the log; default in development) |
| `http_sms` | sms | endpoint, format (json/form), auth header/value, field names, sender ID, extra fields |
| `twilio` | sms | account_sid, auth_token, from / messaging_service_sid |
| `log_email` | email | — |
| `smtp` | email | host, port, encryption, username, password, from_address, from_name |
| `test_payment` | payment | mode: hosted_page / auto_approve / auto_decline. Supports saved cards, holds, 3-D Secure, refunds |

Selection: the active gateway of the needed type whose `service_area_id` matches (area-specific beats global), then highest `priority`. So each island can have its own card processor and SMS sender.

Adding a gateway: implement `App\Plugins\Contracts\{PaymentPlugin|SmsPlugin|EmailPlugin}` (extend `AbstractPlugin`), add the class to `config/plugins.php`. A payment plugin implements: `startCardSetup` / `completeCardSetup` (tokenise a card via the gateway's hosted page), `authorize` (hold; may return a 3-D Secure redirect), `capture`, `void`, `charge` (merchant-initiated, for top-ups), `initiate` / `verifyReturn` (one-off hosted payments), `handleWebhook` (verify signatures!) and `refund`. Webhook URL per gateway: `POST /api/v1/webhooks/payments/{gatewayId}` (shown in the gateway's admin record).

Unpaid amounts block new ride requests until paid (saved card or hosted page) or waived by an admin.

## Documents

Default types (editable): driver photo (rider-visible), driver's licence (number + expiry), Police Certificate of Character, PSV/taxi permit (Barbados only); vehicle registration, insurance (expiry), front (rider-visible) / rear / side / interior photos, inspection certificate (optional).
Files live in private storage (`storage/app/private/documents/...`) and are only served through `/files/documents/{id}`. Re-uploading replaces unapproved versions; an approved document stays valid until its replacement is approved. Hourly `documents:expire` marks lapsed documents and takes non-compliant drivers offline.

## Local testing

Set `OTP_STATIC_CODE=123456` and `OTP_EXPOSE_CODE=true` in `.env` to log in without an SMS gateway.

Run the test suite on the server (clones `main` into a temp dir, never touches `/opt/bentorm`):

```bash
curl -fsSL "https://raw.githubusercontent.com/caritechsolutions/bentorm/main/scripts/test.sh?$(date +%s)" | sudo bash
```
