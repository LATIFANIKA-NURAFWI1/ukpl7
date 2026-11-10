<?php

namespace Database\Factories;

use App\Models\Field;
use App\Models\FieldType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Field>
 */
class FieldFactory extends Factory
{
    protected $model = Field::class;

    public function definition(): array
    {
        return [
            'field_type_id' => FieldType::factory(),
            'name'          => fake()->unique()->words(2, true) . ' Court',
            'price_offpeak' => 100000,
            'price_peak'    => 120000,
            'description'   => fake()->sentence(),
            'photo'         => null,
            'is_active'     => true,
        ];
    }

    /**
     * Field yang tidak aktif (nonaktif)
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
