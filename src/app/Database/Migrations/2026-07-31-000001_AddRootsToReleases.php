<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Records which top-level directories a release covers.
 *
 * ci4-updater 2.6 made a release declare its own scope in the manifest, so
 * this is simply read from the archive on upload. It is shown next to the
 * release: a release that ships vendor/ is an order of magnitude larger than
 * one that doesn't, and is refused by any app that hasn't opted into it —
 * both worth seeing before pointing an installation at it.
 *
 * Null on releases uploaded before this, and on any manifest without `roots`.
 */
class AddRootsToReleases extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('releases', [
            'roots' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'version',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('releases', 'roots');
    }
}
