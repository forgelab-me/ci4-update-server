# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

Entries are written for whoever deploys this server: what changed, and what
you have to do when upgrading. When cutting a release, copy the section for
that version into the *Changelog* field of the upload form — that text is what
instances see in their update panel.

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
