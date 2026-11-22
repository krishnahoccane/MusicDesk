<?php

namespace Database\Factories;

use App\Enums\ArtistStatus;
use App\Enums\ArtistType;
use App\Models\Artist;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-only factory. Production installs never receive dummy artists.
 *
 * @extends Factory<Artist>
 */
class ArtistFactory extends Factory
{
    protected $model = Artist::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->name(),
            'legal_name' => null,
            'type' => ArtistType::Solo,
            'status' => ArtistStatus::Active,
            'country' => 'GB',
            'primary_genre' => 'Electronic',
            'email' => fake()->safeEmail(),
        ];
    }
}
