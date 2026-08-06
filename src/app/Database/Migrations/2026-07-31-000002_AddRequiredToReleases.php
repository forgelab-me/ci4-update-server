<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Marks a release as a step that cannot be jumped over.
 *
 * The update panel only ever offers the newest release, so an app on 1.1 goes
 * straight to 1.4. That is safe as long as every release covers the same
 * directories — each rewrites them wholesale. It stops being safe when they
 * differ: a release that shipped `vendor/` and gets skipped is never applied
 * by anything.
 *
 * A required release is served on its own, ahead of the newest one, so an app
 * walks through it and then carries on.
 */
class AddRequiredToReleases extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('releases', [
            'required' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'after'      => 'roots',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('releases', 'required');
    }
}
