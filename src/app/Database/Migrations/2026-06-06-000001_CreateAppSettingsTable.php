<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAppSettingsTable extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'key'   => ['type' => 'VARCHAR', 'constraint' => 255],
            'value' => ['type' => 'TEXT', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('key');
        $this->forge->createTable('app_settings', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('app_settings', true);
    }
}
