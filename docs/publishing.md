# Publishing from CI

Two ways to let a pipeline upload a release, both ending at the same place: a
token scoped `publish:{slug}` that may upload to one project and nothing else.

**Publishing through the API never promotes a release to latest**, whatever the
caller sends. Promotion stays a click on the project page, so a stolen
credential can only add a release nobody is served.

## Where the signing key lives decides everything

If you sign releases, this is the question that matters more than which
credential you choose.

Sign on your own machine and have CI upload an already-signed archive, and a
stolen publish token can only produce an *unsigned* release — refused by every
app with `Config\Updater::$publicKeys` set. The credential is then not the keys
to the kingdom.

Sign **inside** CI and the private key becomes a repository secret. Trusted
Publishing does not protect it: it secures the upload credential, not the
signing key. What does help is pairing it with a **protected environment** —
the signing secret is only exposed after a human approves the run, and the
server verifies the token really came from that environment.

## Option A — a publish token

Create one on the project page, under *Publishing from CI*. It is shown once;
only a SHA-256 is stored.

```yaml
- name: Publish
  run: |
    curl --fail-with-body \
      -H "Authorization: Bearer ${{ secrets.UPDATE_SERVER_TOKEN }}" \
      -F "zip_file=@release.zip" \
      -F "changelog=$(git tag -l --format='%(contents)' ${{ github.ref_name }})" \
      https://updates.example.com/api/my-app/releases
```

Simple, works from anywhere, and the only long-lived secret in the pipeline.
Give it an expiry — a token nobody remembers is a token nobody revokes.

## Option B — Trusted Publishing

No stored secret at all. GitHub mints a short-lived identity token for the
workflow; the server checks it and hands back a 15-minute publish token.

On the project page, add a trusted publisher:

| Field | Value |
|---|---|
| Repository | `forgelab-me/my-app` |
| Owner id | the **numeric** account id — `https://api.github.com/users/forgelab-me` |
| Environment | optional, but see above |

The owner id is what the match is really pinned on. A repository name can be
renamed, or freed and claimed by someone else; a numeric account id is never
reassigned. Pinning on the name alone would mean losing the name loses the
project.

```yaml
permissions:
  id-token: write        # required to mint the identity token
  contents: read

jobs:
  publish:
    runs-on: ubuntu-latest
    environment: release # matches the trusted publisher, and gates the secrets
    steps:
      - uses: actions/checkout@v5

      - name: Build the release
        run: |
          composer install --no-dev --optimize-autoloader
          php spark update:manifest --sign "$SIGNING_KEY_PATH"

      - name: Exchange the GitHub identity for a publish token
        id: auth
        run: |
          # The audience is your server's URL. Requesting the default would let
          # a token minted for another service be replayed here.
          OIDC=$(curl -sS -H "Authorization: bearer $ACTIONS_ID_TOKEN_REQUEST_TOKEN" \
            "$ACTIONS_ID_TOKEN_REQUEST_URL&audience=https://updates.example.com" | jq -r .value)

          TOKEN=$(curl -sS --fail-with-body -X POST \
            -H "Authorization: Bearer $OIDC" \
            https://updates.example.com/api/my-app/publish/token | jq -r .token)

          echo "::add-mask::$TOKEN"
          echo "token=$TOKEN" >> "$GITHUB_OUTPUT"

      - name: Upload
        run: |
          curl --fail-with-body \
            -H "Authorization: Bearer ${{ steps.auth.outputs.token }}" \
            -F "zip_file=@release_*.zip" \
            https://updates.example.com/api/my-app/releases
```

## In this repository

[`.github/workflows/publish.yml`](../.github/workflows/publish.yml) does the
above for this server's own releases, on a `v*` tag. Two things it works out on
its own:

- **What the release covers.** `app,public` normally; `app,public,vendor` when
  `composer.lock` moved since the previous tag, since that is the only time the
  dependencies need shipping.
- **Whether it is a required step.** Yes whenever `vendor/` ships — skip such a
  release and the dependency update it carries is never applied, because the
  next one only touches `app/` and `public/`.

Both can be overridden by running the workflow by hand. It also refuses to
publish when the tag disagrees with `Config\Updater::VERSION`, which is what the
manifest is built from.

It runs in a protected environment named `release`, and the trusted publisher
requires that same environment. The signing key and its passphrase are held as
environment secrets, so the approval that gates the environment gates the key
too — which is the point, since the key lives in CI here.

## The endpoints

```
POST /api/{slug}/publish/token     Authorization: Bearer <GitHub OIDC token>
  → { "token": "...", "expires_in": 900, "scope": "publish:{slug}" }

POST /api/{slug}/releases          Authorization: Bearer <publish token>
  multipart: zip_file, changelog, released_at, required
  → 201 { "version": "...", "release_id": 12, "is_latest": false }
```

`401` means the credential was refused, `403` that it is not scoped to this
project or no trusted publisher matched, `422` that the archive was rejected —
the body says which.

## When each is worth it

A single maintainer with a couple of repositories: option A, plus local signing
and manual promotion, is already a sound posture.

Trusted Publishing earns its cost when several repositories publish, when the
credential would be shared with machines you do not control, when you want to
know which workflow published what — or when the signing key is in CI and you
want a protected environment standing in front of it.

## What refusing looks like

- **A version that already exists is refused.** Published bytes are immutable;
  an app that already downloaded a version must never get different files under
  the same name. Publish a new version instead.
- The archive still has to carry a `manifest.json` with a valid `version`, and
  the same 256 MB cap applies as through the panel.
