# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

Entries are written for whoever deploys this server: what changed, and what
you have to do when upgrading. When cutting a release, copy the section for
that version into the *Changelog* field of the upload form — that text is what
instances see in their update panel.

## [1.11.0] - 2026-10-09

A security release, from a review of everything added since the first one.

### Fixed

- **A stolen publish credential could make apps install a release it
  uploaded.** The docs promised it could only add a release nobody is served,
  but `required` was passed through from the API — and a required step is
  served *ahead of the newest release* to every app that is behind it. Marked
  required below the latest, an uploaded release was sent to all of them (apps
  that verify signatures still refuse it; the others do not).

  Through the API a release can now be a required step only if its version is
  above every version the project already has — which is what a release
  shipping `vendor/` is when it is cut, so the publish workflow is unaffected.
  Anything else is refused with a `422` saying so. Marking an older release
  required is done from the project page, where a person is deciding.

- **Behind a reverse proxy, the per-IP limits were one limit for everybody.**
  Every request arrived from the proxy's address, so the 10-a-minute cap on
  the login form and the 20-a-minute cap on publishing were shared by all
  clients: anyone who could reach the site could lock the admin out of logging
  in, or stop a pipeline from publishing, just by sending requests. The setting
  that fixes it could not be reached from the Docker image at all —
  `Config\App::$proxyIPs` is an array, and the environment only overrides keys
  that already exist.

  `TRUSTED_PROXIES` (IPs or CIDRs, comma-separated) now fills it, with
  `TRUSTED_PROXY_HEADER` to name the header if it is not `X-Forwarded-For`. A
  header from anywhere else is still ignored.

- **Publish tokens outlived their project.** A token is scoped
  `publish:{slug}` — to a name, not a project — and deleting or renaming a
  project revoked nothing, so whichever project next took that slug inherited
  every old token. They are now revoked with the project, or when its slug
  changes.

- **Editing a project could fail with a 500, or leave it with no slug.** A slug
  that normalised to nothing was saved, and one already held by another project
  hit the database's unique key. Both are now refused with a message.

- **The admin session cookie was never marked `Secure`**, even on a deployment
  forcing HTTPS. It now follows `app_forceGlobalSecureRequests`, and is left
  alone without it so development over `http://` still holds a session.

- **Logging out was a GET**, so any page the admin opened could end their
  session with an image tag. It is now a POST behind the CSRF filter, with the
  navbar button turned into a form.

### Changed

- **`forgelab-me/ci4-updater` moves from 2.16.0 to 2.17.0**, the release that
  lets an update carry root-level files such as `composer.json` and
  `composer.lock`. This server already allows those two through
  `Config\Updater::$allowedFiles`, so nothing needs configuring.

### Upgrading

**This release must ship `vendor/`**, with `composer.json` and `composer.lock`
alongside: the dependencies moved. The publish workflow works that out from
`composer.lock` and marks the release as a required step on its own. No
migration.

Three things to check on your side:

