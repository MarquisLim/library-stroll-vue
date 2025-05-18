<?php

namespace Database\Seeders;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Faker\Factory as Faker;
use Illuminate\Support\Str;

class CatsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();

        $tagMap = [
            'cats'   => ['котики', 'мимими', 'пушистик'],
            'dogs'   => ['собаки', 'друг', 'верность'],
            'random' => ['пейзаж', 'фото', 'иллюстрация', 'современное', 'геометрия'],
        ];

        collect($tagMap)->flatten()->unique()->each(fn($tag) => Tag::firstOrCreate(['name' => $tag]));

        $imageSources = [
            [
                'name' => 'cats',
                'tags' => $tagMap['cats'],
                'url'  => fn() => Http::get('https://api.thecatapi.com/v1/images/search')->json()[0]['url'] ?? null,
            ],
            [
                'name' => 'dogs',
                'tags' => $tagMap['dogs'],
                'url'  => fn() => Http::get('https://dog.ceo/api/breeds/image/random')->json()['message'] ?? null,
            ],
            [
                'name' => 'random',
                'tags' => $tagMap['random'],
                'url'  => fn() => 'https://picsum.photos/seed/' . Str::random(8) . '/640/480',
            ],
        ];

        User::factory(20)
            ->create(['password' => bcrypt('123456zaza')])
            ->each(function ($user) use ($faker, $imageSources) {
                // Аватарка
                $avatarUrl = 'https://i.pravatar.cc/300?img=' . rand(1, 70);
                $res = Http::get($avatarUrl);
                if ($res->ok() && str_starts_with($res->header('Content-Type'), 'image')) {
                    $fileName = "profile-photos/{$user->id}.jpg";
                    Storage::disk('public')->put($fileName, $res->body());
                    $user->forceFill(['profile_photo_path' => $fileName])->save();
                }

                $collection = Collection::create([
                    'user_id'    => $user->id,
                    'name'       => 'Моя галерея',
                    'is_private' => false,
                ]);

                for ($i = 0; $i < rand(5, 10); $i++) {
                    $source = $faker->randomElement($imageSources);
                    $url = $source['url']();

                    if (!$url) continue;

                    $head = Http::withHeaders(['Accept' => '*/*'])->head($url);
                    if (! $head->ok() || ! str_starts_with($head->header('Content-Type'), 'image')) continue;

                    $response = Http::get($url);
                    if (! $response->ok() || ! str_starts_with($response->header('Content-Type'), 'image')) continue;

                    $art = new Artwork([
                        'user_id'        => $user->id,
                        'title'          => $faker->sentence(rand(2, 5)),
                        'description'    => $faker->paragraph(rand(1, 2)),
                        'is_published'   => true,
                        'allow_download' => $faker->boolean(80),
                        'allow_comments' => $faker->boolean(90),
                        'views_count'    => rand(10, 500),
                    ]);
                    $art->save();

                    try {
                        $art->addMediaFromUrl($url)->toMediaCollection('artworks');
                    } catch (\Exception $e) {
                        $art->delete();
                        continue;
                    }

                    $tags = collect($source['tags'])->random(rand(1, count($source['tags'])));
                    $tagIds = Tag::whereIn('name', $tags)->pluck('id');
                    $art->tags()->sync($tagIds);
                    $art->collections()->attach($collection->id);
                }
            });
    }

}
