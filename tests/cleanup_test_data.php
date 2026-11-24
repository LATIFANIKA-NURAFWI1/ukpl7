<?php
/**
 * cleanup_test_data.php — menghapus data uji konkurensi (booking, slot, user uji).
 * Dijalankan setelah pengujian agar database pengembangan kembali bersih.
 */

$repo = dirname(__DIR__); // akar proyek (berkas ini ada di tests/)

require $repo . '/vendor/autoload.php';
$app = require_once $repo . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Booking;
use App\Models\BookingSlot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$emails = [];
for ($i = 1; $i <= 10; $i++) {
    $emails[] = "uji.konkurensi.{$i}@sportbook.test";
}

$ids = User::whereIn('email', $emails)->pluck('id')->all();

$slots = BookingSlot::whereIn('booking_id', Booking::whereIn('user_id', $ids)->pluck('id'))->count();
BookingSlot::whereIn('booking_id', Booking::whereIn('user_id', $ids)->pluck('id'))->delete();
$bookings = Booking::whereIn('user_id', $ids)->count();
Booking::whereIn('user_id', $ids)->delete();
User::whereIn('id', $ids)->delete();

echo "CLEANUP user={$ids[0]}.." . end($ids) . " bookings_dihapus={$bookings} slots_dihapus={$slots}" . PHP_EOL;
echo 'SISA booking_slots total=' . BookingSlot::count() . ' bookings total=' . Booking::count() . PHP_EOL;
