<?php

// database/seeders/AdminUserSeeder.php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\User;

class AdminUserSeeder extends Seeder {
  public function run(): void {
    foreach ([
      ['email' => 'agahariiswarajati@gmail.com', 'name' => 'Aga'],
      // ['email' => 'panitia@domain.id', 'name' => 'Panitia'],
    ] as $adm) {
      $u = User::firstOrCreate(['email'=>$adm['email']], ['name'=>$adm['name']]);
      if ($u->role !== 'superadmin') { $u->role = 'superadmin'; $u->save(); }
    }
  }
}
