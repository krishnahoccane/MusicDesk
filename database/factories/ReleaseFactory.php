<?php

namespace Database\Factories;

use App\Models\Release;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-only factory. Production installs never receive dummy releases.
 *
 * @extends Factory<Release>
 */
class ReleaseFactory extends Factory
{
    protected $model = Release::class;

    public function definition(): array
    {
        return [
            'title' => fake()->unique()->sentence(3, false),
            'type' => 'single',
            'label_name' => 'Test Records',
            'c_line' => '2026 Test Records',
            'p_line' => '2026 Test Records',
            'release_date' => now()->addMonth()->format('Y-m-d'),
        ];
    }
}
