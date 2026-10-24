<?php

namespace Database\Seeders;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;

class CollectionSeeder extends Seeder
{
    public function run(): void
    {
        $collections = [
            [
                'user_email' => 'kotofey@libstroll.test',
                'collections' => [
                    ['name' => 'Лучшие котики', 'is_private' => false, 'own_arts' => true, 'tag_filter' => ['котики']],
                ],
            ],
            [
                'user_email' => 'nature@libstroll.test',
                'collections' => [
                    ['name' => 'Top Landscapes', 'is_private' => false, 'own_arts' => true, 'tag_filter' => ['пейзаж']],
                    ['name' => 'Saved for Later', 'is_private' => true, 'tag_filter' => ['город', 'архитектура']],
                ],
            ],
            [
                'user_email' => 'turbo@libstroll.test',
                'collections' => [
                    ['name' => 'Supercars', 'is_private' => false, 'own_arts' => true, 'tag_filter' => ['спорткар']],
                    ['name' => 'Cool Nature Shots', 'is_private' => false, 'tag_filter' => ['природа', 'пейзаж']],
                ],
            ],
            [
                'user_email' => 'dragon@libstroll.test',
                'collections' => [
                    ['name' => 'Fantasy Portfolio', 'is_private' => false, 'own_arts' => true, 'tag_filter' => ['фантастика', 'фэнтези']],
                ],
            ],
            [
                'user_email' => 'pixelwitch@libstroll.test',
                'collections' => [
                    ['name' => 'Concept Art', 'is_private' => false, 'own_arts' => true, 'tag_filter' => ['концепт-арт']],
                    ['name' => 'Inspiration', 'is_private' => false, 'tag_filter' => ['иллюстрация', 'цифровая живопись']],
                ],
            ],
            [
                'user_email' => 'noosfera@libstroll.test',
                'collections' => [
                    ['name' => 'Мои абстракции', 'is_private' => false, 'own_arts' => true, 'tag_filter' => ['абстракция']],
                ],
            ],
            [
                'user_email' => 'admin@libstroll.test',
                'collections' => [
                    ['name' => 'Подборка редакции', 'is_private' => false, 'tag_filter' => ['пейзаж', 'иллюстрация', 'котики']],
                ],
            ],
        ];

        mt_srand(77);

        foreach ($collections as $group) {
            $user = User::withoutGlobalScopes()->where('email', $group['user_email'])->first();
            if (! $user) {
                continue;
            }

            foreach ($group['collections'] as $colDef) {
                $col = Collection::firstOrCreate(
                    ['user_id' => $user->id, 'name' => $colDef['name']],
                    ['is_private' => $colDef['is_private']]
                );

                if ($col->artworks()->count() > 0) {
                    continue;
                }

                $ownOnly = $colDef['own_arts'] ?? false;
                $tagNames = $colDef['tag_filter'] ?? [];

                $query = Artwork::withoutGlobalScopes()
                    ->where('is_published', true)
                    ->where('is_private', false);

                if ($ownOnly) {
                    $query->where('user_id', $user->id);
                } else {
                    $query->where('user_id', '!=', $user->id);
                }

                if (! empty($tagNames)) {
                    $tagIds = Tag::whereIn('name', $tagNames)->pluck('id');
                    if ($tagIds->isNotEmpty()) {
                        $query->whereHas('tags', fn ($q) => $q->whereIn('tags.id', $tagIds));
                    }
                }

                $artworkIds = $query->inRandomOrder()->limit(mt_rand(3, 8))->pluck('id');
                $col->artworks()->syncWithoutDetaching($artworkIds);
            }
        }

        mt_srand();

        $total = Collection::count();
        $this->command->info("CollectionSeeder: {$total} collections.");
    }
}
