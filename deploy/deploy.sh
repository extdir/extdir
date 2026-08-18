#!/usr/bin/env bash
#
# Deploy extdir to Uberspace.
#
# Run from a local checkout: deploy/deploy.sh
#
# Nothing here builds untrusted code. Dependencies are extdir's own, resolved from
# composer.lock — the no-untrusted-builds rule forbids running composer over third-party
# extension code on this host, and that rule is about the extensions we index, not
# about our own lockfile.
set -euo pipefail

REMOTE_USER="${EXTDIR_USER:-amer}"
REMOTE_HOST="${EXTDIR_HOST:-sirius.uberspace.de}"
REMOTE_PATH="${EXTDIR_PATH:-/home/$REMOTE_USER/websites/extdir}"
SSH="$REMOTE_USER@$REMOTE_HOST"

info() { printf '\033[1;34m==>\033[0m %s\n' "$1"; }

info "Checking the local tree is clean and tested"
if [ -n "$(git status --porcelain)" ]; then
    echo "Working tree is dirty. Commit or stash before deploying." >&2
    exit 1
fi

vendor/bin/phpunit --no-output
vendor/bin/phpstan analyse --no-progress --quiet

info "Syncing files to $SSH:$REMOTE_PATH"
# var/ and vendor/ are excluded: one is per-environment state, the other is
# installed remotely so the platform check runs against the real PHP version.
# .env.local carries the production secrets and must never be overwritten from a
# developer machine.
rsync -az --delete \
    --exclude '.git' \
    --exclude 'var/' \
    --exclude 'vendor/' \
    --exclude 'node_modules/' \
    --exclude '.env.local' \
    --exclude 'compose.yaml' \
    --exclude 'docker/' \
    ./ "$SSH:$REMOTE_PATH/"

info "Installing dependencies and migrating"
ssh "$SSH" bash -euo pipefail <<REMOTE
cd "$REMOTE_PATH"

composer install --no-dev --optimize-autoloader --no-interaction --no-progress

# Migrations run before the cache warms, so the new code never serves a request
# against the old schema.
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

php bin/console cache:clear --env=prod --no-debug
php bin/console cache:warmup --env=prod --no-debug

# assets/vendor is not in git, so a first deploy has nothing to compile.
php bin/console importmap:install
php bin/console asset-map:compile --env=prod --no-debug

# Workers hold the old code in memory until they are restarted; without this a
# deploy updates the website and leaves the background half running yesterday's
# build.
supervisorctl restart extdir-worker-ingest extdir-worker-build || true
REMOTE

info "Verifying the deployed site reports healthy"
if curl -fsS "https://extdir.com/health" | grep -q '"status":"ok"'; then
    info "Deploy complete and healthy."
else
    echo "Deployed, but /health is not reporting ok — check it before walking away." >&2
    exit 1
fi
