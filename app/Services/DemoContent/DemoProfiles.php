<?php

namespace App\Services\DemoContent;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;

class DemoProfiles
{
    public function prepare(int $count, int $seed, array $categories): array
    {
        $names = ['Анна', 'Михаил', 'Мария', 'Даниил', 'София', 'Артём', 'Алиса', 'Кирилл', 'Елена', 'Роман', 'Вера', 'Алексей', 'Юлия', 'Иван', 'Полина'];
        $last = ['Светлова', 'Миронов', 'Волкова', 'Лебедев', 'Орлова', 'Соколов', 'Белова', 'Морозов', 'Лесная', 'Громов', 'Лукина', 'Зайцев', 'Речная', 'Крылов', 'Зорина'];
        $users = [];
        for ($i = 0; $i < $count; $i++) {
            $email = "curator-$seed-$i@library-stroll.test";
            $existing = User::withoutGlobalScopes()->where('email', $email)->first();
            if ($existing) {
                if (! $existing->is_demo) {
                    throw new \RuntimeException('Demo email belongs to a real account');
                } $users[] = $existing;

                continue;
            }
            $theme = array_values($categories)[$i % count($categories)]['label'];
            $bios = ['Собираю наблюдения о композиции и материалах, сравниваю разные эпохи.', 'Ищу выразительные детали и сопоставляю графику, фотографию и живопись.', 'Изучаю открытые архивы и составляю небольшие тематические подборки для вдохновения.'];
            $process = (new Process(['node', config('demo_content.avatar_script')]))->setInput(json_encode(["library-stroll-$seed-$i"]))->setTimeout(60);
            $process->mustRun();
            $png = base64_decode(json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR)[0], true);
            if (! $png || (@getimagesizefromstring($png)['mime'] ?? '') !== 'image/png') {
                throw new \RuntimeException('Invalid local avatar');
            }
            $path = "profile-photos/demo-$seed-$i.png";
            if (Storage::disk('public')->exists($path)) {
                throw new \RuntimeException('Avatar path collision');
            }
            if (! Storage::disk('public')->put($path, $png)) {
                throw new \RuntimeException('Unable to save avatar');
            }
            try {
                $user = new User(['name' => $names[$i % 15].' '.$last[($i + intdiv($i, 15) * 4) % 15], 'email' => $email,
                    'password' => Str::random(64), 'description' => "Демонстрационный профиль куратора. Интересы: $theme. ".$bios[intdiv($i, count($categories)) % 3].' Оригинальные авторы и лицензии указаны у каждой публикации.']);
                $user->forceFill(['is_demo' => true, 'profile_photo_path' => $path, 'created_at' => now()->subDays(30 + (($seed + $i * 17) % 600))])->save();
                $user->assignRole(Role::findOrCreate('User', 'web'));
                $users[] = $user;
            } catch (\Throwable $e) {
                Storage::disk('public')->delete($path);
                throw $e;
            }
        }

        return $users;
    }
}
