<?= $this->extend('layouts/main') ?>
<?= $this->section('content') ?>

<div class="d-flex align-items-center mb-4">
    <a href="<?= site_url('dashboard') ?>" class="btn btn-outline-secondary btn-sm me-3">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h1 class="h3 mb-0 fw-bold">New Project</h1>
</div>

<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card">
            <div class="card-body p-4">
                <form method="POST" action="<?= site_url('projects/new') ?>">
                    <?= csrf_field() ?>

                    <div class="mb-3">
                        <label for="name" class="form-label">Project Name</label>
                        <input type="text" id="name" name="name" class="form-control"
                            value="<?= esc(old('name')) ?>"
                            placeholder="e.g. My App" required autofocus>
                    </div>

                    <div class="mb-3">
                        <label for="slug" class="form-label">
                            Slug
                            <span class="text-muted fw-normal small">— used in API URLs</span>
                        </label>
                        <input type="text" id="slug" name="slug" class="form-control font-monospace"
                            value="<?= esc(old('slug')) ?>"
                            placeholder="e.g. my-app"
                            pattern="[a-z0-9\-]+"
                            title="Lowercase letters, digits and hyphens only"
                            required>
                        <div class="form-text">Preview: <code id="slugPreview"><?= site_url('api/') ?><span id="slugVal">your-slug</span>/latest.json</code></div>
                    </div>

                    <div class="mb-4">
                        <label for="token" class="form-label">
                            API Token
                            <span class="text-muted fw-normal small">— leave empty for public access</span>
                        </label>
                        <div class="input-group">
                            <input type="text" id="token" name="token" class="form-control font-monospace"
                                value="<?= esc(old('token')) ?>"
                                placeholder="Leave empty for no authentication"
                                autocomplete="off">
                            <button type="button" class="btn btn-outline-secondary" id="genToken"
                                title="Generate a random token">
                                <i class="bi bi-arrow-clockwise me-1"></i>Generate
                            </button>
                        </div>
                        <div class="form-text">If set, clients must send <code>Authorization: Bearer &lt;token&gt;</code>.</div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-lg me-1"></i>Create Project
                        </button>
                        <a href="<?= site_url('admin') ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const nameEl  = document.getElementById('name');
    const slugEl  = document.getElementById('slug');
    const slugVal = document.getElementById('slugVal');
    const tokenEl = document.getElementById('token');
    let slugEdited = slugEl.value !== '';

    function toSlug(str) {
        return str.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    }

    nameEl.addEventListener('input', function () {
        if (!slugEdited) {
            slugEl.value = toSlug(this.value);
            slugVal.textContent = slugEl.value || 'your-slug';
        }
    });

    slugEl.addEventListener('input', function () {
        slugEdited = true;
        slugVal.textContent = this.value || 'your-slug';
    });

    slugVal.textContent = slugEl.value || 'your-slug';

    document.getElementById('genToken').addEventListener('click', function () {
        const arr = new Uint8Array(32);
        window.crypto.getRandomValues(arr);
        tokenEl.value = Array.from(arr).map(b => b.toString(16).padStart(2, '0')).join('');
    });
})();
</script>

<?= $this->endSection() ?>


