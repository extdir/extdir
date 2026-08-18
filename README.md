# extdir

A community-run directory of open-source Shopware 6 extensions.

It exists to answer one question that is currently hard to answer:

> Is there an open-source extension that does X, is it maintained, and does it work with my
> Shopware version?

The compatibility matrix and the maintenance signals are the product. Everything else supports
them.

**Not affiliated with Shopware AG.** "Shopware" is used descriptively, to say what these
extensions are for. Operated and maintained by [runio.dev](https://runio.dev).

The platform code is public because nobody should trust artifacts produced by a black box.

---

## Local development

Requires Docker and PHP 8.3 with `intl`, `pdo_mysql` and `zip`.

```bash
composer install
docker compose up -d db          # MariaDB 10.6, pinned to match production
bin/console doctrine:migrations:migrate
bin/console app:shopware:sync-versions
```

The database is exposed on `127.0.0.1:13306` so `bin/console` runs directly on the host. To serve
the app in containers instead:

```bash
docker compose up -d             # nginx on http://localhost:8080
```

### Checks

```bash
vendor/bin/phpunit                              # tests
vendor/bin/phpunit --filter SpdxAllowlistTest   # one test class
vendor/bin/phpstan analyse                      # static analysis, level 8
vendor/bin/php-cs-fixer fix                     # code style
bin/console doctrine:schema:validate            # entities vs. migrations
```

`doctrine:schema:validate` is part of CI. If it fails, an entity changed without a migration.

The test database is separate and does **not** migrate itself. After pulling changes that
add a migration, run it or the functional tests fail with a missing column that has nothing
to do with the change you are making:

```bash
bin/console doctrine:migrations:migrate --no-interaction --env=test
```

### Deploying

Production runs on Uberspace shared hosting. See [deploy/README.md](deploy/README.md) for the
one-time setup, the five DNS records, and the loopback gotcha that will otherwise cost you an
hour.

---

## Things that will bite you

**MariaDB 10.6, not MySQL and not a newer MariaDB.** Production is Uberspace shared hosting on
10.6.19, which has no native `UUID` type and stores `JSON` as `LONGTEXT`. The container and CI both
pin 10.6 so a schema that works locally cannot fail on deploy.

**`DATABASE_URL` on Uberspace must use `$HOSTNAME:3306`, not `127.0.0.1`.** Each Uberspace user
sits in a separate network namespace, so php-fpm and supervisord services cannot reach MySQL over
loopback.

**Packagist p2 documents are delta-encoded.** Only the first version entry is complete; later
entries list just what changed, and `__unset` removes a field. For `shopware/core`, 122 of 209
entries carry no `require` block of their own. Expansion goes through
`composer/metadata-minifier` — the same code Composer uses. `PackagistClientTest` guards this,
because getting it wrong empties the compatibility matrix without producing a single error.

**Compatibility data is self-reported.** `require."shopware/core"` is a maintainer's claim, not a
test result. Every claim carries a confidence tier and the UI says "declares support for", never
"works with".

---

## Hard rules

These are legal and safety constraints, not preferences. They are set out in full in
[docs/brief.md](docs/brief.md) and summarised here because they constrain almost every change:

- **No LICENSE means all rights reserved.** Public readability is not permission to redistribute.
  Unlicensed extensions are indexed and linked, never built or hosted.
- **Untrusted code is never built on the application host.** `composer install` and `npm install`
  run arbitrary scripts; builds happen only in ephemeral CI runners.
- **Link before hosting.** A maintainer's own ZIP, then the tag zipball, and only then something
  we built ourselves — clearly labelled as unofficial.
- **Ranking is algorithmic and public.** No manual boosting, no featured slots. The maintainer
  publishes extensions under the `runio` vendor; those are indexed content like any other, with a
  disclosure badge and no privileged treatment anywhere in the code.

## License

MIT. See [LICENSE](LICENSE).
