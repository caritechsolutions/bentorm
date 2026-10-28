# Bentorm API — v1

Base URL: `https://<host>/api/v1` · JSON in, JSON out · Auth: `Authorization: Bearer <token>` (Laravel Sanctum).

Response shapes: single objects are returned bare; paginated lists are `{data, links, meta}`; unpaginated lists are bare arrays.

## Auth (phone + OTP)

| Method | Path | Body | Notes |
|---|---|---|---|
| POST | `/auth/otp/lookup` | `phone` | `{phone, registered, email_hint}` — the app asks new phones for name + email before sending a code. Own rate limit (10/min per phone) |
| POST | `/auth/otp/request` | `phone, email?` | One 6-digit code, sent by **SMS and email**: the account's email for registered phones (an `email` sent with it is ignored), or `email` for a new sign-up (422 if another account uses it). Returns `channels` (`sms`, `email`) and `email_hint` (`r•••@gmail.com`). 7-digit Barbados numbers are normalised to `+1246…`. 30 s cooldown per phone. If the SMS fails but an email was sent, it still succeeds (`channels: ["email"]`); if nothing could be sent: 503 and no cooldown |
| POST | `/auth/otp/verify` | `phone, code, name?, email?, role? (rider\|driver), device_name?` | Returns `token` + `user`. New accounts need `name` and an email (the one the code was sent to, or `email`); otherwise 422 with `needs_name` / `needs_email` — the code stays valid for the retry |
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
| POST | `/fares/estimate` | `pickup_lat, pickup_lng, dropoff_lat, dropoff_lng, vehicle_type?, passengers?, luggage?, stops?[{lat, lng}]` (up to 2, in order) → `quotes[]` with `fare, hold_amount, subtotal, tax, currency, surge_multiplier, breakdown.lines[]`. Only types that fit the party are quoted. Also returns `route {distance_m, duration_s, polyline: [[lat, lng], …] \| null}` for drawing. 422 `pickup`/`dropoff` when outside service or blocked. |

## Rider

| Method | Path | Body | Notes |
|---|---|---|---|
| GET | `/trips` | | History, paginated |
| GET | `/trips/active` | | Current trip + live `driver_position` |
| POST | `/trips` | `pickup_*, dropoff_*, stops?[{lat, lng, address}], vehicle_type, payment_method? (cash\|card), payment_method_id? (saved card; default card if omitted), passengers?, luggage?, features[]?, rider_note?` | **201** `requested` — searching for a driver · **202** `pending_payment` — card needs 3-D Secure: open `payment_action.url` · **402** card hold declined (trip cancelled, nothing owed) · 422 active trip / amount owed / party too big |
| GET | `/trips/{id}` | | `id` is the trip's public ULID |
| POST | `/trips/{id}/cancel` | `reason?` | Free while `requested`; cancellation fee after a driver accepted |
| POST | `/trips/{id}/stops` | `stops[{lat, lng, address}]` (the stops not yet reached; `[]` removes them) | Before a driver accepts: applied at once. After: `stop_change.status = pending` until the driver accepts or declines (fare re-estimated; the card hold is not changed — any overrun is charged at the end) |
| DELETE | `/trips/{id}/stops/pending` | | Withdraw a change the driver hasn't answered |
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
| POST | `/driver/trips/{id}/navigation` | `destination` (pickup\|stop\|dropoff), `check?` | In-app turn-by-turn gate (admin → Settings → In-app navigation): `{in_app, reason}`; `reason` is `off` or `monthly_limit`. Without `check` an allowed start is counted (one billed Navigation SDK destination). `in_app: false` → open the Google Maps app |
| GET | `/driver/offers` | | Pending offers (resync after reconnect), each with `distance_m`, `eta_s` (road arrival seconds when the area ranks by arrival, else null) and `estimated_earnings` (ex VAT, after commission) |
| GET | `/driver/trips` · `/driver/trips/active` · `/driver/trips/{id}` | | `{id}`: a trip this driver was assigned — e.g. to show that the rider cancelled |
| POST | `/driver/trips/{id}/accept` | | 409 if taken/expired |
| POST | `/driver/trips/{id}/decline` | | |
| POST | `/driver/trips/{id}/arrive` → `/start` → `/complete` | `start: pin` · `complete: lat?, lng?` | **Start needs the rider's 4-digit PIN** (422 `pin` if missing/wrong, with tries left; 429 after 5 wrong for 2 minutes) |
| POST | `/driver/trips/{id}/stops/accept` · `/stops/decline` | | Answer the rider's pending stop change |
| POST | `/driver/trips/{id}/stops/{index}/reached` | | Arrived at stop `index` (0-based), in order, during the trip |
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

