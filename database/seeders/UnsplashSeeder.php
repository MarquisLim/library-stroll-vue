<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Collection;
use App\Models\Artwork;
use App\Models\Tag;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Faker\Factory as Faker;
use Illuminate\Support\Str;

class UnsplashSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker     = Faker::create('en_US');
        $accessKey = config('services.unsplash.access_key');

        User::factory(10)
            ->create(['password' => bcrypt('123456zaza')])
            ->each(function (User $user) use ($faker, $accessKey) {
                // Перепишем имя на английское и зададим ник
                $user->forceFill([
                    'name'     => $faker->userName(),
                ])->save();

                // Аватарка из pravatar
                $avatarUrl = 'https://i.pravatar.cc/300?u=' . urlencode($user->email);
                $head      = Http::head($avatarUrl);
                if (
                    $head->ok() &&
                    Str::startsWith($head->header('Content-Type'), 'image')
                ) {
                    $resp = Http::get($avatarUrl);
                    if (
                        $resp->ok() &&
                        Str::startsWith($resp->header('Content-Type'), 'image')
                    ) {
                        $file = "profile-photos/{$user->id}.jpg";
                        Storage::disk('public')->put($file, $resp->body());
                        $user->forceFill(['profile_photo_path' => $file])->save();
                    }
                }

                $collection = Collection::create([
                    'user_id'    => $user->id,
                    'name'       => 'Unsplash Picks',
                    'is_private' => false,
                ]);

                $count = rand(5, 10);
                for ($i = 0; $i < $count; $i++) {
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

                    $head = Http::head($url);
                    if (
                        ! $head->ok() ||
                        ! Str::startsWith($head->header('Content-Type'), 'image')
                    ) {
                        continue;
                    }

                    $titleSource = $data['description'] ?? $data['alt_description'] ?? null;
                    $title = Str::limit(
                        $titleSource
                            ?: $faker->words(rand(2, 4), true),
                        250,
                        '…'
                    );

                    $descSource = $data['alt_description'] ?? null;
                    $description = $descSource
                        ?: $faker->paragraph(rand(1, 3));

                    $art = Artwork::create([
                        'user_id'        => $user->id,
                        'title'          => Str::limit($title, 250, '…'),
                        'description'    => $description,
                        'is_published'   => true,
                        'allow_download' => true,
                        'allow_comments' => true,
                        'views_count'    => rand(0, 1000),
                    ]);

                    try {
                        $art->addMediaFromUrl($url)
                            ->toMediaCollection('artworks');
                    } catch (\Exception $e) {
                        $art->delete();
                        continue;
                    }

                    $tagSlugs = collect($data['tags'] ?? [])
                        ->pluck('title')
                        ->map(fn($t) => Str::slug($t))
                        ->filter()
                        ->unique()
                        ->toArray();

                    $existing = Tag::whereIn('name', $tagSlugs)
                        ->pluck('id', 'name')
                        ->toArray();

                    $newTagIds = [];
                    foreach (array_diff($tagSlugs, array_keys($existing)) as $slug) {
                        $newTagIds[] = Tag::create(['name' => $slug])->id;
                    }

                    $allTagIds = array_merge(array_values($existing), $newTagIds);
                    $art->tags()->sync($allTagIds);

                    $art->collections()->attach($collection->id);
                }
            });
    }
}