- **Behind a reverse proxy, set `TRUSTED_PROXIES`** — the proxy's address, or
  `172.16.0.0/12` for one on the Docker host. See
  [docs/docker.md](docs/docker.md#behind-a-reverse-proxy). Leave it empty when
  the server is reached directly.
- **A pipeline that marks a release `required` and is not newer than every
  existing version now gets a `422`.** Upload it without `required` and mark it
  from the project page.
- **Anything linking to `/auth/logout` stops working** — it is a POST now. The
  panel's own button was changed with it.

Deleting or renaming a project also revokes its publish tokens, so a pipeline
using one needs a new one. Trusted Publishing is unaffected: it mints a fresh
token on every run.

## [1.10.2] - 2026-09-03

### Fixed

- **CI hardening, flagged by GitHub's own security checks — nothing here
  touches the shipped app.**
  - `tests.yml` carried no `permissions` block, so its `GITHUB_TOKEN` held
    the default read-write grant on a job that only checks out code and runs
    `phpunit`. Scoped to `contents: read`, matching `docker.yml` and
    `publish.yml`, which already had one.
  - The four `docker/*` actions in `docker.yml` (`setup-buildx-action`,
    `login-action`, `metadata-action`, `build-push-action`) were still on
    majors built against Node 20, which GitHub Actions runners are
    deprecating. Bumped to the majors built against Node 24
    (`v3→v4`, `v3→v4`, `v5→v6`, `v6→v7`); nothing in this workflow's own
    inputs changed underneath them.

### Upgrading

Nothing to deploy. This release touches `.github/workflows/` only — the
repository's own CI, not `app/`, `public/`, or `vendor/`.

## [1.10.1] - 2026-08-26

### Fixed

- **A release shipping `vendor/` could leave `composer.json`/`composer.lock`
  describing a different dependency set than what was actually applied.**
  Both files live at the project root, outside every root `update:manifest`
  scans (`app`, `public`, `vendor`), so swapping in a new `vendor/` never
  touched them — a `composer install` run later on the same install would
  reproduce the *previous* dependency set, not the one actually running.

  The publish workflow now passes `--files composer.json,composer.lock` to
  `update:manifest` precisely when `vendor/` ships, and
  `Config\Updater::$allowedFiles` names them so this server accepts writing
  them when it applies an update to itself. Releases that don't touch
  `vendor/` are unaffected — there's nothing to keep in sync.

### Upgrading

Nothing to configure. This release itself ships `vendor/` (the trigger for
the fix to take effect) and is scoped `app,public,vendor` with
`composer.json`/`composer.lock` alongside it, so applying it is also the
first release where the two land back in sync.

## [1.10.0] - 2026-08-26

### Changed

- **`forgelab-me/ci4-updater` moves from 2.11.0 to 2.16.0** — five releases'
  worth of changes to the self-update system this server runs on itself. Two
  are worth knowing about; the rest apply with nothing to do:

  - **A maintenance window now opens while an update is writing files**, and
    every route except `/admin/updates` itself answers `503` for as long as
    it's open — the public API included, so a connected app can never fetch a
    release mid-write. It's opt-in at the package level; this release wires
    the filter up in `app/Config/Filters.php`. Closes the moment writing
    finishes, or on its own after ten minutes if the process dies first.
  - **A restore now offers to revert the migrations an update ran**, not just
    the files, when rolling back to a backup made from 2.15+.

  Drift detection (2.16.0), `updater:check`/`updater:apply` CLI commands
  (2.12.0), and manifests declaring their PHP/extension requirements (2.13.0)
  all apply automatically — nothing here required a change.

### Upgrading

This release must ship `vendor/` — scoped `app,public,vendor`, marked as a
required step, or `composer install` run on the server. No migration, no
configuration: the maintenance window costs nothing unless an update is
actually applying.

## [1.9.0] - 2026-08-26

### Added

- **Trusted Publishing now accepts GitLab CI, not just GitHub Actions**, via
  [`forgelab-me/ci4-trusted-publishing`](https://github.com/forgelab-me/ci4-trusted-publishing)
  ^1.1. A trusted publisher's Provider field picks which CI system it trusts;
  GitLab's project path is allowed to nest (`group/subgroup/project`), unlike
  GitHub's flat `owner/repo`.

  Which provider verifies an incoming token is picked from its own (unverified)
  `iss` claim before anything is checked — not tried against every configured
  provider in turn — so a GitHub-signed token never costs a live fetch of
  GitLab's keys, and vice versa. See [docs/publishing.md](docs/publishing.md)
  for a GitLab CI workflow.

- **A trusted publisher can now pin the workflow file itself**, not just the
  repository and environment. Leave it blank and any workflow in the
  repository satisfies the row, same as before; set it and a token minted by
  any *other* pipeline file in that repository — someone else's job, added
  later, with different reviewers — is refused.

### Upgrading

Run `php spark migrate --all` from `src/` — one new nullable column on
`trusted_publishers`, starting empty on every existing row, which is exactly
"accepts any workflow" and changes nothing for a publisher already trusted.

This release must ship `vendor/`: `forgelab-me/ci4-trusted-publishing` moved
from 1.0 to 1.1. A release scoped `app,public,vendor`, or `composer install
--no-dev` on the server, and mark it as a required step.

## [1.8.0] - 2026-08-21

### Added

- **The dashboard flags a release whose archive is missing from disk.** The
  "latest" release is what `/api/{slug}/files/.../release.zip` actually
  serves — when its zip is gone (moved, deleted, or lost to a past bug),
  every real client downloading it gets a silent 404 with nothing in the
  panel to explain why. A red **File missing** badge next to the project's
  latest version says so outright, and the affected release's **Size**
  column in its own releases table shows **Missing** instead of a size.

  Checked against the actual filesystem on every dashboard load rather than
  stored, so it can never go stale. Only the latest release is checked here —
  an older, un-promoted release with a missing file isn't breaking any
  download, so it stays a detail of the project's own releases table.

### Upgrading

Nothing to do beyond deploying — no migration, no configuration. Covers
`app/` only.

## [1.7.0] - 2026-08-21

### Added

- **The releases table shows each archive's size.** Read straight off disk
  rather than stored, so what's displayed can never drift from the bytes
  actually served.

- **The dashboard flags a release that outranks "latest" but was never
  promoted.** Uploading ahead of what's currently being served is easy to do
  by accident — the project page already shows it release by release; the
  dashboard now surfaces it across every project, before it becomes a support
  question about why an app isn't seeing an upload that's clearly there.

  Compared by version (`version_compare()`), not by upload order or date: an
  upload made later doesn't mean its version is higher.

### Upgrading

Nothing to do beyond deploying — no migration, no configuration. Covers
`app/` only.

## [1.6.4] - 2026-08-21

### Fixed

- **A crafted `manifest.json` inside a release archive could exhaust server
  memory.** `readManifestFromZip()` read the entry fully into memory before
  looking at its size — a small, highly-compressible entry can expand past a
  gigabyte on extraction. The uncompressed size is now checked with
  `ZipArchive::statName()` before anything is pulled out, and anything over
  1MB (generous for a manifest) is refused the same way a missing one is.

  Reachable only by whoever already holds a publish credential — a project's
  publish token or a trusted GitHub workflow — but that is exactly the
  boundary Trusted Publishing exists to manage: a compromised CI runner
  shouldn't be able to take the server down for every project with one
  upload.

### Added

- **The publish endpoints are now rate-limited.** `POST /api/{slug}/publish/token`
  and `POST /api/{slug}/releases` were the only unthrottled state-changing
  routes — Shield's `auth-rates` already covers the login form. Both now allow
  20 requests per minute per IP; a request past that gets a `429` with a JSON
  body instead of Shield's redirect, since these are API routes, not forms.

### Changed

- **CSRF cookie and token names are no longer the CodeIgniter tutorial
  defaults** (`csrf_test_name`, `csrf_cookie_name`), and `tokenRandomize` is
  on. Neither closes a real hole on its own — the token was already
  unguessable — but a default name fingerprints the framework for free, and
  randomization is a no-cost mitigation against BREACH-style compression
  attacks.

### Upgrading

Nothing to do beyond deploying — no migration, no configuration, and no new
dependency: this covers `app/` only. Anyone with an admin tab already open
before the upgrade should reload it once; its form still carries the old CSRF
field name and would otherwise fail its first submission after the deploy.

## [1.6.3] - 2026-08-18

### Changed

- **Trusted Publishing now comes from
  [`forgelab-me/ci4-trusted-publishing`](https://github.com/forgelab-me/ci4-trusted-publishing)**
  rather than from three files kept here. Nothing changes for anyone
  publishing: same endpoints, same credentials, same answers.

  It moved because a second application grew its own copy of it, and the two
  had already drifted apart within a day — over trimming whitespace, and over
  what a refusal says. This is code that verifies a signed statement from a
  third party, where the traps are `alg: none`, algorithm confusion, a replayed
  audience and trusting a repository name that can be renamed away from you. It
  has to be fixed in one place, and adding GitLab CI later has to be done once.

  What stayed here is what belongs here: the `trusted_publishers` table with
  its cascade to `projects`, what the scope `publish:{slug}` permits, and the
  panel. The package owns identity; this application owns authorization.

- `firebase/php-jwt` is no longer a direct dependency — it arrives with the
  package, which is what uses it.

### Upgrading

**This release must ship `vendor/`.** It adds a dependency, and the code
already deployed would otherwise call classes the server does not have. A
release scoped `app,public,vendor`, or `composer install --no-dev` on the
server. The publishing workflow works this out on its own from `composer.lock`,
and marks the release as a required step.

No migration, no configuration. `app/Config/TrustedPublishing.php` arrives with
working defaults: the audience stays this server's own base URL, and GitHub
Actions is the one provider enabled.

## [1.6.2] - 2026-08-18

### Fixed

- **Expired publish tokens are now cleared out.** Shield stops honouring a
  token past its expiry but never deletes it, and Trusted Publishing mints one
  per workflow run — so the project page filled with dead entries, and the
  long-lived tokens worth finding and revoking got lost among them.

  Minting now deletes the expired tokens of that project first. That is where
  the growth happens, so nothing has to be scheduled, and the sweep is scoped
  to the project: another project's tokens are never touched.

  Expiry and revocation stay different things. A token with no expiry date is
  left alone — Shield still refuses one unused for longer than
  `unusedTokenLifetime`, a year by default.

### Upgrading

Nothing to do beyond deploying. Covers `app/` only. Expired tokens already in
the table go on the next mint.

## [1.6.1] - 2026-08-18

### Fixed

- **A refused upload now says why.** Both the panel and the publish API
  answered *A valid ZIP file is required* whether the archive was malformed,
  larger than PHP's `upload_max_filesize`, or dropped whole because it exceeded
  `post_max_size`. Only the first of those is about the archive, and it is the
  least likely: a release shipping `vendor/` is the first upload big enough to
  meet those limits, so the message sent you inspecting a file that was fine.

  Each case is now named, `upload_max_filesize` and `post_max_size` included,
  so the setting to change is in the message itself.

### Upgrading

Nothing to do beyond deploying. Covers `app/` only — the dependencies have not
moved since 1.6.0.

## [1.6.0] - 2026-08-17

### Added

- **Publishing a release from CI**, two ways in, one door out: a token scoped
  `publish:{slug}`, or Trusted Publishing, which exchanges the identity token
  GitHub mints for a workflow against one of those tokens, valid 15 minutes.
  The upload endpoint only ever sees a scoped token, so Trusted Publishing is a
  way of *obtaining* the credential, not a second way of using it.

  ```
  POST /api/{slug}/publish/token    Authorization: Bearer <GitHub OIDC token>
  POST /api/{slug}/releases         Authorization: Bearer <publish token>
  ```

  Both are managed on the project page. See [docs/publishing.md](docs/publishing.md).

- Tokens are **Shield access tokens**, not a mechanism of our own: Shield
  already stores them hashed, scopes them, and expires them per token.

- Trusted publishers are pinned on the **numeric GitHub account id**, not just
  the repository name. A name can be renamed, or freed and claimed by someone
  else; an account id is never reassigned. An `environment` can be required,
  which is what puts a protected environment's approval between a push and a
  publish — worth it especially when the signing key lives in CI.

### Changed

- **Publishing through the API never promotes a release to latest**, whatever
  the caller sends. Promotion stays a click in the panel, so a stolen publish
  credential can only add a release nobody is served. The panel's own upload
  form keeps its checkbox — a human is already deciding there.

- **A version that already exists is refused** rather than overwritten. Before,
  re-uploading a version overwrote its files and inserted a second row with the
  same number. Published bytes have to be immutable: an app that already
  downloaded a version must never get different files under the same name.
  This affects the admin form too.

- The admin form and the publish API now share one ingestion path, so the
  version validation that keeps an archive from writing outside
  `writable/uploads`, the verbatim manifest bytes a signature depends on, and
  the recorded scope cannot drift between them.

### Upgrading

This release adds a dependency, `firebase/php-jwt` — Shield's own JWT addon
signs for *your* users with *your* keys and cannot verify a third party's token
against a remote JWKS. So `vendor/` has to arrive with it: a release covering
only `app/` and `public/` installs code that calls a class the server does not
have, and Trusted Publishing answers `Class "Firebase\JWT\JWT" not found`.
Either apply a release scoped `app,public,vendor`, or run `composer install
--no-dev` on the server yourself.

Then run `php spark migrate --all` from `src/` — one new table for trusted
publishers. Nothing publishes until you create a token or add a publisher.

## [1.5.0] - 2026-08-07

### Added

- **Download counts, per release and per project.** Shown on the project page
  next to each version, with the project total in the Releases header, and as
  a column plus a headline figure on the dashboard.

  Counted per release; a project's figure is the sum of its releases rather
  than a second stored counter, so the two can never drift apart when a
  release is deleted.

  Only `release.zip` counts. `manifest.json` is fetched to decide whether to
  update at all, so counting it would report checks as installs. Requests
  refused for a missing token, or for a version that doesn't exist, count for
  nothing.

  The figures are not exposed publicly: neither the landing page nor
  `latest.json` carries them.

### Upgrading

Deploy as usual, then run `php spark migrate --all` from `src/` — one new
column on `releases`, starting at zero. Downloads served before this release
were never recorded and cannot be recovered.

## [1.4.1] - 2026-08-07

### Added

- A footer carrying the copyright and a link back to the project on GitHub, on
  the admin pages and on the public landing page — the two share no layout, so
  both were done.

  Deliberately without a version number. The admin layout also renders the
  login screen, and the landing page is public: announcing the running version
  to anyone who reaches either saves an attacker the trouble of finding out. It
  stays where it belongs, on the authenticated update panel.

## [1.4.0] - 2026-07-31

### Added

- **`GET /api/{slug}/latest.json?from={version}`** now reports what installing
  the latest release directly would step over: `skipped_versions`, and
  `missed_roots` — the directories a skipped release covered that the latest
  one does not, so nothing will bring them up to date.

  Only this server can work that out; an app sees one release at a time.
  `ci4-updater` 2.8 sends the parameter on its own and shows the warning in its
  update panel.

  Without the parameter the response keeps exactly the keys it always had, so
  nothing already deployed changes.

- **Required steps.** A checkbox on each release, and an action on the project
  page. A required release is served *instead of* the newest one until it has
  been applied, so an app updating from further back walks through it rather
  than jumping over it. It advances one step per check, which keeps every
  response a single well-formed release instead of a plan the app would have
  to execute.

  Use it when a release must not be skipped: it ships a directory later ones
  don't, or a migration a later one depends on.

- **The project page suggests it.** A release covering a directory the newest
  one does not gets a *Skippable* badge, and uploading or promoting a release
  says so outright — that is the moment an older release becomes skippable.
  It only ever suggests: whether it matters is a judgement about the
  application, not something this server can settle.

### Notes

- Serving all of this needs nothing from `forgelab-me/ci4-updater`: the
  requirement stays `^2.7`. It is the *receiving* side that reads the new
  fields, so this server's own update panel starts showing them once it moves
  to 2.8 — the apps it serves get them as soon as they do.

### Upgrading

Deploy as usual, then run `php spark migrate --all` from `src/` — one new
column on `releases`, defaulting to off. No release becomes a required step
until you tick it.

## [1.3.0] - 2026-07-31

Brings in ci4-updater 2.6 → 2.7, where a release started declaring the
directories it covers and `vendor/` became something a release can ship.

### Added

- **Releases record the directories they cover**, read from the manifest on
  upload and shown in a *Covers* column on the project page. Worth seeing
  before promoting one: a release that ships `vendor/` is an order of
  magnitude larger, and any app that hasn't listed `vendor` in
  `Config\Updater::$allowedRoots` refuses it outright.

  Releases uploaded earlier, and any archive built before ci4-updater 2.6,
  show `—`. They keep working exactly as they did.

### Fixed

- **The stored `manifest.json` is now the archive's bytes rather than a
  re-encoding of them.** A release signature covers the exact bytes of the
  manifest, and re-serialising changed spacing, key order and slash escaping
  enough to break the match. It affected only the `manifest_url` fallback —
  apps read the manifest from inside the ZIP, which was untouched — but there
  was no reason to serve a copy that differs from the original.

### Notes

- This server records a release's scope; it never judges it. Nothing here
  needs configuring to host a release that ships `vendor/`. Whether such a
  release is accepted is decided by each installation's
  `Config\Updater::$allowedRoots`.
- This server's **own** updates stay `app` + `public`, the default. Its
  `vendor/` is managed by Composer in a source checkout, and by the image in a
  container.
- Requires `forgelab-me/ci4-updater` ^2.7.

### Upgrading

Deploy as usual, then run `php spark migrate --all` from `src/` — one new
column on `releases`. Nothing else changes.

## [1.2.0] - 2026-07-30

### Added

- **Official container image**, published to
  `ghcr.io/forgelab-me/ci4-update-server` for amd64 and arm64 on every tag.
  `compose.yaml` and `.env.example` give a working stack — server plus
  MariaDB — in three commands.
- The container waits for the database, runs migrations and creates or
  re-syncs the admin account on every start. All idempotent, so restarts and
  redeploys are safe.
- `SELF_UPDATE` and `RUN_MIGRATIONS` environment switches.
- `ADMIN_PASSWORD`: the container hashes it at startup. Compose interpolates
  `$` in env files, so pasting a bcrypt hash there silently loses characters and
  produces an account that accepts no password; supplying the password avoids
  the trap entirely. `ADMIN_PASSWORD_HASH` still works and now takes precedence,
  and a hash that doesn't parse is refused rather than seeded.

### Changed

- **The application moved into `src/`.** The repository root now holds only
  packaging and documentation — `Dockerfile`, `compose.yaml`, `docker/`,
  `docs/`, `README`, `CHANGELOG`. This is a repository layout change with no
  effect on a running instance: releases still ship `app/` and `public/` at the
  archive root, so self-update and rollback are untouched, and the container is
  byte-for-byte the same tree at `/var/www/html`. Only a **source checkout** is
  affected — see *Upgrading* below.
- In-place self-update is **off in the container** (`SELF_UPDATE=false`). It
  writes into `app/` and `public/`, which a container discards when it is
  recreated — an update would report success and then vanish. The panel
  explains that the image is the unit of deployment instead. Outside a
  container nothing changes.

### Notes for container deployments

- Pass configuration with CodeIgniter's **underscore** names (`app_baseURL`,
  `database_default_hostname`), not the dotted ones. The container shell is
  dash, which drops environment variables whose names aren't valid shell
  identifiers, so the dotted form never reaches PHP or Apache.
- There are two environment files and they never collide: `/.env` configures
  Compose and the container, `/src/.env` configures CodeIgniter.
- See [docs/docker.md](docs/docker.md) for the full guide.
- `/var/www/html/writable` must be a volume: it holds the release archives
  served to every connected app. Back it up with the database.

### Upgrading a source checkout

Nothing to do on a container deployment, and nothing to do on an instance that
updates itself through the panel. On a checkout that tracks this repository:

- `git pull` moves the application into `src/`. Your `.env`, `vendor/` and
  `writable/` are untracked, so git leaves them where they are — move them
  yourself: `mv .env vendor writable src/`. `writable/` in particular holds the
  uploaded releases, so move it rather than letting it be recreated empty.
- Point the document root at `src/public`, and run `composer`, `spark` and
  `phpunit` from `src/`.

## [1.1.0] - 2026-07-30

Brings in ci4-updater 2.2 → 2.5. Most of this is visible in the **Updates**
panel of this server, which self-updates like any other app.

### Added

- **Rollback.** The panel now lists the backups taken before each update and
  restores any of them in one click: files are put back as they were and files
  the update added are removed. Backups whose update shipped migrations are
  flagged, because a restore reverts code and never the database.
- **Optional signed releases.** Set `Config\Updater::$publicKeys` to refuse any
  release that isn't signed by a key you trust. Nothing to do on the serving
  side: a signature travels inside the release archive, so projects hosted here
  can be signed without any change to this server.
- **Backup retention.** `Config\Updater::$keepBackups` (default 5) caps how
  many backups are kept; the oldest are pruned after an update is applied.
  Set it to `0` to keep everything.

### Changed

- The update panel is rendered from the package rather than from a copy in
  `app/Views`, so its improvements now arrive with `composer update`. The
  layout it extends and the name it displays come from
  `Config\Updater::$layout` and `$appName`.
- Requires `forgelab-me/ci4-updater` ^2.5.

### Upgrading

Deploy as usual. Two things worth knowing:

- **`app/Views/admin/updates.php` is gone from this release.** If your
  deployment had customised that file, the update removes your version and the
  packaged panel takes over. Run `php spark updater:setup --views` afterwards to
  get an editable copy back, or set `$layout` / `$appName` and use the packaged
  one.
- **Old backups are now pruned** once an update is applied — the five most
  recent are kept. Set `$keepBackups = 0` before updating if you would rather
  keep them all.

## [1.0.1] - 2026-07-30

### Security

- **Release upload could write outside `writable/uploads`.** The version string
  is read from `manifest.json` *inside* the uploaded archive and used as a
  directory name; it was used as-is, so a crafted archive declaring
  `"version": "../../public"` escaped the uploads directory. Versions are now
  restricted to letters, digits, dots, hyphens and underscores (20 characters
  max) and anything else is refused. Exploiting it required an authenticated
  admin uploading a hostile archive — the realistic case being a release built
  by someone else.
- **The login form had no rate limiting.** It now runs behind Shield's
  `auth-rates` filter, which caps attempts per IP and answers `429` beyond that.
- **Security response headers were disabled.** The `secureheaders` filter is
  now active: `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`
  and `X-Permitted-Cross-Domain-Policies`.
- **Password changes bypassed Shield's rules.** The profile form validated a
  bare 8-character minimum; it now uses Shield's `strong_password`, which also
  rejects compromised, dictionary and personal-information passwords.
- Requires `forgelab-me/ci4-updater` ^2.1, which validates the file paths in a
  release manifest before applying an update.

### Changed

- The settings form only persists known keys instead of storing every field it
  receives in the POST body.
- Uploaded archives are capped at 256 MB by the application, rather than
  relying solely on `upload_max_filesize`.
- `.env.example` documents `app.forceGlobalSecureRequests` and why HTTPS is not
  optional in production: every app pointed at this server treats its responses
  as trusted code.

### Upgrading

Nothing to do beyond deploying: no migration, no new configuration. Existing
release directories are untouched — the version validation only applies to new
uploads.

## [1.0.0] - 2026-07-29

Initial release.

### Added

- Multi-project update server: each project has its own slug, optional bearer
  token and release history, serving the `latest.json` feed that
  [`ci4-updater`](https://github.com/forgelab-me/ci4-updater) expects.
- Release upload through the admin panel. `manifest.json` is read from inside
  the ZIP — including archives wrapped in a single root folder, as GitHub
  generates — and the version and file hashes are taken from it.
- Public API: `GET /api/{slug}/latest.json`, plus `release.zip` and
  `manifest.json` downloads restricted to those two filenames and streamed
  through the application, so `writable/uploads` is never web-accessible.
  Tokens are compared with `hash_equals()`.
- Admin panel authenticated with [Shield](https://github.com/codeigniter4/shield):
  dashboard, project management, per-project token generation, settings.
- Setup through CodeIgniter's own CLI — `php spark migrate --all` then
  `php spark db:seed AdminUserSeeder`, the seeder being idempotent so it doubles
  as the recovery path for a locked-out admin account.
- The server updates itself with `ci4-updater`, storing its updater settings in
  its own `app_settings` table rather than a separate JSON file.
- Test suite and a GitHub Actions workflow across PHP 8.2 to 8.5. Dependencies
  are locked against PHP 8.2, the declared minimum.
