<?php

namespace Database\Factories;

use App\Models\FieldType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FieldType>
 */
class FieldTypeFactory extends Factory
{
    protected $model = FieldType::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word() . ' Sport',
            'icon' => null,
        ];
    }
}
