<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Tag;
use App\Models\Artwork;
use App\Models\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Faker\Factory as Faker;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            ComplaintTypeSeeder::class,
        ]);
    }
}

