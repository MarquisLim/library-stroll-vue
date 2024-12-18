<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use App\Models\Tag;
use App\Models\Artwork;
use App\Models\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $tagsList = ['котики','искусство','пейзаж','фантастика','авторское','портрет','абстракция','3D','фото'];
        foreach($tagsList as $t){
            Tag::firstOrCreate(['name'=>$t]);
        }

        User::factory(20)->create(['password'=>bcrypt('123456zaza')])->each(function($user){
            // Создаем коллекцию "Все" для каждого пользователя
            $allCollection = Collection::create([
                'user_id' => $user->id,
                'name' => 'Все',
                'is_private' => false
            ]);

            // Загрузим 5-10 работ с котиками
            for($i=0;$i<rand(5,10);$i++){
                $response=Http::get('https://api.thecatapi.com/v1/images/search');
                $url=$response->json()[0]['url']??null;
                if($url){
                    $art=new Artwork();
                    $art->user_id=$user->id;
                    $art->title='Работа '.$i;
                    $art->description='Описание работы '.$i;
                    $art->is_published=true;
                    $art->allow_download=true;
                    $art->allow_comments=true;
                    $art->save();

                    $art->addMediaFromUrl($url)->toMediaCollection('artworks');

                    // Привяжем случайные теги
                    $allTags=Tag::inRandomOrder()->limit(rand(1,5))->pluck('id');
                    $art->tags()->sync($allTags);

                    $art->collections()->attach($allCollection->id);

                    // Просмотры, лайки можно рандомно
                    $art->views_count=rand(10,500);
                    $art->save();
                }
            }
        });
    }
}
