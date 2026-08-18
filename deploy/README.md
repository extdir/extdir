# Deploying extdir to Uberspace

Everything here is specific to a shared host with no root, no Docker and no process
isolation. That constraint is the reason for most of the decisions below, not an
inconvenience to work around.

## One-time setup

### 1. Web and mail domains

```bash
uberspace web domain add extdir.com
uberspace mail domain add extdir.com
```

### 2. DNS records

All five are required. **Four of them were initially added at the wrong name**, so
the Host column matters more than it looks.

| Type | Host | Value |
|---|---|---|
| `A` | `@` | `95.143.172.236` |
| `AAAA` | `@` | `2001:1a50:11:0:b0fa:c6ff:fe35:8e51` |
| `MX` | `@` (priority `0`) | `sirius.uberspace.de.` |
| `TXT` | `@` | `v=spf1 include:spf.uberspace.de ~all` |
| `TXT` | `uberspace._domainkey` | `v=DKIM1;t=s;n=core;p=…` (from `uberspace mail domain add`) |
| `TXT` | `_dmarc` | `v=DMARC1; p=none; rua=mailto:legal@extdir.com; fo=1` |

Two traps, both of which cost an evening:

- **On Namecheap the Host field is relative to the domain.** Entering `extdir.com`
  creates the record at `extdir.com.extdir.com`, which resolves to nothing and
  gives no error. Use `@` for the apex and the bare subdomain otherwise.
- **The DKIM value is 413 characters and a single DNS TXT string is capped at
  255.** It has to be split into two quoted strings. Most panels concatenate them
  automatically; some reject the record silently if you do not.

Verify rather than assume — DNS resolving is not the same as the signature
verifying:

```bash
dig +short MX extdir.com
dig +short TXT uberspace._domainkey.extdir.com
dig +short @1.1.1.1 TXT _dmarc.extdir.com   # bypass local negative caching
```

Then send a test mail to a Gmail address and read *Show original*. You want
`SPF: PASS`, `DKIM: PASS` and `DMARC: PASS`. Mail delivered is not proof: the
first test delivered fine while DKIM was failing.

`legal@extdir.com` is published in the Impressum, the privacy policy and the
takedown policy as the § 5 DDG contact. It has to work in **both** directions.

### 3. Database

```bash
mysql -e "CREATE DATABASE ${USER}_extdir DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```

### 4. Secrets

`.env.local` on the server, never synced from a developer machine:

```
APP_ENV=prod
APP_SECRET=<generate: php -r 'echo bin2hex(random_bytes(16));'>

# $HOSTNAME, not 127.0.0.1 — see the gotcha below.
DATABASE_URL="mysql://<user>:<pass>@$HOSTNAME:3306/${USER}_extdir?serverVersion=10.6.19-MariaDB&charset=utf8mb4"

GITHUB_APP_ID=…
GITHUB_APP_CLIENT_ID=…
GITHUB_APP_CLIENT_SECRET=…
```

Then authorise the crawler once, interactively:

```bash
php bin/console app:github:authorize
```

### 5. Workers and schedule

```bash
cp deploy/services/*.ini ~/etc/services.d/
supervisorctl reread && supervisorctl update
crontab deploy/cron/crontab
mkdir -p ~/logs ~/backups/extdir
```

## Deploying

```bash
deploy/deploy.sh
```

Refuses to run with a dirty working tree, runs the tests and PHPStan locally
first, syncs, migrates, warms the cache, restarts the workers, and finally checks
that `/health` reports `ok`. A deploy that leaves the site unhealthy exits
non-zero rather than reporting success.

## The Uberspace gotcha that will cost you an hour

**`DATABASE_URL` must use `$HOSTNAME:3306`, never `127.0.0.1`.**

Each Uberspace user runs in a separate network namespace. A php-fpm process or a
supervisord service cannot reach MySQL over loopback — but an interactive SSH
session *can*, so `bin/console` works from the shell while the website returns
500s. The symptom points at the application; the cause is the DSN.

## GitHub App redirect URI

Must be **`https://`**, exactly:

```
https://extdir.com/auth/github/callback
```

GitHub matches redirect URIs exactly, and the application generates an absolute
`https` URL in production — an `http://` registration fails the handshake with
`redirect_uri_mismatch`. It is also the wrong scheme on its own terms: the callback
carries an OAuth authorisation code in the query string, and over plaintext that
code is readable by anyone on the path.

Add `http://localhost:8001/auth/github/callback` as a second URI for local work.
Plain HTTP is fine there — loopback is exempt by convention and never leaves the
machine.

Production is forced to HTTPS by `requires_channel` in `security.yaml`, so an
`http://` request redirects rather than being served.

## Health and monitoring

`/health` returns `200` with `{"status":"ok"}` or `503` when degraded. It checks
four things:

| Check | Fails when |
|---|---|
| `database` | The connection is refused — usually the loopback DSN above |
| `catalog` | The index is empty |
| `freshness` | No crawl has completed in 48 hours |
| `reference_data` | No current Shopware version — the matrix would be meaningless |

Freshness is a **failure**, not a metric. The dangerous outage for a directory is
not the site going down; it is the site staying up while serving compatibility
data that stopped being refreshed three weeks ago because a worker died or a
token expired. Point an uptime monitor at `/health`, not at `/`.

Because a stale index answers **503** rather than 200-with-a-warning, a plain
status-code check is enough — no paid keyword-matching feature is needed to catch
the failure that actually matters.

Two free services cover different halves of this, and they are complementary:

| Service | Free tier | What it catches |
|---|---|---|
| [UptimeRobot](https://uptimerobot.com) | 50 monitors, 5-minute checks | `/health` returning non-200 — the site being down, *or* the data being stale |
| [Healthchecks.io](https://healthchecks.io) | 20 checks, 3 months of history | A cron job that did not run at all. `/health` notices after 48 hours; this notices within minutes and names the job |

Point UptimeRobot at `https://extdir.com/health` first — it is the one that
matters. Add heartbeat pings to the crontab later if a crawl ever fails quietly
enough to be annoying.

## Backups

`deploy/backup.sh` runs nightly, keeps 14 days, and fails loudly if the dump is
empty — a zero-byte file that looks like a backup is worse than no backup.
Uberspace's own snapshots protect against hardware loss, not against a migration
that drops the wrong column.

Restore:

```bash
gunzip -c ~/backups/extdir/extdir-YYYY-MM-DD.sql.gz | mysql ${USER}_extdir
```

## Nothing untrusted is ever built here

`composer install` and `npm install` execute arbitrary scripts. Extension code is
third-party and hostile-by-default, so it is never installed or built on this
host — that is what `extdir/builder` and its ephemeral runners are for
(the no-untrusted-builds rule). The `composer install` in the deploy script resolves extdir's
own `composer.lock` and nothing else.
