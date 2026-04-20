<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Purge>
 */
class PurgeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * By default a purge is bound to a freshly created Twitter account
     * (owned by a freshly created user). Tests that need an orphan purge
     * must set 'account_id' => null explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'post_id' => fake()->unique()->numberBetween(1, 1000000),
            'posted_at' => fake()->dateTimeBetween('-2 years', 'now'),
            'text' => fake()->optional(0.9)->sentence(20),
            'account_id' => Account::factory()->twitter(),
        ];
    }

    /**
     * An orphan purge with no owning account. The scheduler and UI must
     * treat these as invisible under the single-tenant hardening.
     */
    public function orphan(): static
    {
        return $this->state(fn (array $attributes) => [
            'account_id' => null,
        ]);
    }
}
