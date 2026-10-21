#!/usr/bin/env bash
# Bentorm bootstrap — stable entry point. Clones/updates the repo, then runs scripts/install.sh FROM THE CHECKOUT
# so the installer you get is always the current one (raw.githubusercontent.com caches files for ~5 minutes).
#
#   curl -fsSL "https://raw.githubusercontent.com/caritechsolutions/bentorm/main/scripts/bootstrap.sh?$(date +%s)" | sudo ADMIN_PHONE=... API_HOST=... WS_HOST=... bash
set -euo pipefail
main() {
  [[ $EUID -eq 0 ]] || { echo "Run as root (curl ... | sudo bash)" >&2; exit 1; }
  REPO_URL="https://github.com/caritechsolutions/bentorm"
  INSTALL_DIR="/opt/bentorm"
  BRANCH="${BRANCH:-main}"
  command -v git >/dev/null || { apt-get update -qq && apt-get install -y -qq git >/dev/null; }
  git config --global --add safe.directory "$INSTALL_DIR" >/dev/null 2>&1 || true
  if [[ -d "$INSTALL_DIR/.git" ]]; then
    git -C "$INSTALL_DIR" fetch -q origin "$BRANCH"
    git -C "$INSTALL_DIR" reset -q --hard "origin/$BRANCH"
  else
    git clone -q -b "$BRANCH" "$REPO_URL" "$INSTALL_DIR"
  fi
  echo "==> Running installer from $INSTALL_DIR @ $(git -C "$INSTALL_DIR" rev-parse --short HEAD)"
  exec bash "$INSTALL_DIR/scripts/install.sh"
}
main "$@" </dev/null
