<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Counts how many times each release archive has been downloaded.
 *
 * Per release rather than per project: a project total is the sum of its
 * releases, so the two can never drift apart. Only `release.zip` counts —
 * fetching `manifest.json` is a metadata lookup, and counting it would inflate
 * the figure with checks that never downloaded anything.
 */
class AddDownloadsToReleases extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('releases', [
            'downloads' => [
                'type'     => 'INT',
                'unsigned' => true,
                'default'  => 0,
                'after'    => 'required',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('releases', 'downloads');
    }
}
