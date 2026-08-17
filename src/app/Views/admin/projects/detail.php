<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="d-flex align-items-center mb-4">
    <a href="<?= site_url('dashboard') ?>" class="btn btn-outline-secondary btn-sm me-3">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h1 class="h3 mb-0 fw-bold"><?= esc($project['name']) ?></h1>
    <?php if ($project['token']): ?>
        <span class="badge bg-warning text-dark ms-3"><i class="bi bi-lock-fill me-1"></i>Protected</span>
    <?php else: ?>
        <span class="badge bg-secondary ms-3"><i class="bi bi-unlock me-1"></i>Public</span>
    <?php endif; ?>
</div>

<div class="row g-4">

    <?php /* Project settings */ ?>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header">
                <i class="bi bi-gear-fill me-2"></i>Project Settings
            </div>
            <div class="card-body">
                <form method="POST" action="<?= site_url("projects/{$project['id']}/update") ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control"
                            value="<?= esc($project['name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Slug</label>
                        <input type="text" name="slug" class="form-control font-monospace"
                            value="<?= esc($project['slug']) ?>"
                            pattern="[a-z0-9\-]+" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">
                            API Token
                            <span class="text-muted fw-normal small">— empty = public</span>
                        </label>
                        <div class="input-group">
                            <input type="text" id="tokenInput" name="token"
                                class="form-control font-monospace"
                                value="<?= esc($project['token'] ?? '') ?>"
                                autocomplete="off">
                            <button type="button" class="btn btn-outline-secondary" id="genToken">
                                <i class="bi bi-arrow-clockwise"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-floppy-fill me-1"></i>Save Changes
                    </button>
                </form>
            </div>
            <div class="card-footer">
                <form method="POST"
                    action="<?= site_url("projects/{$project['id']}/delete") ?>"
                    onsubmit="return confirm('Delete «<?= esc($project['name'], 'js') ?>» and ALL its releases?\n\nThis cannot be undone.')">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-trash3 me-1"></i>Delete Project
                    </button>
                </form>
            </div>
        </div>
    </div>

    <?php /* Upload form */ ?>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-cloud-upload-fill me-2"></i>Upload New Release
            </div>
            <div class="card-body">
                <form method="POST"
                    action="<?= site_url("projects/{$project['id']}/releases") ?>"
                    enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Release Date</label>
                            <input type="date" name="released_at" class="form-control"
                                value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Changelog</label>
                            <textarea name="changelog" class="form-control" rows="3"
                                placeholder="What's new in this release?"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">
                                ZIP Archive <span class="text-danger">*</span>
                            </label>
                            <input type="file" name="zip_file" class="form-control"
                                accept=".zip,application/zip" required>
                            <div class="form-text">
                                <i class="bi bi-info-circle me-1"></i>
                                The <code>manifest.json</code> is read automatically from inside the ZIP — version, covered directories and file hashes are extracted from it.
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                    name="set_latest" id="setLatest" value="1" checked>
                                <label class="form-check-label" for="setLatest">
                                    Set as latest immediately
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox"
                                    name="required" id="requiredStep" value="1">
                                <label class="form-check-label" for="requiredStep">
                                    Required step
                                </label>
                                <div class="form-text">
                                    Apps are served this release on its own before any newer one,
                                    instead of jumping straight to the latest. Use it when a release
                                    must not be skipped — it ships a directory later ones don't, or a
                                    migration a later one depends on. You'll be prompted after upload
                                    if the covered directories suggest it.
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-cloud-upload me-1"></i>Upload Release
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php /* Releases table */ ?>
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-tags-fill me-2"></i>Releases</span>
                <span class="d-flex align-items-center gap-2">
                    <?php $totalDl = array_sum(array_map(static fn ($r) => (int) ($r['downloads'] ?? 0), $releases)); ?>
                    <?php if ($totalDl > 0): ?>
                        <span class="badge bg-dark border border-secondary text-secondary"
                              title="Total across every version of this project">
                            <i class="bi bi-download me-1"></i><?= number_format($totalDl) ?>
                        </span>
                    <?php endif; ?>
                    <span class="badge bg-secondary"><?= count($releases) ?></span>
                </span>
            </div>
            <?php if (empty($releases)): ?>
                <div class="card-body text-center text-muted py-4">
                    <i class="bi bi-inbox me-2"></i>No releases yet — upload one above.
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Version</th>
                            <th>Covers</th>
                            <th class="text-end">Downloads</th>
                            <th>Date</th>
                            <th>Changelog</th>
                            <th class="text-center">Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($releases as $r): ?>
                        <tr>
                            <td><code><?= esc($r['version']) ?></code></td>
                            <td class="small">
                                <?php if (! empty($r['roots'])): ?>
                                    <?php // An app refuses a release covering a directory it hasn't
                                          // listed in Config\Updater::$allowedRoots, so it is worth
                                          // seeing before pointing an installation at it. ?>
                                    <span class="text-muted"><?= esc($r['roots']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted" title="Built before ci4-updater 2.6, which is when a release started declaring its scope.">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end font-monospace small">
                                <?php $dl = (int) ($r['downloads'] ?? 0); ?>
                                <?php if ($dl > 0): ?>
                                    <span class="text-light" title="release.zip served <?= $dl ?> time<?= $dl > 1 ? 's' : '' ?>"><?= number_format($dl) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">0</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= esc($r['released_at'] ?? '—') ?></td>
                            <td class="text-muted small"
                                style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                                title="<?= esc($r['changelog'] ?? '') ?>">
                                <?= esc($r['changelog'] ?? '—') ?>
                            </td>
                            <td class="text-center">
                                <?php if ($r['is_latest']): ?>
                                    <span class="badge bg-success">
                                        <i class="bi bi-star-fill me-1"></i>Latest
                                    </span>
                                <?php endif; ?>

                                <?php if (! empty($r['required'])): ?>
                                    <span class="badge bg-primary"
                                          title="Apps are served this release on its own, before anything newer.">
                                        <i class="bi bi-signpost-split me-1"></i>Required step
                                    </span>
                                <?php elseif (isset($unguarded[$r['version']])): ?>
                                    <?php // Suggested, not enforced: only you know whether skipping
                                          // this release actually matters for your app. ?>
                                    <span class="badge bg-warning text-dark"
                                          title="This release covers <?= esc(implode(', ', $unguarded[$r['version']])) ?>, which the latest one does not. An app updating from further back skips it, and nothing else will apply it.">
                                        <i class="bi bi-exclamation-triangle me-1"></i>Skippable
                                    </span>
                                <?php elseif (! $r['is_latest']): ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="d-flex gap-1 justify-content-end flex-nowrap">
                                    <?php if (! $r['is_latest']): ?>
                                    <form method="POST"
                                        action="<?= site_url("projects/{$project['id']}/releases/{$r['id']}/set-latest") ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit"
                                            class="btn btn-sm btn-outline-success"
                                            title="Set as latest">
                                            <i class="bi bi-star me-1"></i>Set Latest
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <form method="POST"
                                        action="<?= site_url("projects/{$project['id']}/releases/{$r['id']}/required") ?>">
                                        <?= csrf_field() ?>
                                        <button type="submit"
                                            class="btn btn-sm <?= empty($r['required']) ? 'btn-outline-primary' : 'btn-primary' ?>"
                                            title="<?= empty($r['required'])
                                                ? 'Serve this release on its own before anything newer'
                                                : 'Stop treating this release as a required step' ?>">
                                            <i class="bi bi-signpost-split me-1"></i><?= empty($r['required']) ? 'Require' : 'Unrequire' ?>
                                        </button>
                                    </form>
                                    <form method="POST"
                                        action="<?= site_url("projects/{$project['id']}/releases/{$r['id']}/delete") ?>"
                                        onsubmit="return confirm('Delete release <?= esc($r['version'], 'js') ?>? This cannot be undone.')">
                                        <?= csrf_field() ?>
                                        <button type="submit"
                                            class="btn btn-sm btn-outline-danger"
                                            title="Delete release">
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php /* Publishing from CI */ ?>
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-robot me-2"></i>Publishing from CI
            </div>
            <div class="card-body">

                <?php if ($raw = session()->getFlashdata('new_publish_token')): ?>
                    <div class="alert alert-success">
                        <div class="fw-semibold mb-1"><i class="bi bi-key me-1"></i>Copy this token now — it is never shown again.</div>
                        <code class="d-block p-2 rounded" style="background:rgba(0,0,0,.35);word-break:break-all"><?= esc($raw) ?></code>
                    </div>
                <?php endif; ?>

                <p class="text-muted small">
                    Both routes end at the same place: a token scoped
                    <code><?= esc($publishScope) ?></code> that may upload a release here.
                    Uploading never promotes to <em>latest</em> — that stays a click on this page.
                </p>

                <h6 class="mt-4">Tokens</h6>
                <?php if (empty($publishTokens)): ?>
                    <p class="text-muted small mb-3">None yet.</p>
                <?php else: ?>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Name</th><th>Expires</th><th>Last used</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($publishTokens as $tk): ?>
                                <tr>
                                    <td><?= esc($tk['name']) ?></td>
                                    <td class="small <?= $tk['expired'] ? 'text-danger' : 'text-muted' ?>">
                                        <?= $tk['expires'] ? esc($tk['expires']) . ($tk['expired'] ? ' (expired)' : '') : 'never' ?>
                                    </td>
                                    <td class="small text-muted"><?= esc($tk['last_used_at'] ?? 'never') ?></td>
                                    <td class="text-end">
                                        <form method="POST" action="<?= site_url('projects/' . $project['id'] . '/publish-tokens/' . $tk['id'] . '/revoke') ?>"
                                              onsubmit="return confirm('Revoke this token? Any pipeline using it stops publishing.')">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-sm btn-outline-danger">Revoke</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <form method="POST" action="<?= site_url('projects/' . $project['id'] . '/publish-tokens') ?>" class="row g-2 align-items-end mb-4">
                    <?= csrf_field() ?>
                    <div class="col-sm-5">
                        <label class="form-label small">Name</label>
                        <input name="name" class="form-control form-control-sm" placeholder="GitHub Actions">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small">Expires in (days, blank = never)</label>
                        <input name="expires_days" type="number" min="1" class="form-control form-control-sm" placeholder="90">
                    </div>
                    <div class="col-sm-3">
                        <button class="btn btn-sm btn-success w-100"><i class="bi bi-plus-lg me-1"></i>Create token</button>
                    </div>
                </form>

                <h6>Trusted publishers <span class="badge bg-secondary ms-1">no stored secret</span></h6>
                <p class="text-muted small">
                    A GitHub workflow proves who it is with the identity token GitHub mints for it, and gets a
                    15-minute token in exchange. It must request the audience
                    <code><?= esc($oidcAudience) ?></code>.
                </p>

                <?php if (! empty($publishers)): ?>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Repository</th><th>Owner id</th><th>Environment</th><th>Last used</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($publishers as $pb): ?>
                                <tr>
                                    <td class="font-monospace small"><?= esc($pb['repository']) ?></td>
                                    <td class="font-monospace small text-muted"><?= esc($pb['repository_owner_id']) ?></td>
                                    <td class="small">
                                        <?php if ($pb['environment']): ?>
                                            <?= esc($pb['environment']) ?>
                                        <?php else: ?>
                                            <span class="text-muted">any</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted"><?= esc($pb['last_used_at'] ?? 'never') ?></td>
                                    <td class="text-end">
                                        <form method="POST" action="<?= site_url('projects/' . $project['id'] . '/publishers/' . $pb['id'] . '/delete') ?>"
                                              onsubmit="return confirm('Remove this publisher?')">
                                            <?= csrf_field() ?>
                                            <button class="btn btn-sm btn-outline-danger">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <?php /* align-items-start, not -end: the hints below some inputs would otherwise push their column up. */ ?>
                <form method="POST" action="<?= site_url('projects/' . $project['id'] . '/publishers') ?>" class="row g-2 align-items-start">
                    <?= csrf_field() ?>
                    <div class="col-sm-4">
                        <label class="form-label small">Repository</label>
                        <input name="repository" class="form-control form-control-sm" placeholder="forgelab-me/my-app" required>
                        <div class="form-text" style="font-size:.75rem">owner/repo</div>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label small">Owner id</label>
                        <input name="repository_owner_id" class="form-control form-control-sm" placeholder="10387667" required>
                        <div class="form-text" style="font-size:.75rem">api.github.com/users/&lt;owner&gt;</div>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label small">Environment</label>
                        <input name="environment" class="form-control form-control-sm" placeholder="release">
                        <div class="form-text" style="font-size:.75rem">blank = any</div>
                    </div>
                    <div class="col-sm-2">
                        <label class="form-label small d-none d-sm-inline-block">&nbsp;</label>
                        <button class="btn btn-sm btn-success w-100">Trust</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php /* API info */ ?>
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <i class="bi bi-code-slash me-2"></i>API Endpoints
            </div>
            <div class="card-body">
                <div class="mb-2">
                    <span class="badge bg-primary me-2">GET</span>
                    <code class="user-select-all"><?= site_url("api/{$project['slug']}/latest.json") ?></code>
                </div>
                <div class="mb-2">
                    <span class="badge bg-secondary me-2">GET</span>
                    <code><?= site_url("api/{$project['slug']}/files/{version}/release.zip") ?></code>
                </div>
                <?php if ($project['token']): ?>
                <hr class="border-secondary">
                <div class="small text-muted mb-1">Required header:</div>
                <code class="user-select-all">Authorization: Bearer <?= esc($project['token']) ?></code>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<script>
document.getElementById('genToken').addEventListener('click', function () {
    const arr = new Uint8Array(32);
    window.crypto.getRandomValues(arr);
    document.getElementById('tokenInput').value =
        Array.from(arr).map(b => b.toString(16).padStart(2, '0')).join('');
});
</script>

<?= $this->endSection() ?>


