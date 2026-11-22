<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Essential production defaults. Run by the installer and on upgrades, so
 * every seeder here must be idempotent. Demo content is never seeded here.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            CatalogReferenceSeeder::class,
            DspSeeder::class,
        ]);
    }
}
