# Tests

```bash
composer install
composer test
```

No database server is needed: the `tests` connection group in
**app/Config/Database.php** uses SQLite `:memory:`, and each test class
migrates a fresh schema. A `.env` isn't needed either — the suite runs the
same way in CI as it does locally.

## What's covered

| File | Scope |
|---|---|
| `Api/ApiLatestTest.php` | `GET /api/{slug}/latest.json` — response shape, latest-release selection, 404s, token enforcement |
| `Api/ApiFileTest.php` | `GET /api/{slug}/files/{version}/{file}` — filename whitelist, tokens, missing file/version, download response |
| `Models/ReleaseModelTest.php` | `setLatest()` (single latest, project isolation), version lookups, ordering |
| `Models/AppSettingModelTest.php` | Settings read/write, and that the class still satisfies ci4-updater's `SettingsInterface` |
| `Support/ZipManifestTest.php` | Reading `manifest.json` out of an uploaded ZIP, including GitHub-style single-root archives |
| `database/AdminUserSeederTest.php` | `AdminUserSeeder` — creation, username derivation, idempotent re-run, missing env |
| `unit/HealthTest.php` | Framework smoke test (paths defined, `baseURL` valid) |

## Running a subset

```bash
vendor/bin/phpunit --no-coverage tests/Api
vendor/bin/phpunit --no-coverage tests/Api/ApiLatestTest.php
vendor/bin/phpunit --no-coverage --filter testSetLatestDoesNotTouchOtherProjects
```

## Code coverage

`composer test` passes `--no-coverage` on purpose: **phpunit.dist.xml**
declares coverage reports and sets `failOnWarning="true"`, so without a
coverage driver installed PHPUnit warns and the run exits non-zero even when
every test passes.

With [Xdebug](https://xdebug.org/docs/install) (`xdebug.mode=coverage`) or
PCOV installed, use:

```bash
composer test:coverage
```

Reports land in **build/logs/** (git-ignored).

## Writing new tests

Test classes live in **tests/** in the global namespace and extend
`CodeIgniter\Test\CIUnitTestCase`. Method names must start with `test`.

For anything touching the database, add `DatabaseTestTrait` **and** set the
namespace to migrate — the trait defaults to `Tests\Support`, which does not
contain this app's tables:

```php
use CodeIgniter\Test\DatabaseTestTrait;

protected $namespace = 'App';   // projects, releases, app_settings
// protected $namespace;        // null = every namespace, incl. Shield's auth tables
```

Add `FeatureTestTrait` to exercise routes end to end (`$this->get(...)`,
`$this->withHeaders([...])->get(...)`).

## Resources

- [CodeIgniter 4 testing guide](https://codeigniter.com/user_guide/testing/index.html)
- [Testing your database](https://codeigniter.com/user_guide/testing/database.html)
- [PHPUnit documentation](https://docs.phpunit.de/)
