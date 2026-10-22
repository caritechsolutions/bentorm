#!/usr/bin/env bash
# Runs the backend test suite against the latest main in a throwaway directory (never touches /opt/bentorm).
#   curl -fsSL "https://raw.githubusercontent.com/caritechsolutions/bentorm/main/scripts/test.sh?$(date +%s)" | sudo bash
set -euo pipefail
main() {
  REPO_URL="https://github.com/caritechsolutions/bentorm"
  BRANCH="${BRANCH:-main}"
  TMP=$(mktemp -d /tmp/bentorm-test.XXXXXX)
  trap 'rm -rf "$TMP"' EXIT

  echo "==> Cloning $BRANCH"
  git clone -q --depth 1 -b "$BRANCH" "$REPO_URL" "$TMP/repo"
  cd "$TMP/repo/backend"
  echo "==> Testing commit $(git rev-parse --short HEAD)"

  echo "==> Installing dependencies (incl. dev)"
  COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --quiet
  cp .env.example .env
  php artisan key:generate --quiet

  echo "==> Running tests"
  php artisan test --compact "$@" || { echo; echo "TESTS FAILED"; exit 1; }
}
main "$@" </dev/null
