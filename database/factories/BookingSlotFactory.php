<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\BookingSlot;
use App\Models\Field;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingSlot>
 */
class BookingSlotFactory extends Factory
{
    protected $model = BookingSlot::class;

    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'field_id'   => Field::factory(),
            'slot_date'  => now()->addDays(3)->toDateString(),
            'slot_hour'  => 10,
            'price'      => 100000,
        ];
    }
}
