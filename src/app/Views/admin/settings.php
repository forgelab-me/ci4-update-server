<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="d-flex align-items-center mb-4">
    <a href="<?= site_url('dashboard') ?>" class="btn btn-sm btn-outline-secondary me-3">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h1 class="h4 mb-0 fw-bold"><i class="bi bi-gear me-2"></i>Settings</h1>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header px-4 py-3 fw-semibold">All settings</div>
            <form method="post" action="<?= site_url('admin/settings') ?>">
                <?= csrf_field() ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th style="width:40%">Key</th>
                                <th>Value</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($settings as $key => $value): ?>
                            <?php $isSecret = in_array($key, $passwordFields ?? [], true); ?>
                            <tr>
                                <td>
                                    <code class="text-warning small"><?= esc($key) ?></code>
                                </td>
                                <td>
                                    <input type="<?= $isSecret ? 'password' : 'text' ?>"
                                           name="<?= esc($key) ?>"
                                           value="<?= $isSecret ? '' : esc($value) ?>"
                                           placeholder="<?= $isSecret && $value !== '' ? '••••••••' : '' ?>"
                                           autocomplete="<?= $isSecret ? 'new-password' : 'off' ?>"
                                           class="form-control form-control-sm">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($settings)): ?>
                            <tr><td colspan="2" class="text-center text-secondary py-3">No settings yet</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (! empty($settings)): ?>
                <div class="card-footer px-4 py-3">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-floppy me-1"></i>Save
                    </button>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header px-4 py-3 fw-semibold">
                <i class="bi bi-info-circle me-1"></i>Key reference
            </div>
            <div class="card-body p-4 small text-secondary">
                <dl class="mb-0">
                    <dt class="text-warning">update_server_url</dt>
                    <dd>
                        URL of this project's <code>latest.json</code> endpoint on an update server.<br>
                        <code class="text-secondary" style="font-size:.8em">https://updates.example.com/api/my-project/</code>
                    </dd>
                    <dt class="text-warning mt-2">update_server_token</dt>
                    <dd>
                        Optional bearer token, if the project is protected.<br>
                        Leave empty to keep the current value.
                    </dd>
                </dl>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header px-4 py-3 fw-semibold">
                <i class="bi bi-arrow-repeat me-1"></i>Quick access
            </div>
            <div class="card-body p-4">
                <a href="<?= site_url('admin/updates') ?>" class="btn btn-outline-secondary btn-sm w-100">
                    <i class="bi bi-arrow-repeat me-1"></i>Go to Updates
                </a>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>


