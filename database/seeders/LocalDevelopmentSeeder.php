<?php

namespace Database\Seeders;

use App\Enums\SystemRole;
use App\Models\Artist;
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
 *
 * Artist account (linked to the first artist profile, when one exists):
 *   Email:    artist@labeldesk.test
 *   Password: ArtistPassw0rd!
 */
class LocalDevelopmentSeeder extends Seeder
{
    public const EMAIL = 'dev@labeldesk.test';

    public const PASSWORD = 'DevPassw0rd!';

    public const ARTIST_EMAIL = 'artist@labeldesk.test';

    public const ARTIST_PASSWORD = 'ArtistPassw0rd!';

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

        $artist = Artist::query()->orderBy('id')->first();
        if ($artist) {
            $artistUser = User::query()->firstOrNew(['email' => self::ARTIST_EMAIL]);
            $artistUser->forceFill([
                'name' => $artist->name.' (artist)',
                'password' => self::ARTIST_PASSWORD,
                'is_active' => true,
                'email_verified_at' => now(),
            ])->save();
            $artistUser->syncRoles([SystemRole::Artist->value]);
            $artistUser->artists()->sync([$artist->id]);
        }
    }
}
