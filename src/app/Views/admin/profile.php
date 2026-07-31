<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">

        <div class="d-flex align-items-center mb-4">
            <a href="<?= site_url('dashboard') ?>" class="btn btn-sm btn-outline-secondary me-3">
                <i class="bi bi-arrow-left"></i>
            </a>
            <h1 class="h4 mb-0 fw-bold">My Profile</h1>
        </div>

        <div class="card">
            <div class="card-header px-4 py-3">
                <span class="fw-semibold">Account information</span>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="<?= site_url('admin/profile') ?>">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" id="username" name="username" class="form-control"
                               value="<?= esc(old('username', $user->username)) ?>"
                               required autocomplete="username">
                    </div>

                    <div class="mb-4">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" id="email" name="email" class="form-control"
                               value="<?= esc(old('email', $user->email)) ?>"
                               required autocomplete="email">
                    </div>

                    <hr class="border-secondary my-4">

                    <p class="text-secondary small mb-3">
                        <i class="bi bi-lock me-1"></i>Leave the password fields blank to keep your current password.
                    </p>

                    <div class="mb-3">
                        <label for="password" class="form-label">New password</label>
                        <input type="password" id="password" name="password" class="form-control"
                               autocomplete="new-password" minlength="8">
                    </div>

                    <div class="mb-4">
                        <label for="password_confirm" class="form-label">Confirm new password</label>
                        <input type="password" id="password_confirm" name="password_confirm" class="form-control"
                               autocomplete="new-password">
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Save changes
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>

<?= $this->endSection() ?>


