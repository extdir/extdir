# extdir/builder

**This directory is the source for a separate repository: `github.com/extdir/builder`.**
It is kept here so it stays version-controlled alongside the app that dispatches it, but it
must be pushed to its own repo before it can run.

```bash
gh repo create extdir/builder --public --description "Isolated build pipeline for extdir"
cp -r builder/* /path/to/builder-checkout/
```

## Why a separate repository

The application repository holds the database credentials, the GitHub App private key and the
webhook secret. This repository holds a write token for the artifact bucket. Keeping them apart
means compromising the public-facing Symfony process does not also hand over the ability to
overwrite artifacts that merchants download — which is the whole point of the split in
The no-untrusted-builds rule.

It also keeps every build log publicly readable, which is what makes the provenance claims in
The verifiable-build rule checkable by someone who does not trust us.

## What it does

Given a repository, a git ref and a package name, it:

1. Checks out the pinned commit — **not** the tag, which can be moved after the fact.
2. Runs a licence detector over the actual files. **This is the gate**: if no accepted SPDX
   licence is found, the job exits non-zero and nothing is produced. A `composer.json` licence
   field is a claim; this is evidence.
3. Validates the extension with `shopware-cli extension validate`.
4. Packages it with a pinned `shopware-cli` version.
5. Asserts the resulting ZIP contains the original `LICENSE` file — MIT, BSD and Apache all
   require the notice to travel with every copy, and it is cheaper to check than to apologise.
6. Generates an SBOM and a SHA-256.
7. Uploads to R2 and calls back to the app with the provenance.

## Hardening

- `permissions: contents: read` — the job cannot write to any repository.
- No secrets beyond the R2 write token and the callback secret.
- Hard timeout, so a hostile `composer install` cannot hold a runner indefinitely.
- Runs only on `workflow_dispatch`, so nothing in an untrusted repository can trigger it.

The single most important property: **the build never runs on the host holding user data or
credentials.** `composer install` and `npm install` execute arbitrary scripts from repositories
we do not control, and Uberspace is shared hosting with no isolation to contain that.

## Required secrets

| Secret | Purpose |
|---|---|
| `R2_ENDPOINT` | `https://<account-id>.r2.cloudflarestorage.com` |
| `R2_BUCKET` | `extdir-artifacts` |
| `R2_ACCESS_KEY_ID` | Object Read & Write, scoped to that one bucket |
| `R2_SECRET_ACCESS_KEY` | — |
| `EXTDIR_CALLBACK_URL` | `https://extdir.com/builder/callback` |
| `EXTDIR_CALLBACK_SECRET` | Shared secret; the app also re-verifies the run against the GitHub API |
