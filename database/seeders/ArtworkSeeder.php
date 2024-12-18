<?php

namespace Database\Seeders;

use App\Models\Artwork;
use App\Models\Collection;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;

class ArtworkSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tagsList = ['искусство', 'пейзаж', 'фантастика', 'авторское', 'портрет', 'абстракция', '3D', 'фото'];
        foreach ($tagsList as $t) {
            Tag::firstOrCreate(['name' => $t]);
        }

        User::factory(20)->create(['password' => bcrypt('123456zaza')])->each(function ($user) {
            // Создаем коллекцию "Все" для каждого пользователя
            $allCollection = Collection::create([
                'user_id' => $user->id,
                'name' => 'Все',
                'is_private' => false
            ]);

            // Загрузим 5-10 случайных изображений с Picsum.photos
            for ($i = 0; $i < rand(5, 10); $i++) {
                // Используем API Picsum.photos
                $imageUrl = "https://picsum.photos/500/300?random=" . rand(1, 1000);

                $art = new Artwork();
                $art->user_id = $user->id;
                $art->title = 'Работа ' . $i;
                $art->description = 'Описание работы ' . $i;
                $art->is_published = true;
                $art->allow_download = true;
                $art->allow_comments = true;
                $art->save();

                $art->addMediaFromUrl($imageUrl)->toMediaCollection('artworks');

                // Привяжем случайные теги
                $allTags = Tag::inRandomOrder()->limit(rand(1, 5))->pluck('id');
                $art->tags()->sync($allTags);

                // Добавляем работу в коллекцию "Все"
                $art->collections()->attach($allCollection->id);

                // Рандомные просмотры, лайки
                $art->views_count = rand(10, 500);
                $art->save();
            }
        });
    }

}
