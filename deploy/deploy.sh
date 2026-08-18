#!/usr/bin/env bash
# =============================================================================
# deploy.sh — ship extdir to Uberspace.
#
# One idempotent script. The first run generates .env.local from the server's
# own MariaDB credentials; later runs sync code, migrate, refresh caches and
# restart the workers.
#
# Nothing here builds untrusted code. Composer resolves extdir's own
# composer.lock and nothing else — the rule against building third-party
# extension code on this host is about the extensions we index, not our
# lockfile.
#
# Flags:
#   --skip-tests    skip the local PHPUnit/PHPStan gate
#   --allow-dirty   proceed with uncommitted changes
#   --remote-only   skip the rsync, only re-run remote setup
#   --dry-run       show what would sync, change nothing
# =============================================================================
set -euo pipefail

# ---------- Config ----------------------------------------------------------
REMOTE="${EXTDIR_REMOTE:-uberspace}"
APP_DOMAIN="extdir.com"
# Named after the domain: Uberspace serves ~/websites/<domain>/ as that domain's
# DocumentRoot, so the directory name is load-bearing rather than cosmetic.
REMOTE_PATH="${EXTDIR_PATH:-/home/amer/websites/extdir.com}"
DB_NAME="${EXTDIR_DB:-amer_extdir}"
# Uberspace 7 spells it php83 — no dot, no hyphen. Bare `php` may be an older
# default and the resulting failure is confusing.
PHP_BIN="/usr/bin/php83"
TRUSTED_HOSTS='^(localhost|extdir\.com|www\.extdir\.com)$'

SKIP_TESTS=0; ALLOW_DIRTY=0; REMOTE_ONLY=0; DRY_RUN=0
for arg in "$@"; do
    case "$arg" in
        --skip-tests)  SKIP_TESTS=1 ;;
        --allow-dirty) ALLOW_DIRTY=1 ;;
        --remote-only) REMOTE_ONLY=1 ;;
        --dry-run)     DRY_RUN=1 ;;
        -h|--help)     sed -n '14,18p' "$0"; exit 0 ;;
        *) echo "Unknown flag: $arg" >&2; exit 1 ;;
    esac
done

GREEN=$'\e[32m'; RED=$'\e[31m'; DIM=$'\e[2m'; RESET=$'\e[0m'
step() { printf "  ${DIM}▸${RESET} %-50s" "$1"; }
ok()   { printf "${GREEN}✓${RESET} %s\n" "${1:-}"; }
fail() { printf "${RED}✗ %s${RESET}\n" "$1" >&2; exit 1; }

# ---------- Pre-flight ------------------------------------------------------
step "SSH to $REMOTE"
ssh -o BatchMode=yes -o ConnectTimeout=8 "$REMOTE" 'echo ok' >/dev/null 2>&1 \
    || fail "cannot reach '$REMOTE' — add it to ~/.ssh/config"
ok

if [ "$ALLOW_DIRTY" -eq 0 ]; then
    step "Working tree is clean"
    [ -z "$(git status --porcelain)" ] || fail "uncommitted changes (--allow-dirty to override)"
    ok
fi

if [ "$SKIP_TESTS" -eq 0 ]; then
    step "Tests"
    vendor/bin/phpunit --no-output >/dev/null || fail "tests failed"
    ok
    step "Static analysis"
    vendor/bin/phpstan analyse --no-progress --quiet || fail "phpstan failed"
    ok
fi

# ---------- Sync ------------------------------------------------------------
# Deliberately not .gitignore. Some tracked files have no business in production
# (tests, CI config, the local container setup), and some untracked ones are
# regenerated on the server rather than shipped.
RSYNC_EXCLUDES=(
    --exclude '.git' --exclude '.github'
    --exclude 'var/' --exclude 'vendor/' --exclude 'assets/vendor/'
    --exclude 'public/assets/' --exclude 'public/bundles/'
    --exclude 'tests/' --exclude '.phpunit.cache/'
    --exclude 'compose.yaml' --exclude 'docker/'
    --exclude 'node_modules/'
    # Server-only secrets. Overwriting these from a laptop is how a deploy takes
    # the site down with "Access denied for user".
    --exclude '.env.local' --exclude '.env.local.php'
)

