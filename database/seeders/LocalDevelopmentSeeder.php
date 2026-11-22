<?php

namespace Database\Seeders;

use App\Enums\SystemRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * DEVELOPMENT ONLY. Creates (or resets) a local Super Admin account for working
 * on the UI. Refuses to run outside the "local" environment and is never
 * called by the installer or DatabaseSeeder.
 *
 *   php artisan db:seed --class=LocalDevelopmentSeeder
 *
 * Local development credentials (also used when walking through the
 * installation wizard locally):
 *   Email:    dev@labeldesk.test
 *   Password: DevPassw0rd!
 *   License:  MD-LD-TEST-ACTV-AAAA-BBBB (mock provider, LICENSE_DRIVER=mock)
 */
class LocalDevelopmentSeeder extends Seeder
{
    public const EMAIL = 'dev@labeldesk.test';

    public const PASSWORD = 'DevPassw0rd!';

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('LocalDevelopmentSeeder may only run in the local environment.');
        }

        $user = User::query()->firstOrNew(['email' => self::EMAIL]);
        $user->forceFill([
            'name' => 'Dev Administrator',
            'password' => self::PASSWORD,
            'is_active' => true,
            'email_verified_at' => now(),
        ])->save();

        $user->syncRoles([SystemRole::SuperAdmin->value]);
    }
}
