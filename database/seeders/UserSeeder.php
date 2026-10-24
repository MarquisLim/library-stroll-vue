<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Support\SeedImageGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command->error('UserSeeder доступен только в local/testing.');

            return;
        }

        $imageGen = new SeedImageGenerator();

        $users = [
            [
                'name'        => 'Admin',
                'email'       => 'admin@libstroll.test',
                'description' => 'Администратор платформы LibraryStroll.',
                'role'        => 'Admin',
            ],
            [
                'name'        => 'Moderator',
                'email'       => 'mod@libstroll.test',
                'description' => 'Модератор контента.',
                'role'        => 'Moderator',
            ],
            [
                'name'        => 'Котофей',
                'email'       => 'kotofey@libstroll.test',
                'description' => 'Фотографирую котиков — рыжих, серых и полосатых. Мурчащий контент каждый день.',
                'role'        => 'User',
                'theme'       => 'cats',
            ],
            [
                'name'        => 'Мурка Пушистова',
                'email'       => 'murka@libstroll.test',
                'description' => 'Обожаю котят! Снимаю пушистых малышей на камеру.',
                'role'        => 'User',
                'theme'       => 'cats',
            ],
            [
                'name'        => 'DogLens',
                'email'       => 'dogs@libstroll.test',
                'description' => 'Dog photographer. Portraits, walks, puppies, and loyal friends.',
                'role'        => 'User',
                'theme'       => 'dogs',
            ],
            [
                'name'        => 'NatureEye',
                'email'       => 'nature@libstroll.test',
                'description' => 'Landscape photographer. Capturing sunsets, mountains, and forests around the world.',
                'role'        => 'User',
                'theme'       => 'nature',
            ],
            [
                'name'        => 'Горизонт',
                'email'       => 'horizon@libstroll.test',
                'description' => 'Пейзажная фотография. Путешествия и природа — моя страсть.',
                'role'        => 'User',
                'theme'       => 'nature',
            ],
            [
                'name'        => 'TurboPixel',
                'email'       => 'turbo@libstroll.test',
                'description' => 'Automotive photography and renders. Supercars, muscle cars, vintage rides.',
                'role'        => 'User',
                'theme'       => 'cars',
            ],
            [
                'name'        => 'DragonForge',
                'email'       => 'dragon@libstroll.test',
                'description' => 'Fantasy illustrator. Worlds of dragons, magic, and ancient civilizations.',
                'role'        => 'User',
                'theme'       => 'fantasy',
            ],
            [
                'name'        => 'Ноосфера',
                'email'       => 'noosfera@libstroll.test',
                'description' => 'Абстрактная цифровая живопись. Геометрия, паттерны, цвет.',
                'role'        => 'User',
                'theme'       => 'abstract',
            ],
            [
                'name'        => 'PixelWitch',
                'email'       => 'pixelwitch@libstroll.test',
                'description' => 'Digital artist & concept designer. Characters, environments, and UI art.',
                'role'        => 'User',
                'theme'       => 'digital',
            ],
            [
                'name'        => 'СтритАртист',
                'email'       => 'street@libstroll.test',
                'description' => 'Город, граффити, архитектура. Снимаю улицы и то, что на них.',
                'role'        => 'User',
                'theme'       => 'general',
            ],
            [
                'name'        => 'Новичок',
                'email'       => 'newbie@libstroll.test',
                'description' => 'Только начинаю выкладывать свои работы.',
                'role'        => 'User',
                'theme'       => 'general',
            ],
        ];

        $password = Hash::make('password');

        foreach ($users as $i => $data) {
            $user = User::withoutGlobalScopes()->firstOrCreate(
                ['email' => $data['email']],
                [
                    'name'              => $data['name'],
                    'email_verified_at' => now(),
                    'password'          => $password,
                    'description'       => $data['description'],
                    'views_count'       => $this->viewsForIndex($i),
                ]
            );

            $roleName = $data['role'];
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($user->wasRecentlyCreated && $role && ! $user->hasRole($roleName)) {
                $user->assignRole($roleName);
            }

            if (! $user->profile_photo_path) {
                $avatarPath = $imageGen->avatar($i, $data['name']);
                if (file_exists($avatarPath)) {
                    $dest = "profile-photos/{$user->id}.jpg";
                    Storage::disk('public')->put($dest, file_get_contents($avatarPath));
                    $user->forceFill(['profile_photo_path' => $dest])->save();
                }
            }
        }

        $imageGen->cleanup();

        $this->command->info('UserSeeder: ' . User::withoutGlobalScopes()->count() . ' users.');
    }

    private function viewsForIndex(int $i): int
    {
        return match (true) {
            $i < 2  => rand(500, 2000),
            $i < 6  => rand(100, 800),
            $i < 10 => rand(20, 300),
            default => rand(0, 50),
        };
    }
}
