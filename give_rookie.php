<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';

use App\Models\User;
use App\Models\Achievement;

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(Illuminate\Http\Request::capture());

$user = User::where('nim', '2106116092')->first();
$rookie = Achievement::where('name', 'Rookie')->first();

if ($user && $rookie) {
    $user->achievements()->syncWithoutDetaching([$rookie->id => ['earned_at' => now()]]);
    echo "Lencana Rookie berhasil diberikan untuk " . $user->name;
} else {
    echo "User atau Achievement tidak ditemukan.";
}
