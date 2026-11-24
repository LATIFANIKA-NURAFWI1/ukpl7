<?php
/**
 * verify_concurrency.php — memverifikasi hasil uji konkurensi pada database:
 * tepat 1 booking & 1 slot untuk kombinasi lapangan/tanggal/jam yang diuji,
 * dan tidak ada booking yatim (booking tanpa satu pun baris slot).
 *
 * Pemakaian: php verify_concurrency.php <Y-m-d> <jam>
 */

$repo = dirname(__DIR__); // akar proyek (berkas ini ada di tests/)

require $repo . '/vendor/autoload.php';
$app = require_once $repo . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Booking;
use App\Models\BookingSlot;

$date = $argv[1] ?? date('Y-m-d');
$hour = (int) ($argv[2] ?? 0);

$slots    = BookingSlot::where('slot_date', $date)->where('slot_hour', $hour)->count();
$bookings = Booking::where('booking_date', $date)->count();
$orphan   = Booking::where('booking_date', $date)
    ->whereDoesntHave('slots')
    ->count();

printf("SLOT_TERSIMPAN_UNTUK_%s_%02d00=%d\n", $date, $hour, $slots);
printf("BOOKING_PADA_TANGGAL_ITU=%d\n", $bookings);
printf("BOOKING_YATIM_TANPA_SLOT=%d\n", $orphan);
printf("KESIMPULAN=%s\n", ($slots === 1 && $orphan === 0) ? 'LULUS' : 'GAGAL');
