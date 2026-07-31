<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="row justify-content-center" style="margin-top: 8vh">
    <div class="col-sm-10 col-md-6 col-lg-4">
        <div class="text-center mb-4">
            <i class="bi bi-cloud-upload-fill text-primary" style="font-size: 2.5rem"></i>
            <h1 class="h4 mt-2 fw-bold">Update Server</h1>
            <p class="text-muted small">Dashboard</p>
        </div>
        <div class="card">
            <div class="card-body p-4">
                <form method="POST" action="<?= site_url('auth/login') ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" id="email" name="email" class="form-control"
                            autofocus required autocomplete="username">
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" id="password" name="password" class="form-control"
                            required autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-box-arrow-in-right me-1"></i>Sign in
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?= $this->endSection() ?>


