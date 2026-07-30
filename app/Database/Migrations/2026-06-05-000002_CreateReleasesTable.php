<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateReleasesTable extends Migration
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
            'version' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
            ],
            'changelog' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'zip_path' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'manifest_path' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'is_latest' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'released_at' => [
                'type' => 'DATE',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addForeignKey('project_id', 'projects', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('releases');
    }

    public function down(): void
    {
        $this->forge->dropTable('releases');
    }
}
