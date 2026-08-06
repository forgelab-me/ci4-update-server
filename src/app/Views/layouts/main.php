<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <?php helper('auth'); ?>
    <?php $uri = service('uri'); $isAdminPage = $uri->getSegment(1) === 'admin'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($title ?? 'Update Server') ?> — Update Server</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <?= $this->renderSection('head') ?>
    <style>
        body { background-color: #0d1117; min-height: 100vh; }
        .navbar { border-bottom: 1px solid #30363d; }
        .card { border-color: #30363d; }
        .card-header { border-color: #30363d; background-color: rgba(255,255,255,.03); }
        .card-footer { border-color: #30363d; background-color: rgba(255,255,255,.02); }
        .table { --bs-table-bg: transparent; }
        .table > thead > tr > th { border-color: #30363d; color: #8b949e; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; }
        .table > tbody > tr > td { border-color: #21262d; vertical-align: middle; }
        .navbar-brand { font-weight: 700; letter-spacing: .3px; }
        code { color: #79c0ff; }
        .font-monospace { font-family: ui-monospace, "SFMono-Regular", monospace; font-size: .875em; }
    </style>
</head>
<body>

<?php if (auth()->loggedIn()): ?>
<nav class="navbar bg-dark mb-4">
    <div class="container d-flex justify-content-between align-items-center">
        <div class="d-flex gap-2 align-items-center">
            <a class="navbar-brand text-white" href="<?= site_url('admin') ?>">
                <i class="bi bi-cloud-upload-fill text-primary me-1"></i>Update Server
            </a>
            <a href="<?= site_url('dashboard') ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-speedometer2 me-1"></i>Dashboard
            </a>
            <a href="<?= site_url('admin/settings') ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-gear me-1"></i>Settings
            </a>
            <a href="<?= site_url('admin/updates') ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-repeat me-1"></i>Updates
            </a>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <a href="<?= site_url('admin/profile') ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-person me-1"></i><?= esc(auth()->user()->username) ?>
            </a>
            <a href="<?= site_url('auth/logout') ?>" class="btn btn-sm btn-outline-secondary" title="Logout">
                <i class="bi bi-box-arrow-right"></i>
            </a>
        </div>
    </div>
</nav>
<?php endif; ?>

<main class="container pb-5">
    <?php if ($flash = session()->getFlashdata('success')): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i><?= esc($flash) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($flash = session()->getFlashdata('warning')): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= esc($flash) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($flash = session()->getFlashdata('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= esc($flash) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?= $this->renderSection('content') ?>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?= $this->renderSection('scripts') ?>
</body>
</html>

