<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0 fw-bold">Dashboard</h1>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= site_url('projects/new') ?>" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>New Project
        </a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center py-3">
            <div class="display-5 fw-bold text-primary"><?= $projectCount ?></div>
            <div class="text-muted small mt-1"><i class="bi bi-folder2-open me-1"></i>Projects</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-3">
            <div class="display-5 fw-bold text-success"><?= $releaseCount ?></div>
            <div class="text-muted small mt-1"><i class="bi bi-tag me-1"></i>Releases</div>
        </div>
    </div>
</div>

<?php if (empty($projects)): ?>
    <div class="card">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-inbox display-4 d-block mb-3"></i>
            No projects yet. <a href="<?= site_url('projects/new') ?>">Create your first one.</a>
        </div>
    </div>
<?php else: ?>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Slug</th>
                    <th class="text-center">Releases</th>
                    <th>Latest Version</th>
                    <th class="text-center">Access</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($projects as $p): ?>
                <tr>
                    <td class="fw-semibold"><?= esc($p['name']) ?></td>
                    <td><code><?= esc($p['slug']) ?></code></td>
                    <td class="text-center">
                        <span class="badge bg-secondary"><?= (int) $p['releases_count'] ?></span>
                    </td>
                    <td>
                        <?php if ($p['latest_version']): ?>
                            <span class="badge bg-success"><i class="bi bi-check me-1"></i><?= esc($p['latest_version']) ?></span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($p['token']): ?>
                            <span class="badge bg-warning text-dark" title="Token required">
                                <i class="bi bi-lock-fill me-1"></i>Protected
                            </span>
                        <?php else: ?>
                            <span class="badge bg-secondary" title="No token required">
                                <i class="bi bi-unlock me-1"></i>Public
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <a href="<?= site_url('projects/' . $p['id']) ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-pencil me-1"></i>Manage
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?= $this->endSection() ?>


