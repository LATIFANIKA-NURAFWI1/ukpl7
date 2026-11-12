<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingSlot;
use App\Models\Field;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class BookingService
{
    /**
     * Normalisasi daftar jam: cast integer, hapus duplikat, dan urutkan menaik.
     *
     * @param  array<int, int|string>  $hours
     * @return list<int>
     */
    public function normalizeHours(array $hours): array
    {
        $normalized = array_values(array_unique(array_map('intval', $hours)));
        sort($normalized);

        return $normalized;
    }

    /**
     * Validasi apakah slot jam berurutan tanpa jeda.
     *
     * @param  array<int, int|string>  $hours
     */
    public function areSlotsContiguous(array $hours): bool
    {
        $hours = $this->normalizeHours($hours);
        if (count($hours) <= 1) {
            return count($hours) === 1;
        }

        for ($i = 1; $i < count($hours); $i++) {
            if ($hours[$i] - $hours[$i - 1] !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Cek apakah ada slot jam yang sudah melewati waktu sekarang.
     *
     * @param  array<int, int|string>  $hours
     */
    public function hasPastSlots(string $bookingDate, array $hours): bool
    {
        $now = now();
        $dateStr = Carbon::parse($bookingDate)->toDateString();

        foreach ($this->normalizeHours($hours) as $hour) {
            $slotStart = Carbon::parse($dateStr . ' ' . sprintf('%02d:00:00', $hour));
            if ($slotStart->lte($now)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ambil waktu mulai booking paling awal berdasarkan slot_hour terkecil.
     */
    public function getStartDateTime(Booking $booking): Carbon
    {
        $earliestHour = $booking->slots()->min('slot_hour');
        $startTime = $earliestHour !== null
            ? sprintf('%02d:00:00', (int) $earliestHour)
            : $booking->start_time;

        $dateStr = $booking->booking_date instanceof Carbon
            ? $booking->booking_date->toDateString()
            : Carbon::parse($booking->booking_date)->toDateString();

        return Carbon::parse($dateStr . ' ' . $startTime);
    }

    public function createBooking(array $data, Field $field): Booking
    {
        $hours = $this->normalizeHours($data['hours'] ?? []);
        if (empty($hours)) {
            throw new \DomainException('Silakan pilih minimal 1 slot jam.');
        }

        if (!$this->areSlotsContiguous($hours)) {
            throw new \DomainException('Slot jam yang dipilih harus berurutan tanpa jeda.');
        }

        $bookingDate = Carbon::parse($data['booking_date'])->toDateString();
        if ($this->hasPastSlots($bookingDate, $hours)) {
            throw new \DomainException('Jam yang dipilih sudah lewat. Silakan pilih jam yang masih tersedia.');
        }

        $slotPrices = [];
        $totalPrice = 0;
        foreach ($hours as $hour) {
            $price = $field->getPriceForSlot($hour, $bookingDate);
            $slotPrices[$hour] = $price;
            $totalPrice += $price;
        }

        $paymentType = $data['payment_type'] ?? 'full';
        if ($paymentType === 'dp') {
            $amountPaid = $totalPrice * 0.5;
            $remaining = $totalPrice - $amountPaid;
        } else {
            $amountPaid = $totalPrice;
            $remaining = 0;
        }

        $startHour = min($hours);
        $endHour = max($hours) + 1;
        $duration = count($hours);
        $userId = $data['user_id'] ?? auth()->id();

        try {
            return DB::transaction(function () use (
                $field,
                $userId,
                $bookingDate,
                $hours,
                $slotPrices,
                $startHour,
                $endHour,
                $duration,
                $totalPrice,
                $paymentType,
                $amountPaid,
                $remaining
            ) {
                $lockedField = Field::whereKey($field->id)->lockForUpdate()->firstOrFail();
                if (!$lockedField->is_active) {
                    throw new \DomainException('Lapangan sedang tidak aktif dan tidak dapat dipesan.');
                }

                $bookedHours = BookingSlot::where('field_id', $lockedField->id)
                    ->where('slot_date', $bookingDate)
                    ->whereIn('slot_hour', $hours)
                    ->lockForUpdate()
                    ->pluck('slot_hour')
                    ->map(fn ($h) => (int) $h)
                    ->all();

                if (!empty($bookedHours)) {
                    $formatted = implode(', ', array_map(fn ($h) => sprintf('%02d:00', $h), $bookedHours));
                    throw new \DomainException("Slot jam {$formatted} sudah dipesan, silakan pilih jam lain.");
                }

                $booking = Booking::create([
                    'booking_code'      => Booking::generateCode(),
                    'user_id'           => $userId,
                    'field_id'          => $lockedField->id,
                    'start_time'        => sprintf('%02d:00', $startHour),
                    'end_time'          => sprintf('%02d:00', $endHour),
                    'booking_date'      => $bookingDate,
                    'duration_hours'    => $duration,
                    'total_price'       => $totalPrice,
                    'payment_type'      => $paymentType,
                    'amount_paid'       => $amountPaid,
                    'remaining_payment' => $remaining,
                    'status'            => 'menunggu_pembayaran',
                ]);

                foreach ($hours as $hour) {
                    $booking->slots()->create([
                        'field_id'  => $lockedField->id,
                        'slot_date' => $bookingDate,
                        'slot_hour' => $hour,
                        'price'     => $slotPrices[$hour],
                    ]);
                }

                return $booking;
            });
        } catch (QueryException $e) {
            $sqlState = $e->errorInfo[0] ?? $e->getCode();
            if ((string) $sqlState === '23000') {
                throw new \DomainException('Slot sudah dipesan, silakan pilih jam lain.', 0, $e);
            }

            throw $e;
        }
    }

    public function getBookedSlots($fieldId, $date): array
    {
        return BookingSlot::whereHas('booking', function ($q) use ($fieldId, $date) {
            $q->where('field_id', $fieldId)
                ->where('booking_date', $date)
                ->whereIn('status', ['menunggu_pembayaran', 'terkonfirmasi', 'selesai']);
        })->pluck('slot_hour')->map(fn ($h) => (int) $h)->all();
    }

    public function cancelBooking(Booking $booking, string $targetStatus = 'dibatalkan'): Booking
    {
        if (!in_array($targetStatus, ['dibatalkan', 'refund'], true)) {
            throw new \InvalidArgumentException("Target status pembatalan tidak valid: {$targetStatus}");
        }

        return DB::transaction(function () use ($booking, $targetStatus) {
            $lockedBooking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if (!in_array($lockedBooking->status, ['menunggu_pembayaran', 'terkonfirmasi'], true)) {
                throw new \DomainException('Booking ini tidak dapat dibatalkan.');
            }

            $bookingStart = $this->getStartDateTime($lockedBooking);
            $diffInHours = now()->diffInHours($bookingStart, false);

            $refundAmount = 0;
            if ($diffInHours >= 24) {
                $refundAmount = $lockedBooking->amount_paid;
            } elseif ($diffInHours >= 12) {
                $refundAmount = $lockedBooking->amount_paid * 0.5;
            }

            $lockedBooking->update([
                'status'        => $targetStatus,
                'refund_amount' => $refundAmount,
                'cancelled_at'  => now(),
            ]);

            $lockedBooking->slots()->delete();

            return $lockedBooking->fresh();
        });
    }
}
