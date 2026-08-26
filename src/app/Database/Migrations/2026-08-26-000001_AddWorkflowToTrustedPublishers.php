<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Pins a trusted publisher to the pipeline file that may use it, not just the
 * repository and environment.
 *
 * Null accepts any workflow in the repository, same as a null `environment`
 * accepts any run — existing rows keep working unchanged. Set it to the
 * workflow's own path (GitHub: `owner/repo/.github/workflows/release.yml`;
 * GitLab: the `ci_config_ref_uri`, ref stripped) and only that file may mint
 * against the row.
 *
 * Comes from `forgelab-me/ci4-trusted-publishing` ^1.1, which also adds
 * GitLab CI as a second provider.
 */
class AddWorkflowToTrustedPublishers extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('trusted_publishers', [
            'workflow' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'environment',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('trusted_publishers', 'workflow');
    }
}
