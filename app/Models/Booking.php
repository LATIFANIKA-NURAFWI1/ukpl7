<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Booking extends Model
{
    use HasFactory;

    public const STATUS_LABELS = [
        'menunggu_pembayaran' => 'Menunggu Pembayaran',
        'terkonfirmasi'       => 'Terkonfirmasi',
        'selesai'             => 'Selesai',
        'dibatalkan'          => 'Dibatalkan',
        'refund'              => 'Refund',
    ];

    public const ALLOWED_TRANSITIONS = [
        'menunggu_pembayaran' => ['terkonfirmasi', 'dibatalkan'],
        'terkonfirmasi'       => ['selesai', 'dibatalkan', 'refund'],
        'dibatalkan'          => ['refund'],
        'selesai'             => [],
        'refund'              => [],
    ];

    protected $fillable = [
        'booking_code',
        'user_id',
        'field_id',
        'booking_date',
        'start_time',
        'end_time',
        'duration_hours',
        'total_price',
        'payment_type',       // FIX: ditambahkan agar tidak terkena mass-assignment bug
        'amount_paid',        // FIX: ditambahkan agar nilai DP/full tersimpan
        'remaining_payment',  // FIX: ditambahkan agar sisa bayar tersimpan
        'payment_proof',
        'status',
        'refund_amount',
        'cancelled_at',
    ];

    protected $casts = [
        'booking_date'      => 'date',
        'cancelled_at'      => 'datetime',
        'total_price'       => 'float',
        'amount_paid'       => 'float',
        'remaining_payment' => 'float',
        'refund_amount'     => 'float',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function field()
    {
        return $this->belongsTo(Field::class);
    }

    public function slots()
    {
        return $this->hasMany(BookingSlot::class);
    }

    /**
     * Generate unique booking code: SB-XXXXXXXX
     */
    public static function generateCode(): string
    {
        do {
            $code = 'SB-' . strtoupper(Str::random(8));
        } while (self::where('booking_code', $code)->exists());

        return $code;
    }

    /**
     * Helper: badge color per status
     */
    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'menunggu_pembayaran' => 'warning',
            'terkonfirmasi'       => 'success',
            'selesai'             => 'primary',
            'dibatalkan'          => 'danger',
            'refund'              => 'secondary',
            default               => 'light',
        };
    }

    public static function labelForStatus(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? ucfirst($status);
    }

    public function statusLabel(): string
    {
        return self::labelForStatus($this->status);
    }

    /**
     * Daftar status tujuan yang diizinkan dari status saat ini.
     *
     * @return list<string>
     */
    public function allowedNextStatuses(): array
    {
        return self::ALLOWED_TRANSITIONS[$this->status] ?? [];
    }

    public function canTransitionTo(string $targetStatus): bool
    {
        return in_array($targetStatus, $this->allowedNextStatuses(), true);
    }
}
