<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Collection;
use App\Models\Artwork;
use App\Models\Tag;
use Illuminate\Support\Facades\Http;
use Faker\Factory as Faker;
use Illuminate\Support\Str;

class UnsplashSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker     = Faker::create();
        $accessKey = config('services.unsplash.access_key');

        // Создаём несколько пользователей
        User::factory(10)
            ->create(['password' => bcrypt('password')])
            ->each(function ($user) use ($faker, $accessKey) {
                // Коллекция для каждого пользователя
                $collection = Collection::create([
                    'user_id'    => $user->id,
                    'name'       => 'Unsplash Picks',
                    'is_private' => false,
                ]);

                // Сколько артов добавить
                $count = rand(5, 10);
                for ($i = 0; $i < $count; $i++) {
                    // Запрос к Unsplash Random Photo
                    $response = Http::withHeaders([
                        'Authorization' => "Client-ID {$accessKey}",
                    ])->get('https://api.unsplash.com/photos/random', [
                        'orientation'    => 'landscape',
                        'content_filter' => 'high',
                    ]);

                    if (! $response->ok()) {
                        continue;
                    }

                    $data = $response->json();
                    $url  = $data['urls']['regular'] ?? null;
                    if (! $url) {
                        continue;
                    }

                    // Формируем заголовок и описание
                    $title       = $data['description']
                        ?: $data['alt_description']
                            ?: "Photo by {$data['user']['name']}";
                    $description = $data['description']
                        ?: $data['alt_description']
                            ?: $faker->sentence();

                    // Создаём запись Artwork
                    $art = Artwork::create([
                        'user_id'        => $user->id,
                        'title'          => Str::limit($title, 250, '…'),
                        'description'    => $description,
                        'is_published'   => true,
                        'allow_download' => true,
                        'allow_comments' => true,
                        'views_count'    => rand(0, 1000),
                    ]);

                    // Прикрепляем картинку через spatie/laravel-medialibrary
                    try {
                        $art->addMediaFromUrl($url)
                            ->toMediaCollection('artworks');
                    } catch (\Exception $e) {
                        // если не удалось скачать/сохранить — удаляем запись
                        $art->delete();
                        continue;
                    }

                    // Синхронизируем теги из ответа Unsplash
                    $tagTitles = collect($data['tags'] ?? [])
                        ->pluck('title')
                        ->map(fn($t) => Str::slug($t))
                        ->filter()
                        ->unique()
                        ->toArray();

                    $tagIds = Tag::whereIn('name', $tagTitles)
                        ->pluck('id')
                        ->toArray();

                    // Если каких-то тегов ещё нет — создаём их
                    $newTagIds = [];
                    foreach ($tagTitles as $slug) {
                        if (! in_array($slug, Tag::whereIn('id', $tagIds)->pluck('name')->toArray())) {
                            $new = Tag::firstOrCreate(['name' => $slug]);
                            $newTagIds[] = $new->id;
                        }
                    }

                    $allTagIds = array_merge($tagIds, $newTagIds);
                    $art->tags()->sync($allTagIds);

                    // Добавляем арт в коллекцию пользователя
                    $art->collections()->attach($collection->id);
                }
            });
    }
}
