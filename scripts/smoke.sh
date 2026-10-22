#!/usr/bin/env bash
# Live end-to-end check of a deployed Bentorm API, run ON the API server (it reads OTP codes from the app log).
#   curl -fsSL "https://raw.githubusercontent.com/caritechsolutions/bentorm/main/scripts/smoke.sh?$(date +%s)" | sudo API_HOST=api.ridecoop.net ADMIN_PHONE=12462530487 bash
#
# Creates test accounts +1246555 0101 (rider) / 0202 (driver), a test vehicle and two test rides in Barbados.
# Uses the test card gateway. Safe to re-run (accounts are reused).
set -uo pipefail
main() {
  command -v jq >/dev/null || { apt-get install -y -qq jq >/dev/null 2>&1 || { echo "jq required"; exit 1; }; }
  API="${API:-https://${API_HOST:?set API_HOST}/api/v1}"
  ADMIN_PHONE="${ADMIN_PHONE:?set ADMIN_PHONE}"
  LOG="${LOG:-/opt/bentorm/backend/storage/logs/laravel.log}"
  PASS=0; FAIL=0
  ok()   { echo "  ✔ $*"; PASS=$((PASS+1)); }
  bad()  { echo "  ✘ $*"; FAIL=$((FAIL+1)); }
  check(){ [[ "$2" == "$3" ]] && ok "$1" || bad "$1 (got '$2', want '$3')"; }
  req()  { curl -s -X "$1" "$API$2" -H 'Accept: application/json' -H 'Content-Type: application/json' ${3:+-H "Authorization: Bearer $3"} ${4:+-d "$4"}; }
  code() { curl -s -o /dev/null -w '%{http_code}' -X "$1" "$API$2" -H 'Accept: application/json' -H 'Content-Type: application/json' ${3:+-H "Authorization: Bearer $3"} ${4:+-d "$4"}; }
  upload(){ curl -s -X POST "$API$2" -H 'Accept: application/json' -H "Authorization: Bearer $1" "${@:3}"; }
  login(){ local phone=$1 name=$2 role=$3 c=""
    req POST /auth/otp/request "" "{\"phone\":\"$phone\"}" >/dev/null
    for _ in $(seq 1 10); do c=$(grep "SMS to +$phone" "$LOG" 2>/dev/null | tail -1 | grep -oE 'code is [0-9]{6}' | grep -oE '[0-9]{6}'); [[ -n "$c" ]] && break; sleep 0.5; done
    req POST /auth/otp/verify "" "{\"phone\":\"$phone\",\"code\":\"$c\",\"name\":\"$name\",\"role\":\"$role\",\"device_name\":\"smoke\"}" | jq -r '.token // empty'; }
  wait_offer(){ local n=0; for _ in $(seq 1 30); do n=$(req GET /driver/offers "$1" | jq '.offers|length'); [[ "$n" -ge 1 ]] && break; sleep 1; done; echo "$n"; }
  BT='"pickup_lat":13.0969,"pickup_lng":-59.6145,"dropoff_lat":13.0667,"dropoff_lng":-59.5333'
  JPG=$(mktemp --suffix=.jpg); php -r 'imagejpeg(imagecreatetruecolor(40,30), $argv[1]);' "$JPG"
  PDF=$(mktemp --suffix=.pdf); printf '%%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%%%EOF\n' > "$PDF"
  trap 'rm -f "$JPG" "$PDF"' EXIT

  echo "== Health & auth ($API)"
  check "health" "$(req GET /health | jq -r .ok)" "true"
  ADMIN=$(login "$ADMIN_PHONE" Admin rider); check "admin login" "$(req GET /me "$ADMIN" | jq -r .role)" "admin"
  RIDER=$(login 12465550101 "Smoke Rider" rider); check "rider login" "$(req GET /me "$RIDER" | jq -r .role)" "rider"
  DRIVER=$(login 12465550202 "Smoke Driver" driver); check "driver login" "$(req GET /me "$DRIVER" | jq -r .role)" "driver"
  check "no token → 401" "$(code GET /me)" "401"
  check "rider blocked from admin" "$(code GET /admin/stats "$RIDER")" "403"

  echo "== Geofence & fares"
  check "Bridgetown is served" "$(req GET '/geo/check?lat=13.0969&lng=-59.6145' | jq -r .service_area.code)" "BB"
  check "airport zone detected" "$(req GET '/geo/check?lat=13.0746&lng=-59.4925' | jq -r '.zones[0].code')" "BB-GAIA"
  check "St Lucia not served" "$(req GET '/geo/check?lat=13.9094&lng=-60.9789' | jq -r .served)" "false"
  Q=$(req POST /fares/estimate "" "{$BT}")
  check "quotes for 3 vehicle types" "$(echo "$Q" | jq '.quotes|length')" "3"
  echo "     Bridgetown → Oistins: $(echo "$Q" | jq -r '[.quotes[] | "\(.display_name) \(.currency) \(.fare)"] | join(" · ")')"
  check "5 passengers → XL only" "$(req POST /fares/estimate "" "{$BT,\"passengers\":5}" | jq -c '[.quotes[].vehicle_type]')" '["xl"]'

  echo "== Driver onboarding (documents → approval → online)"
  AREA=$(req GET /service-areas | jq -r '.[] | select(.code=="BB") | .id')
  check "driver sets area" "$(code PATCH /driver/profile "$DRIVER" "{\"service_area_id\":$AREA}")" "200"
  VEH=$(req GET /driver/vehicles "$DRIVER" | jq -r '.[0].id // empty')
  [[ -z "$VEH" ]] && VEH=$(req POST /driver/vehicles "$DRIVER" '{"make":"Toyota","model":"Corolla","plate":"SMOKE1","vehicle_type":"standard","seats":4,"luggage_capacity":2,"features":["child_seat"]}' | jq -r .id)
  req POST /driver/vehicles/$VEH/select "$DRIVER" >/dev/null
  if [[ "$(req GET /driver/profile "$DRIVER" | jq -r .can_go_online)" == "false" ]]; then
    check "blocked from going online without documents" "$(code POST /driver/online "$DRIVER")" "403"
  else
    ok "driver already cleared from a previous run"
  fi
  exp=$(date -d '+1 year' +%F)
  docup(){ # $1 path prefix, $2 checklist json
    echo "$2" | jq -c '.checklist[] | select(.type.is_required and (.state=="missing" or .state=="rejected" or .state=="expired")) | .type' | while read -r t; do
      key=$(echo "$t" | jq -r .key); img=$(echo "$t" | jq -r .is_image_only)
      args=(-F "document_type=$key"); [[ "$img" == true ]] && args+=(-F "file=@$JPG;type=image/jpeg") || args+=(-F "file=@$PDF;type=application/pdf")
      [[ $(echo "$t" | jq -r .requires_number) == true ]] && args+=(-F number=TEST-1)
      [[ $(echo "$t" | jq -r .requires_expiry) == true ]] && args+=(-F "expires_at=$exp")
      upload "$DRIVER" "$1" "${args[@]}" | jq -r '"     uploaded \(.type.name // .message)"'
    done; }
  docup /driver/documents "$(req GET /driver/documents "$DRIVER")"
  docup /driver/vehicles/$VEH/documents "$(req GET /driver/vehicles/$VEH/documents "$DRIVER")"
  PENDING=$(req GET '/admin/documents?status=pending' "$ADMIN" | jq -r '.data[].id')
  for d in $PENDING; do req POST /admin/documents/$d/review "$ADMIN" '{"decision":"approve"}' >/dev/null; done
  check "all documents approved" "$(req GET '/admin/documents?status=pending' "$ADMIN" | jq '.data|length')" "0"
  DID=$(req GET /me "$DRIVER" | jq -r .id)
  check "admin approves driver" "$(code PATCH /admin/drivers/$DID/status "$ADMIN" '{"status":"approved"}')" "200"
  check "admin approves vehicle" "$(code PATCH /admin/vehicles/$VEH/status "$ADMIN" '{"status":"approved"}')" "200"
  check "driver goes online" "$(code POST /driver/online "$DRIVER")" "200"
  check "location ping" "$(req POST /driver/location "$DRIVER" '{"lat":13.0975,"lng":-59.6140,"heading":90}' | jq -r .ok)" "true"
  check "admin live map shows a driver" "$(req GET /admin/live "$ADMIN" | jq "[.drivers[] | select(.driver_id==$DID)] | length")" "1"

  echo "== Cash ride (dispatch via queue worker + Redis)"
  for t in $(req GET /trips/active "$RIDER" | jq -r '.trip.id // empty'); do req POST /trips/$t/cancel "$RIDER" >/dev/null; done
  TRIP=$(req POST /trips "$RIDER" "{$BT,\"vehicle_type\":\"standard\",\"features\":[\"child_seat\"]}" | jq -r .id)
  check "ride requested" "$(req GET /trips/active "$RIDER" | jq -r .trip.status)" "requested"
  check "driver received the offer" "$(wait_offer "$DRIVER")" "1"
  check "accept" "$(req POST /driver/trips/$TRIP/accept "$DRIVER" | jq -r .status)" "accepted"
  check "arrive" "$(req POST /driver/trips/$TRIP/arrive "$DRIVER" | jq -r .status)" "arrived"
  check "start" "$(req POST /driver/trips/$TRIP/start "$DRIVER" | jq -r .status)" "in_progress"
  req POST /driver/location "$DRIVER" '{"lat":13.0900,"lng":-59.5900}' >/dev/null; req POST /driver/location "$DRIVER" '{"lat":13.0750,"lng":-59.5500}' >/dev/null
  DONE=$(req POST /driver/trips/$TRIP/complete "$DRIVER" '{"lat":13.0667,"lng":-59.5333}')
  check "complete, cash paid" "$(echo "$DONE" | jq -r '.status + "/" + .payment_status')" "completed/paid"
  echo "     fare $(echo "$DONE" | jq -r '.currency + " " + (.actual.fare|tostring)') · driver earns $(echo "$DONE" | jq -r .driver_earnings)"
  check "rider rates driver" "$(code POST /trips/$TRIP/rate "$RIDER" '{"score":5}')" "201"

  echo "== Card ride (saved card + hold)"
  PMID=$(req GET /me/payment-methods "$RIDER" | jq -r '.[0].id // empty')
  if [[ -z "$PMID" ]]; then
    PM=$(req POST /me/payment-methods "$RIDER" '{"lat":13.0969,"lng":-59.6145}'); PMID=$(echo "$PM" | jq -r .payment_method.id)
    ACTION=$(curl -s "$(echo "$PM" | jq -r .redirect_url)" | grep -oE 'action="[^"]+"' | head -1 | sed 's/action="//;s/"$//;s/&amp;/\&/g')
    curl -s -o /dev/null -X POST "$ACTION" -d number=4242424242424242 -d exp_month=12 -d exp_year=$(( $(date +%Y) + 3 ))
    curl -s -o /dev/null "$API/payment-methods/$PMID/return"
  fi
  check "test card saved" "$(req GET /me/payment-methods/$PMID "$RIDER" | jq -r .status)" "active"
  CR=$(req POST /trips "$RIDER" "{$BT,\"vehicle_type\":\"standard\",\"payment_method\":\"card\"}"); CTRIP=$(echo "$CR" | jq -r .id)
  check "hold approved, ride requested" "$(echo "$CR" | jq -r .status)" "requested"
  echo "     held $(echo "$CR" | jq -r .hold_amount) against estimate $(echo "$CR" | jq -r .estimate.fare)"
  check "driver received the offer" "$(wait_offer "$DRIVER")" "1"
  for s in accept arrive start; do req POST /driver/trips/$CTRIP/$s "$DRIVER" >/dev/null; done
  CD=$(req POST /driver/trips/$CTRIP/complete "$DRIVER" '{"lat":13.0667,"lng":-59.5333}')
  check "fare captured from hold" "$(echo "$CD" | jq -r .payment_status)" "paid"
  check "hold shows captured" "$(req GET "/admin/payments?trip_id=$CTRIP" "$ADMIN" | jq -r '[.data[]|select(.purpose=="hold")][0].status')" "captured"

  echo "== WebSockets"
  check "rider authorised for trip channel" "$(curl -s -o /dev/null -w '%{http_code}' -X POST "${API%/v1}/broadcasting/auth" -H "Authorization: Bearer $RIDER" -H 'Accept: application/json' -d "socket_id=123.456&channel_name=private-trip.$CTRIP")" "200"
  check "Reverb service running" "$(systemctl is-active bentorm-reverb 2>/dev/null)" "active"

  req POST /driver/offline "$DRIVER" >/dev/null
  echo; echo "PASSED $PASS · FAILED $FAIL"; [[ $FAIL -eq 0 ]]
}
main "$@" </dev/null