## Stops and ride PIN

- **Stops:** up to `MAX_STOPS` (2) between pickup and drop-off, each inside the pickup's service area and outside blocked areas. The fare follows the road route through them, plus the rate card's `stop_fee` per stop (line `stops`, ex tax). Fixed-route fares don't apply to rides with stops. Trips carry `stops[{lat, lng, address, reached_at}]` and `stop_change {status, stops, estimated_fare, requested_at, responded_at}`.
- **PIN:** every ride gets a random 4-digit `pin`, shown only to the rider (`GET /trips/{id}`, `/trips/active`) while the ride is active — never to drivers, never in websocket broadcasts or offers. `pin_verified` turns true when the driver starts the trip with it.

## Dispatch

Set in the admin panel under **Settings → Driver dispatch**: one default, plus optional overrides per service area (the pickup's area decides).

1. Rider requests → trip `requested`, `DispatchTripJob` queued.
2. Candidates: idle, heartbeat-alive drivers of the vehicle type within the current search radius (Redis GEO), whose current vehicle fits the party, luggage and features, and who haven't been offered this trip before.
3. **Strategy** — `waves`: the best `batch_size` get the offer; `broadcast`: every candidate (up to `broadcast_limit`) at once. Offers last `offer_ttl_seconds`; first to `accept` wins (row lock), the rest are withdrawn. Declines by everyone start the next wave immediately.
4. **Ranking** ("best") — `distance`: nearest in a straight line; `eta`: fastest by road (the nearest `eta_candidates` are checked in one Google Route Matrix / OSRM table call; falls back to straight-line estimates); `longest_idle`: longest since going online or finishing a ride (`idle_since` in Redis).
5. **Radius** starts at `radius_start_m` and widens by `radius_step_m` up to `radius_max_m`. A ring with nobody new widens at once without using a wave; at the widest ring an empty search uses a wave (waits one offer period for drivers to appear).
6. After `max_waves` waves with no acceptance → `no_drivers` (longest wait ≈ `max_waves × offer_ttl_seconds`).

Offers record `wave`, `radius_m` and, for `eta` ranking, `eta_s` (also sent to the driver in `/driver/offers` and the `trip.offered` event). The admin trip page lists every offer.

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
| `private-user.{userId}` | `chat.message` `{trip_id, id, body, sender}` | the other side of the trip |
| `private-user.{userId}` | `call.ring` `{call_id, trip_id, from, video}` · `call.signal` `{call_id, seq, type, payload}` · `call.updated` `{call_id, status}` | caller / callee |
| `private-user.{userId}` | `order.updated` `{order_id, status}` | shop customer |

The apps connect with the settings from `GET /config → realtime` and keep a slower poll as a safety net. If the WebSocket server is down, chat and calls still work (the server logs the failure and the apps poll).

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

Set `OTP_STATIC_CODE=123456` and `OTP_EXPOSE_CODE=true` in `.env` to log in without an SMS gateway. On a server, `php artisan bentorm:test-token 12465550101 --name=Test` issues a token for a +1 246 555 test number (used by `scripts/smoke.sh`, which therefore sends no SMS).

Run the test suite on the server (clones `main` into a temp dir, never touches `/opt/bentorm`):

```bash
curl -fsSL "https://raw.githubusercontent.com/caritechsolutions/bentorm/main/scripts/test.sh?$(date +%s)" | sudo bash
```

## Shop (phase 1: stores and catalogue)

- **Store portal** at `/store` (same app as the admin panel; optional `STORE_HOST` for a friendly hostname). One login can belong to several stores; every page is scoped to the store in the URL and only its members can open it (Filament tenancy).
- **Sign-up:** name, mobile, email, password → a code by SMS + email proves the phone → "Register your store" (category, address, map pin, logo). An existing rider account with that phone is reused; admin phones are refused. Log in with phone or email + password; "Forgot password" resets with a code to the phone.
- **Stores** start `pending`; an admin approves / rejects / suspends them (Shop → Stores). Store documents use the document system (`applies_to = store`, optionally only for one shop category, e.g. the food permit). Customers will only see approved stores that are switched open and within their hours.
- **Catalogue:** menu sections, products (price, photo, size small/medium/large, options groups with priced choices, sold-out switch). **Staff:** owners add people by mobile; staff manage the catalogue but not settings or staff.
- **Money settings** (Shop → Fees & commission): commission on items (per-store override), Ride Coop share of the delivery fee, service fee (flat and/or %). Delivery fees come from each area's **Delivery** rate card (vehicle type `delivery`, `service = delivery`, never offered as a ride).

## Shop (phase 2: customer orders)

Public (no login): `GET /shop/categories?lat&lng`, `GET /shop/stores?category&q&lat&lng` (approved stores near the point; `is_open` tells you if they take orders now), `GET /shop/stores/{slug}` (store + menu sections → products with options), `POST /shop/quote`.

Signed in:

| Call | Notes |
|---|---|
| `POST /shop/orders` | Body: `store` (slug), `lat`, `lng`, `items[{product_id, quantity, options[{group, choices[]}], note}]`, `address`, `note`, `payment_method_id`. Card only. 201 → order; if the card needs 3-D Secure, `payment.redirect_url` is set and the order stays `pending_payment` until it's done. |
| `GET /shop/orders` | Paginated history (wrapped in `data`). |
| `GET /shop/orders/active` | Plain list of orders still in progress. |
| `GET /shop/orders/{id}` | One order (by `public_id`). |
| `POST /shop/orders/{id}/cancel` | Only before the store accepts; the card hold is released. |
| `POST /shop/orders/{id}/change` | `{accept: true|false}` answers a store's "out of stock" removal request. Declining cancels the order and releases the hold. |

- **Totals** = items (+ VAT on items when the store is VAT-registered and prices exclude VAT) + delivery fee (the area's Delivery rate card, store → customer) + service fee (Fees & commission). One card hold covers the total.
- **Statuses:** `pending_payment` → `placed` (the store has 5 minutes to answer, else `expired` and the hold is released) → `accepted` (with prep time) → `ready` (card captured for now; phase 3 moves capture to delivery). Also `declined`, `cancelled`.
- **Money split:** store receives items − commission; Ride Coop keeps commission + service fee + its share of the delivery fee; the rest of the delivery fee goes to the driver (phase 3).
- **Store portal → Orders:** tabs New / Preparing / Ready / Past. A new order rings (after "Turn on sound" is tapped once) and shows a red banner on every page; the store also gets an SMS. Actions: Accept (prep minutes), Decline (reason), Out of stock (remove items → customer approves), Mark ready.

## Shop (phase 3: deliveries)

- **A delivery is a trip** (`kind: "delivery"`, store → customer, `vehicle_type: delivery`) linked to its order. It is offered with the same dispatch strategy as rides, but only to drivers with **`accepts_deliveries`** on (any approved vehicle). Drivers switch it with `PATCH /driver/profile {accepts_deliveries: true}`; admins can too (Drivers → Edit).
- **When:** the scheduler (`orders:dispatch`, every minute) sends a driver once the order will be ready within *driver lead* minutes (Shop → Fees & commission, default 10). "Mark ready" and "Need driver now" in the store portal send one straight away. If a job ends with nobody (`no_drivers`) or the driver cancels, a new job goes out the next minute.
- **No driver for *alert* minutes** (default 10): the store (SMS + push), admins (push) and the customer (push) are told once; the customer may then cancel (`POST /shop/orders/{id}/cancel`) and the hold is released.
- **Driver flow:** accept → `arrive` (at the store) → the **store** taps **Picked up** in the portal (order `picked_up`, trip `in_progress`; drivers can't `start` a delivery) → at the door `POST /driver/trips/{id}/complete {pin, lat, lng}` with the **customer's 4-digit PIN** (same lock-out rules as rides). Completing captures the card and marks the order `delivered`. The driver earns `order.driver_earnings` (delivery fee ex VAT − Ride Coop share).
- **Driver sees** `trip.delivery`: `order_number`, `store {name, phone, address}`, `customer_name`, `items[]`, `note`, `earnings`, `picked_up`. Never the PIN.
- **Customer sees** `order.delivery`: `status` (`searching` · `driver_assigned` · `at_store` · `on_the_way` · `delivered`), `driver {name, phone, vehicle}`, `driver_position`, `pin`, `no_driver_alert`, `can_cancel`. Deliveries never appear in the customer's rides (`/trips`, `/trips/active`).
- Order statuses add `picked_up` ("On the way") and `delivered`. The card is charged at delivery (no longer at "ready").
- Customers also get `delivery.trip_id`, `can_chat`, `can_call`, `unread_messages` (message or call the driver through the app; with masked calls on, `driver.phone` is null).

## App features (switches, pages, safety, money)

Every feature below has an on/off switch (and numbers) in **Admin → Settings → App features**. `GET /config` returns them as `features` (e.g. `"tips": true`, `"scheduled.min_minutes": 30`) plus `emergency_numbers`; the apps hide what is off, the server refuses it (`403`), and no app update is needed.

**Pages inside the apps.** `POST /app-links {page}` → `{url, expires_in: 120}`: a one-time sign-in link the app opens in an in-app browser. Pages: `help`, `help/{slug}`, `support/new?category=…&trip=…&order=…`, `support/tickets`, `support/tickets/{id}`, `statement` (drivers), `about`. They are server pages, so they change without an app update.

| Feature | Endpoints | Notes |
|---|---|---|
| Saved places | `GET/POST /me/places`, `PATCH/DELETE /me/places/{id}` `{kind: home\|work\|other, label, address, lat, lng, place_id}` | Saving Home/Work again replaces it; up to 12. |
| Book for later | `scheduled_at` (ISO 8601) on `POST /fares/estimate` and `POST /trips`; `GET /trips/scheduled` | Status `scheduled` until *dispatch lead* minutes before pickup (scheduler `trips:scheduled`), then normal dispatch. Cancel is free until a driver accepts. Window: `scheduled.min_minutes` … `scheduled.max_days`. |
| Tips | `POST /trips/{id}/tip {amount, payment_method_id?}`; `POST /shop/orders/{id}/tip {amount}`; `tip` at shop checkout | Card only, once per trip, within `tips.window_hours`, up to `tips.max_amount`. 100% to the driver (ledger + earnings `tips`). Trip has `tip`, `can_tip`; order has `can_tip`, `totals.tip`. |
| Promo codes | `POST /promo-codes/check {code, for: rides\|shop, amount?}`; `promo_code` on estimate, `POST /trips`, shop quote/place | Admin → Marketing → Promo codes. Ride Coop pays the discount: drivers and stores are paid on the full price. Trip has `discount`, `promo_code`, `payable`. |
| Ride credit | `GET /me/credit`; `use_credit: true` on `POST /trips` and shop quote/place | Up to `credit.max_share` × the fare. Admins add credit (Users → Ride credit). Trip has `credit`; cash drivers collect `payable`. |
| Invite a friend | `GET /me/referral` (code, share text, counts, `can_enter_code`); `POST /me/referral {code}` | Both get credit after the new customer's first completed ride or delivered order. |
| Share my trip | `POST /trips/{id}/share` → `{url, text}` | Public live map at `/t/{token}` until an hour after the trip. |
| SOS | `POST /sos {trip_id?, lat?, lng?}` | Alerts admins (push + Admin → Operations → Safety alerts); apps show `emergency_numbers` (211 / 511 / 311). |
| Chat | `GET /trips/{id}/messages?after=`, `POST /trips/{id}/messages {body}` | Open while a driver is on the way and during the trip; trip has `can_chat`, `unread_messages`. Push type `chat`. |
| Calls (audio / video) | `POST /trips/{id}/calls {video}`; `GET /calls/incoming`; `GET /calls/{id}`; `POST /calls/{id}/accept\|decline\|end\|phone`; `POST /calls/{id}/signal {type: offer\|answer\|candidate, payload}`; `GET /calls/{id}/signals?after=` | WebRTC in the apps, signalling through the server, relay through coturn (`TURN_HOST` in the installer; short-lived credentials in `ice_servers`). `phone` = fall back to a Twilio call that rings the caller and connects the other side (numbers stay private). Rings for 45 s; push type `call`. |
| Help & support | app pages above; Admin → Support (articles, tickets) | Lost-item tickets tell the driver; staff replies push with `data.page`. |
| Payouts | Admin → Finance → Payouts; driver page `statement`; store portal → Statement | Ledger: + = Ride Coop owes the driver/store. Record payouts with a reference; CSV export. |
| Automatic surge | Admin → Settings → Automatic surge | Per area, every minute: demand ÷ free drivers → multiplier (capped), applied to prices quoted for now and locked at request. |

**Driver presence.** Every `POST /driver/location` re-states the driver's full presence (vehicle type from their current car, idle/busy from their job), so a gap in pings never leaves an online driver invisible to dispatch; the reply has `online` (false = the server has them offline: the app signs back on). Drivers marked online whose phone is silent for 10 minutes are set offline and told by push (scheduler `drivers:expire-silent`). `php artisan bentorm:drivers` lists online drivers with last ping, car, state and whether dispatch can offer them rides (and why not).

**Maps.** `GET /drivers/nearby?lat=&lng=&vehicle_type=` → `{cars: [{lat, lng, heading, vehicle_type}]}`: free cars within 4 km, positions rounded to ~100 m, no driver details. `GET /trips/{id}` and `/trips/active` add `route {phase: to_pickup|ride, polyline [[lat, lng]…]|null, distance_m, duration_s}` (approach route cached a minute, ride route for the trip). During a ride the rider app joins `private-trip.{id}` for `driver.location` every few seconds.

**Checking calls.** On the server: `php artisan bentorm:calls` shows the last calls and, for each phone, whether its offer/answer arrived and which connection candidates it offered (`host`, `srflx`, `relay`; no `relay` = that phone couldn't reach the call relay). Signalling is kept for an hour. `php artisan bentorm:turn-test` prints a 30-minute relay login and the command to run `scripts/check-turn.sh` on a Linux machine outside your network (DNS, UDP/TCP 3478, relay ports, relay-to-relay / hairpin NAT). Relay ports default to UDP 49160–49660 (about 2 per phone per call; `TURN_MIN_PORT` / `TURN_MAX_PORT` in the installer).

**Rate limits.** Each throttled action has its own counter (for example, wrong invite codes never block SOS or a tip). All API calls also share the general limit of 120 a minute per user.


## Fleets (taxi owners with drivers)

- **Fleet portal** at `/fleet` (same app and logins as the store portal; optional `FLEET_HOST`, e.g. `fleet.ridecoop.net` → nginx :8082). Sign up → "Register your fleet" (name, area, contact) → the fleet is `pending` until an admin approves it (Drivers → Fleets). Each login sees only its own fleet(s); **owners** see everything, **managers** cars, drivers and trips but not the statement, bank details, settings or team.
- **Cars** belong to the fleet (`vehicles.fleet_id`, no `driver_id`). Their documents are uploaded in the portal and checked like any vehicle; changing an approved car sends it back for a check. An admin can move a driver's own car into a fleet (Vehicles → Move to a fleet), optionally keeping that driver on it.
- **Drivers** are invited by mobile number and given chosen cars (`fleet_drivers`, `fleet_driver_vehicle`); they accept or decline in the driver app. A driver can be in several fleets and keep their own car; their driver documents and rating stay theirs. **One driver per fleet car**: selecting or going online in a car someone else is online in is refused ("FL2 is in use by Mark").
- **Taking a car away** (removing the driver, unticking the car, or the fleet being suspended): a driver who isn't on a trip is set offline at once; a driver on a trip finishes it and is set offline as it ends. The reason is stored in `driver_profiles.offline_reason`, returned by pings, and the app shows it instead of signing back on.
- **Money:** `trips.fleet_id` is fixed when the driver accepts. Card fares, fees and tips book to the fleet (`ledger_entries.payee_type = fleet`); cash trips book the commission owed, and the cash is recorded as held by that driver for the owner (`fleet_cash`), cleared with "Record cash received" on the portal statement. Admin pays fleets from Payouts → Fleets.
- **Driver API:** `GET /driver/profile` adds `fleet_invites`; `GET /driver/fleet-invites`; `POST /driver/fleet-invites/{id}/accept|decline`; `GET /driver/vehicles` lists own cars then fleet cars (with `fleet {id, name}` and `in_use_by`); `POST /driver/vehicles/{id}/select` accepts fleet cars the driver was given; `GET /driver/earnings` adds `fleets[]` (earned in fleet cars, paid to the fleet); trips in fleet cars carry `earnings_paid_to`; `POST /driver/location` adds `offline_reason`.
