# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

Entries are written for whoever deploys this server: what changed, and what
you have to do when upgrading. When cutting a release, copy the section for
that version into the *Changelog* field of the upload form — that text is what
instances see in their update panel.

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

Deploy as usual, then run `php spark migrate --all` from `src/` — one new table
for trusted publishers. Nothing publishes until you create a token or add a
publisher. Requires `firebase/php-jwt`, added as a dependency: Shield's own JWT
addon signs for *your* users with *your* keys and cannot verify a third party's
token against a remote JWKS.

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
