<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * GitHub workflows allowed to publish to a project without a stored secret.
 *
 * A workflow presents the short-lived OIDC token GitHub mints for it; the
 * server checks its claims against a row here and, if they match, issues an
 * upload token valid for minutes. Nothing long-lived is ever stored in CI.
 *
 * `repository_owner_id` is the numeric GitHub account id, and it is what the
 * match is really pinned on. Pinning on the name alone would mean that if the
 * account were ever renamed or deleted, whoever claimed the freed name could
 * publish here. The id is not reassigned.
 *
 * `environment` is optional but is where this earns its keep when releases are
 * signed in CI: require a protected environment, and the signing secret is
 * only exposed after a human approves the run — with the server verifying that
 * the token really came from it.
 */
class CreateTrustedPublishersTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'project_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            // The admin who trusted this workflow. Minted tokens act as them,
            // so a publish is always attributable to a person.
            'user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            // Only GitHub for now; the column keeps the door open.
            'provider' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'github',
            ],
            'repository' => [
                'type'       => 'VARCHAR',
                'constraint' => 140,
            ],
            'repository_owner_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'environment' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'last_used_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('project_id');
        $this->forge->addForeignKey('project_id', 'projects', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('trusted_publishers');
    }

    public function down(): void
    {
        $this->forge->dropTable('trusted_publishers');
    }
}
