#!/usr/bin/env bash
# Ride Coop — check the call relay (coturn) from OUTSIDE your network.
#
# On the Ride Coop server, get a 30-minute login and the exact command:
#   cd /opt/bentorm/backend && sudo php artisan bentorm:turn-test
# Then on any Linux machine outside your network (Ubuntu/Debian, as root):
#   curl -fsSL ".../scripts/check-turn.sh?$(date +%s)" | sudo bash -s -- turn.ridecoop.net 'USER' 'PASS'
#
# Tests: DNS · UDP 3478 · TCP 3478 · relay ports · relay-to-relay (both phones on the relay).
# Installs only the coturn test tools here; the coturn service on this machine is left off.
set -uo pipefail

HOST="${1:-}"; USER_="${2:-}"; PASS="${3:-}"
PEER_PORT="${PEER_PORT:-3480}"
TURN_PORT="${TURN_PORT:-3478}"
[[ -n "$HOST" && -n "$USER_" && -n "$PASS" ]] || { echo "Usage: check-turn.sh HOST USER PASS  (get them from: artisan bentorm:turn-test)"; exit 2; }
[[ $EUID -eq 0 ]] || { echo "Run as root (… | sudo bash -s -- …)"; exit 2; }

ok()   { echo "  ✔ $*"; }
bad()  { echo "  ✘ $*"; FAILED=1; }
note() { echo "    $*"; }
FAILED=0
WORK=$(mktemp -d); trap 'pkill -f "turnutils_peer -p $PEER_PORT" 2>/dev/null; rm -rf "$WORK"' EXIT

# ---------------------------------------------------------------- tools
if ! command -v turnutils_uclient >/dev/null; then
  echo "==> Installing the coturn test tools"
  if command -v apt-get >/dev/null; then
    had_service=0; systemctl list-unit-files coturn.service >/dev/null 2>&1 && had_service=1
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq coturn >/dev/null || { echo "Could not install coturn"; exit 1; }
    # Only the test tools are wanted: don't leave a TURN server running on this machine.
    [[ $had_service -eq 0 ]] && { systemctl disable --now coturn >/dev/null 2>&1 || true; }
  elif command -v dnf >/dev/null; then
    dnf install -y -q coturn-utils >/dev/null 2>&1 || dnf install -y -q coturn >/dev/null || { echo "Could not install coturn (EPEL needed?)"; exit 1; }
  else
    echo "Install coturn's turnutils_uclient first, then run again."; exit 1
  fi
fi

run() { # run NAME args… → output in $WORK/NAME
  local name=$1; shift
  timeout 15 stdbuf -oL -eL turnutils_uclient -v -p "$TURN_PORT" "$@" > "$WORK/$name" 2>&1
}
relay_addr() { grep -oE 'Received relay addr: [0-9.]+:[0-9]+' "$WORK/$1" | head -1 | awk '{print $4}'; }
received()   { grep -oE 'tot_recv_msgs=[0-9]+' "$WORK/$1" | tail -1 | cut -d= -f2; }
why()        {
  if grep -q 'Cannot complete Allocation' "$WORK/$1"; then note "The relay answered but refused the login (expired? copy the command again from bentorm:turn-test)."
  elif grep -q 'Forbidden IP' "$WORK/$1"; then note "The relay refused that destination (403 Forbidden IP)."
  else note "No answer — the port is closed or not forwarded."; fi
}

echo "==> Call relay check for $HOST"
IP=$(getent ahostsv4 "$HOST" | awk 'NR==1{print $1}')
if [[ -z "$IP" ]]; then bad "DNS: $HOST does not resolve — add an A record to your public IP (DNS only, not proxied)"; exit 1; fi
ok "DNS: $HOST → $IP"
case "$IP" in 10.*|192.168.*|172.1[6-9].*|172.2[0-9].*|172.3[01].*) bad "That is a private address — the A record must be your PUBLIC IP";; esac
MYIP="${MYIP:-$(curl -fsS --max-time 5 https://api.ipify.org 2>/dev/null || true)}"
[[ "$MYIP" == "$IP" ]] && note "(this machine has the same public IP as the relay — run the check from a machine on ANOTHER network)"

# A small echo listener here, so each test finishes quickly instead of holding relay ports until it times out.
PEER="${MYIP:-192.0.2.1}"
[[ -n "$MYIP" ]] && { (setsid turnutils_peer -p "$PEER_PORT" -L 0.0.0.0 > "$WORK/peer" 2>&1 &); sleep 1; }

# 1. UDP 3478: get a relay address
run udp -u "$USER_" -w "$PASS" -e "$PEER" -r "$PEER_PORT" -n 3 -m 1 "$HOST"
R=$(relay_addr udp)
if [[ -n "$R" ]]; then
  ok "UDP $TURN_PORT: relay login works, relay address $R"
  case "${R%%:*}" in
    10.*|192.168.*|172.1[6-9].*|172.2[0-9].*|172.3[01].*) bad "…but that's a PRIVATE address: the relay doesn't know its public IP. Re-run the update with TURN_PUBLIC_IP=$IP";;
    "$IP") ;;
    *) note "(relay address differs from the DNS address $IP — fine if that is also your public IP)";;
  esac
else bad "UDP $TURN_PORT: no relay"; why udp; fi

# 2. TCP 3478 (phones on networks that block UDP)
run tcp -t -u "$USER_" -w "$PASS" -e "$PEER" -r "$PEER_PORT" -n 3 -m 1 "$HOST"
if [[ -n "$(relay_addr tcp)" ]]; then ok "TCP $TURN_PORT: works"; else bad "TCP $TURN_PORT: no relay"; why tcp; fi

# 3. Relay ports: the relay sends to a test listener here; the answer must come back in on UDP 49160-49200
if [[ -n "$R" && -n "$MYIP" ]]; then
  run ports -u "$USER_" -w "$PASS" -e "$MYIP" -r "$PEER_PORT" -n 20 -m 1 -l 120 "$HOST"
  N=$(received ports)
  if [[ "${N:-0}" -gt 0 ]]; then ok "Relay ports: open (test packets came back)"
  else
    bad "Relay ports: nothing came back"
    note "Either the relay ports (UDP 49160-49660, or the range the update printed) are not forwarded to the server, or this machine blocks incoming UDP $PEER_PORT"
    note "(open UDP $PEER_PORT in this machine's firewall / cloud security group and run again to tell which)."
  fi
fi

# 4. Relay to relay: both phones on the relay (e.g. both on mobile data). Needs the router to loop back
#    traffic sent to its own public IP ("hairpin NAT" / "NAT loopback").
if [[ -n "$R" ]]; then
  run hairpin -u "$USER_" -w "$PASS" -y -n 20 -m 1 -l 120 "$HOST"
  N=$(received hairpin)
  if [[ "${N:-0}" -gt 0 ]]; then ok "Relay to relay: works"
  else
    bad "Relay to relay: nothing came back"
    note "Turn on hairpin NAT / NAT loopback for the relay ports on the router (or use a cloud server for the relay)."
    note "Calls still work when at least one phone can be reached directly (e.g. on Wi-Fi)."
  fi
fi

echo
if [[ $FAILED -eq 0 ]]; then echo "All good — the call relay is reachable from outside."; else echo "Some checks failed — see the ✘ lines above."; fi
exit $FAILED
