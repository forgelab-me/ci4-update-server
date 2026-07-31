# Running with Docker

```bash
curl -O https://raw.githubusercontent.com/forgelab-me/ci4-update-server/main/compose.yaml
curl -o .env https://raw.githubusercontent.com/forgelab-me/ci4-update-server/main/.env.example

# edit .env — at minimum app_baseURL, database_default_password,
# ADMIN_EMAIL and ADMIN_PASSWORD

docker compose up -d
```

The stack is the server plus MariaDB. On every start the container waits for
the database, applies pending migrations, and creates or re-syncs the admin
account. All of it is idempotent, so restarting and redeploying are safe.

Images: `ghcr.io/forgelab-me/ci4-update-server`, tagged `1.2.3`, `1.2`, `1` and
`latest`, built for `linux/amd64` and `linux/arm64`. Pin at whichever level of
churn you want to follow.

## Configuration

No `.env` is copied into the image — CodeIgniter reads real environment
variables, and that is what Compose passes.

| Variable | Purpose |
|---|---|
| `app_baseURL` | Public URL, trailing slash included. Apps are told where to fetch releases using this, so it must be how they actually reach the server. |
| `app_forceGlobalSecureRequests` | Redirect everything to HTTPS. Leave on unless a proxy already does it. |
| `database_default_database` / `_username` / `_password` | Database credentials. Host, driver and port are set by `compose.yaml`. |
| `ADMIN_EMAIL` | First admin account. |
| `ADMIN_PASSWORD` | Its password, hashed by the container at startup. |
| `ADMIN_PASSWORD_HASH` | A bcrypt hash, if you prefer not to store the plaintext. Takes precedence over `ADMIN_PASSWORD`. |
| `SELF_UPDATE` | `false` in the image — see [Updating](#updating). |
| `RUN_MIGRATIONS` | Set to `false` to skip migrations at startup. |
| `APP_PORT` | Host port. Behind a proxy, `127.0.0.1:8080` keeps it off the public interface. |

### Three traps worth knowing about

These each cost real debugging time, so they are worth stating plainly.

**Use the underscore spelling.** `app_baseURL`, not `app.baseURL`. The
container's shell is dash, which discards environment variables whose names
aren't valid shell identifiers — dots included. With the dotted form the
settings never reach PHP or Apache, and the app fails as though nothing was
configured.

**Two `.env` files, and that is deliberate.** `/.env` at the repository root
configures Compose and the container; `/src/.env` configures CodeIgniter in a
source checkout. They sit in different directories precisely so neither can
overwrite the other — which is why the application lives under `src/`.

**Beware `$` in a bcrypt hash.** Compose interpolates `$` in env files, so
pasting a hash silently drops part of it and produces an account that accepts
no password — with no error anywhere. Either use `ADMIN_PASSWORD` and let the
container hash it, or double every `$`:

```bash
php -r "echo str_replace('\$', '\$\$', password_hash('yourpassword', PASSWORD_DEFAULT)) . PHP_EOL;"
```

The entrypoint rejects a hash that doesn't parse rather than seeding an
unusable account.

## Updating

The image is the unit of deployment, so in-place self-update is off
(`SELF_UPDATE=false`). Applying an update would write into the container
filesystem, which is discarded when the container is recreated: the update
would report success and then vanish. The panel says so instead of offering a
button that quietly does nothing.

```bash
docker compose pull
docker compose up -d
```

Migrations run automatically on the new container. Pin a tag (`:1.2`) if you
would rather decide when a major version lands.

## Data and backups

`/var/www/html/writable` holds the release archives served to every connected
app. **Losing that volume loses every release of every project** — back it up
alongside the database.

```bash
# Releases
docker run --rm -v ci4-update-server_updates_data:/data -v "$PWD:/backup" \
  alpine tar czf /backup/updates-data.tar.gz -C /data .

# Database
docker compose exec db \
  mariadb-dump -uupdates -p"$database_default_password" updates > updates-db.sql
```

## Behind a reverse proxy

Publish on loopback (`APP_PORT=127.0.0.1:8080`), terminate TLS at the proxy,
and forward the usual headers. Keep `app_baseURL` set to the public HTTPS URL:
it is what ends up in the `zip_url` and `manifest_url` handed to client apps,
so getting it wrong sends them to an address they can't reach.

## Building locally

```bash
docker compose up -d --build
```

A `compose.override.yaml` with `build: .` is enough to build from source
instead of pulling; Compose picks it up automatically.

## Health

The image declares a healthcheck, so `docker compose ps` reports `healthy`
once the app answers. Startup logs are prefixed `[entrypoint]`:

```bash
docker compose logs app | grep entrypoint
```
