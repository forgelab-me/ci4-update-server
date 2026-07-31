<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-arrow-repeat text-warning fs-4"></i>
    <h1 class="h4 mb-0 fw-bold">Updates</h1>
</div>

<div class="alert alert-info d-flex gap-3 align-items-start">
    <i class="bi bi-box-seam fs-4 flex-shrink-0"></i>
    <div>
        <strong>This instance updates by pulling a new image.</strong>
        <p class="mb-2 mt-1">
            In-place updates are turned off (<code>SELF_UPDATE=false</code>): they would
            write into the container filesystem, which is discarded the next time the
            container is recreated — so an update would appear to succeed and then
            silently disappear.
        </p>
        <pre class="mb-0 p-2 rounded small" style="background:rgba(0,0,0,.3)">docker compose pull
docker compose up -d</pre>
    </div>
</div>

<div class="card">
    <div class="card-header px-4 py-3 fw-semibold"><i class="bi bi-info-circle me-2"></i>System info</div>
    <div class="card-body">
        <table class="table table-sm mb-0">
            <tbody>
                <tr>
                    <td class="text-secondary" style="width:45%">Update Server</td>
                    <td>
                        <span class="badge bg-warning text-dark">v<?= esc($appVersion) ?></span>
                        <small class="text-secondary ms-1"><?= esc($appDate) ?></small>
                    </td>
                </tr>
                <tr><td class="text-secondary">PHP</td><td><?= esc($phpVersion) ?></td></tr>
                <tr><td class="text-secondary">CodeIgniter</td><td><?= esc($ciVersion) ?></td></tr>
                <tr><td class="text-secondary">Database</td><td><?= esc($dbVersion) ?></td></tr>
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-secondary">
        Migrations run automatically when the container starts.
    </div>
</div>

<?= $this->endSection() ?>
