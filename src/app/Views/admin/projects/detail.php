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
                <span class="badge bg-secondary"><?= count($releases) ?></span>
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
                                <?php else: ?>
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