if [ "$REMOTE_ONLY" -eq 0 ]; then
    if [ "$DRY_RUN" -eq 1 ]; then
        rsync -az --delete --dry-run "${RSYNC_EXCLUDES[@]}" ./ "$REMOTE:$REMOTE_PATH/"
        exit 0
    fi
    step "Syncing to $REMOTE:$REMOTE_PATH"
    rsync -az --delete "${RSYNC_EXCLUDES[@]}" ./ "$REMOTE:$REMOTE_PATH/" >/dev/null \
        || fail "rsync failed"
    ok
fi

# ---------- Remote ----------------------------------------------------------
step "Remote setup"
ssh "$REMOTE" "REMOTE_PATH='$REMOTE_PATH' DB_NAME='$DB_NAME' PHP_BIN='$PHP_BIN' \
    APP_DOMAIN='$APP_DOMAIN' TRUSTED_HOSTS='$TRUSTED_HOSTS' bash -s" <<'REMOTE_SCRIPT'
set -euo pipefail
cd "$REMOTE_PATH"

# First run only: build .env.local from the server's own credentials. The
# MariaDB password lives in ~/.my.cnf, which Uberspace generates — reading it
# here means the password is never typed, never copied and never sits in a file
# on a laptop.
if [ ! -f .env.local ]; then
    [ -f "$HOME/.my.cnf" ] || { echo "~/.my.cnf not found — cannot derive DB credentials" >&2; exit 1; }
    DB_USER=$(awk -F'=' '/^user/{gsub(/[ "]/,"",$2); print $2; exit}' "$HOME/.my.cnf")
    DB_PASS=$(awk -F'=' '/^password/{gsub(/[ "]/,"",$2); print $2; exit}' "$HOME/.my.cnf")
    # URL-encode: generated passwords routinely contain characters that would
    # otherwise terminate the DSN early.
    DB_PASS_ENC=$(python3 -c 'import sys,urllib.parse;print(urllib.parse.quote(sys.argv[1],safe=""))' "$DB_PASS")

    {
        echo "APP_ENV=prod"
        echo "APP_SECRET=$(head -c 16 /dev/urandom | od -An -tx1 | tr -d ' \n')"
        # $HOSTNAME, never 127.0.0.1: each Uberspace user has its own network
        # namespace, so php-fpm cannot reach MySQL over loopback even though an
        # interactive shell can.
        echo "DATABASE_URL=\"mysql://${DB_USER}:${DB_PASS_ENC}@${HOSTNAME}:3306/${DB_NAME}?serverVersion=10.6.19-MariaDB&charset=utf8mb4\""
        echo "TRUSTED_HOSTS='${TRUSTED_HOSTS}'"
        echo "DEFAULT_URI=https://${APP_DOMAIN}"
        echo "R2_PUBLIC_BASE_URL="
        echo "GITHUB_APP_ID="
        echo "GITHUB_APP_CLIENT_ID="
        echo "GITHUB_APP_CLIENT_SECRET="
    } > .env.local
    echo "  generated .env.local — add the GitHub App credentials, then re-run"
fi

composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --no-progress

# Migrations run before the cache warms, so new code never serves against an old
# schema.
$PHP_BIN bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

$PHP_BIN bin/console cache:clear --env=prod --no-debug
$PHP_BIN bin/console cache:warmup --env=prod --no-debug

# assets/vendor is not in git, so a fresh server has nothing to compile.
$PHP_BIN bin/console importmap:install
$PHP_BIN bin/console asset-map:compile --env=prod --no-debug

# Compile .env into one PHP file: one less read per request, and it keeps the
# plaintext .env.local off the request path.
composer dump-env prod

# Workers hold the old code in memory; without this the site updates while the
# background half keeps running yesterday's build.
supervisorctl restart extdir-worker-ingest extdir-worker-build 2>/dev/null || true
REMOTE_SCRIPT
ok

# ---------- Verify ----------------------------------------------------------
step "Health check"
if curl -fsS --max-time 20 "https://$APP_DOMAIN/health" | grep -q '"status":"ok"'; then
    ok "deployed"
else
    fail "deployed, but https://$APP_DOMAIN/health is not ok — check before walking away"
fi
