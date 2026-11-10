<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Field;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        return [
            'booking_code'   => 'SB-' . strtoupper(fake()->unique()->bothify('????????')),
            'user_id'        => User::factory(),
            'field_id'       => Field::factory(),
            'booking_date'   => now()->addDays(3)->toDateString(),
            'start_time'     => '10:00',
            'end_time'       => '11:00',
            'duration_hours' => 1,
            'total_price'    => 100000,
            'status'         => 'menunggu_pembayaran',
        ];
    }

    /**
     * Booking dengan status terkonfirmasi
     */
    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'terkonfirmasi',
        ]);
    }

    /**
     * Booking dengan status dibatalkan
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status'       => 'dibatalkan',
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Booking dengan status selesai
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'selesai',
        ]);
    }
}
