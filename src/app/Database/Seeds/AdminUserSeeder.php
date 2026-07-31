<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use CodeIgniter\Shield\Entities\User as ShieldUser;
use CodeIgniter\Shield\Models\UserModel;

/**
 * Creates (or re-activates) the admin account from ADMIN_EMAIL and
 * ADMIN_PASSWORD_HASH in .env.
 *
 * Usage:
 *   php spark db:seed AdminUserSeeder
 *
 * Idempotent: running it again updates the existing account's password hash
 * to match .env and makes sure it's active, so it doubles as a
 * password-reset / unlock path for the initial admin.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $adminEmail    = trim((string) env('ADMIN_EMAIL', ''));
        $adminPassHash = trim((string) env('ADMIN_PASSWORD_HASH', ''));

        if ($adminEmail === '' || $adminPassHash === '') {
            throw new \RuntimeException(
                'ADMIN_EMAIL and ADMIN_PASSWORD_HASH must be set in .env before seeding the admin user. '
                . 'Generate a hash with: php -r "echo password_hash(\'yourpassword\', PASSWORD_DEFAULT) . PHP_EOL;"'
            );
        }

        /** @var UserModel $userModel */
        $userModel = model(UserModel::class);
        $existing  = $userModel->findByCredentials(['email' => $adminEmail]);

        if ($existing === null) {
            $user = new ShieldUser([
                'username'      => $this->buildUsername($adminEmail),
                'email'         => $adminEmail,
                'password_hash' => $adminPassHash,
                'active'        => 1,
            ]);
            $userModel->save($user);

            echo "Admin user created: {$adminEmail}\n";

            return;
        }

        $existing->active = 1;
        if ($existing->password_hash !== $adminPassHash) {
            $existing->password_hash = $adminPassHash;
        }
        $userModel->save($existing);

        echo "Admin user updated (active, password hash synced from .env): {$adminEmail}\n";
    }

    private function buildUsername(string $email): string
    {
        $username = preg_replace('/[^a-zA-Z0-9\.]/', '', strstr($email, '@', true) ?: 'admin');

        return ($username === '' || $username === null) ? 'admin' : $username;
    }
}
