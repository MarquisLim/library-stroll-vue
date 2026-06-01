<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        collect(['User', 'Moderator', 'Admin', 'SuperAdmin'])
            ->each(fn ($name) => Role::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]));
    }
}
