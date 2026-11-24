<?php
/**
 * setup_test_users.php — membuat 10 user penguji untuk uji konkurensi SportBook.
 * Data uji ini dihapus kembali oleh cleanup_test_data.php setelah pengujian.
 */

$repo = dirname(__DIR__); // akar proyek (berkas ini ada di tests/)

require $repo . '/vendor/autoload.php';
$app = require_once $repo . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ids = [];
for ($i = 1; $i <= 10; $i++) {
    $u = App\Models\User::firstOrCreate(
        ['email' => "uji.konkurensi.{$i}@sportbook.test"],
        [
            'name'              => "Penguji Konkurensi {$i}",
            'password'          => bcrypt('password'),
            'role'              => 'user',
            'phone'             => '0800000000' . $i,
            'email_verified_at' => now(),
        ]
    );
    $ids[] = $u->id;
}

echo 'USER_IDS=' . implode(',', $ids) . PHP_EOL;
