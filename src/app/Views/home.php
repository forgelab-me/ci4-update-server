<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Server</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background-color: #0d1117; color: #e6edf3; }
        .navbar  { border-bottom: 1px solid #30363d; background-color: #161b22 !important; }
        .hero    { padding: 5rem 0 4rem; border-bottom: 1px solid #21262d; }
        .hero h1 { font-size: clamp(2rem, 5vw, 3.2rem); font-weight: 700; letter-spacing: -.5px; }
        .hero p  { color: #8b949e; font-size: 1.125rem; max-width: 560px; }

        .stat-card { background: #161b22; border: 1px solid #30363d; border-radius: 8px; padding: 1.5rem 2rem; }
        .stat-card .num { font-size: 2.2rem; font-weight: 700; }
        .stat-card .lbl { font-size: .85rem; color: #8b949e; text-transform: uppercase; letter-spacing: .08em; }

        .section-title { font-size: .7rem; text-transform: uppercase; letter-spacing: .12em; color: #8b949e;
                         border-bottom: 1px solid #21262d; padding-bottom: .6rem; margin-bottom: 1.5rem; }

        .endpoint { background: #161b22; border: 1px solid #30363d; border-radius: 8px; margin-bottom: .75rem; }
        .endpoint .method { font-size: .72rem; font-weight: 700; letter-spacing: .05em; padding: .2rem .55rem;
                            border-radius: 4px; }
        .endpoint .path   { font-family: ui-monospace,"SFMono-Regular",monospace; font-size: .875rem; color: #79c0ff; }
        .endpoint .desc   { font-size: .85rem; color: #8b949e; }

        pre.snippet { background: #161b22; border: 1px solid #30363d; border-radius: 8px;
                      padding: 1rem 1.25rem; font-size: .82rem; color: #e6edf3; white-space: pre-wrap;
                      word-break: break-all; margin: 0; }
        pre.snippet .hl { color: #79c0ff; }

        .project-row:hover td { background: rgba(48,54,61,.4); }
        table { --bs-table-bg: transparent; }
        th { font-size: .72rem; text-transform: uppercase; letter-spacing: .08em; color: #8b949e; border-color: #30363d !important; }
        td { border-color: #21262d !important; vertical-align: middle; }

        .copy-btn { cursor: pointer; opacity: .5; transition: opacity .15s; }
        .copy-btn:hover { opacity: 1; }

        footer { border-top: 1px solid #21262d; color: #6e7681; font-size: .8125rem; }
        footer a { color: #8b949e; text-decoration: none; }
        footer a:hover { color: #79c0ff; text-decoration: underline; }
    </style>
</head>
<body>

<nav class="navbar">
    <div class="container">
        <span class="navbar-brand fw-bold text-white">
            <i class="bi bi-cloud-upload-fill text-primary me-2"></i>Update Server
        </span>
        <a href="<?= site_url('dashboard') ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-lock me-1"></i>Dashboard
        </a>
    </div>
</nav>

<!-- Hero -->
<section class="hero">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-7">
                <p class="text-primary fw-semibold small mb-2 text-uppercase tracking-wide">Software Distribution</p>
                <h1>Ship updates to your<br>apps with one API call.</h1>
                <p class="mt-3">
                    A lightweight self-hosted update server. Upload a release, get a versioned endpoint.
                    Your app checks for updates, downloads the ZIP — done.
                </p>
                <div class="d-flex gap-2 mt-4 flex-wrap">
                    <a href="#api" class="btn btn-primary">
                        <i class="bi bi-code-slash me-2"></i>API Reference
                    </a>
                    <?php if (! empty($publicProjects)): ?>
                    <a href="#projects" class="btn btn-outline-secondary">
                        <i class="bi bi-box-seam me-2"></i>Browse Projects
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="d-flex gap-3">
                    <div class="stat-card flex-fill text-center">
                        <div class="num text-primary"><?= $totalProjects ?></div>
                        <div class="lbl">Project<?= $totalProjects !== 1 ? 's' : '' ?></div>
                    </div>
                    <div class="stat-card flex-fill text-center">
                        <div class="num text-success"><?= $totalReleases ?></div>
                        <div class="lbl">Release<?= $totalReleases !== 1 ? 's' : '' ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- API Reference -->
<section class="py-5" id="api">
    <div class="container">

        <p class="section-title">API Reference</p>

        <div class="row g-5">
            <div class="col-lg-6">

                <div class="endpoint p-3 d-flex align-items-start gap-3">
                    <div>
                        <span class="method bg-success text-white">GET</span>
                    </div>
                    <div>
                        <div class="path">/api/<span class="text-warning">{slug}</span>/latest.json</div>
                        <div class="desc mt-1">Returns the latest release metadata as JSON.</div>
                    </div>
                </div>

                <div class="endpoint p-3 d-flex align-items-start gap-3">
                    <div>
                        <span class="method bg-success text-white">GET</span>
                    </div>
                    <div>
                        <div class="path">/api/<span class="text-warning">{slug}</span>/files/<span class="text-warning">{version}</span>/release.zip</div>
                        <div class="desc mt-1">Downloads the release archive for a given version.</div>
                    </div>
                </div>

                <div class="endpoint p-3 d-flex align-items-start gap-3">
                    <div>
                        <span class="method bg-success text-white">GET</span>
                    </div>
                    <div>
                        <div class="path">/api/<span class="text-warning">{slug}</span>/files/<span class="text-warning">{version}</span>/manifest.json</div>
                        <div class="desc mt-1">Downloads the manifest file for a given version.</div>
                    </div>
                </div>

                <div class="mt-4 p-3" style="background:#161b22;border:1px solid #30363d;border-radius:8px">
                    <p class="small mb-2" style="color:#8b949e">
                        <i class="bi bi-lock-fill me-1 text-warning"></i>
                        <strong class="text-white">Protected projects</strong> require a Bearer token:
                    </p>
                    <pre class="snippet">Authorization: Bearer <span class="hl">&lt;token&gt;</span></pre>
                </div>

            </div>

            <div class="col-lg-6">
                <p class="small text-secondary mb-2">Example response — <code>latest.json</code></p>
                <pre class="snippet">{
  <span class="hl">"version"</span>:      "1.4.2",
  <span class="hl">"changelog"</span>:    "Bug fixes and performance improvements.",
  <span class="hl">"date"</span>:         "2026-06-06",
  <span class="hl">"zip_url"</span>:      "<?= rtrim(base_url(), '/') ?>/api/my-app/files/1.4.2/release.zip",
  <span class="hl">"manifest_url"</span>: "<?= rtrim(base_url(), '/') ?>/api/my-app/files/1.4.2/manifest.json"
}</pre>

                <p class="small text-secondary mt-4 mb-2">Check for updates in your app</p>
                <pre class="snippet"><span style="color:#ff7b72">curl</span> <?= rtrim(base_url(), '/') ?>/api/<span class="hl">my-app</span>/latest.json</pre>

                <p class="small text-secondary mt-3 mb-2">Protected project</p>
                <pre class="snippet"><span style="color:#ff7b72">curl</span> -H "Authorization: Bearer <span class="hl">&lt;token&gt;</span>" \
     <?= rtrim(base_url(), '/') ?>/api/<span class="hl">my-app</span>/latest.json</pre>
            </div>
        </div>

    </div>
</section>

<!-- Public Projects -->
<?php if (! empty($publicProjects)): ?>
<section class="py-5 border-top" style="border-color:#21262d !important" id="projects">
    <div class="container">

        <p class="section-title">Public Projects</p>

        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Slug</th>
                        <th>Latest version</th>
                        <th>Released</th>
                        <th>Endpoint</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($publicProjects as $p): ?>
                    <?php $latest = $latestByProject[$p['id']] ?? null; ?>
                    <tr class="project-row">
                        <td class="fw-semibold"><?= esc($p['name']) ?></td>
                        <td><code><?= esc($p['slug']) ?></code></td>
                        <td>
                            <?php if ($latest): ?>
                                <span class="badge bg-success"><?= esc($latest['version']) ?></span>
                            <?php else: ?>
                                <span class="text-secondary small">No releases</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-secondary small">
                            <?= $latest ? esc($latest['released_at']) : '—' ?>
                        </td>
                        <td>
                            <?php $url = site_url("api/{$p['slug']}/latest.json") ?>
                            <span class="font-monospace small text-secondary" id="ep-<?= $p['id'] ?>">
                                <?= esc($url) ?>
                            </span>
                            <i class="bi bi-copy copy-btn ms-2"
                               onclick="copyText('<?= esc($url, 'js') ?>', this)"
                               title="Copy"></i>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>
</section>
<?php endif; ?>

<?php // This page is public: no version number here either. ?>
<footer class="py-4 mt-2">
    <div class="container d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span>&copy; <?= date('Y') ?> ForgeLab &mdash; MIT</span>
        <span class="d-flex flex-wrap gap-3">
            <a href="https://github.com/forgelab-me/ci4-update-server" target="_blank" rel="noopener noreferrer">
                <i class="bi bi-github me-1"></i>forgelab-me/ci4-update-server
            </a>
            <a href="<?= site_url('dashboard') ?>">Dashboard</a>
        </span>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function copyText(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        btn.className = 'bi bi-check2 ms-2 text-success';
        setTimeout(() => btn.className = 'bi bi-copy copy-btn ms-2', 1500);
    });
}
</script>
</body>
</html>


