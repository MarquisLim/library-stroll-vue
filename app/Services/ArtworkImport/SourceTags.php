<?php

namespace App\Services\ArtworkImport;

final class SourceTags
{
    // Exact vocabulary aliases only. Search queries never become artwork tags.
    private const RUSSIAN = [
        'cat' => 'кот', 'cats' => 'кот', 'kittens' => 'котёнок',
        'dog' => 'собака', 'dogs' => 'собака', 'birds' => 'птицы',
        'horse' => 'лошадь', 'horses' => 'лошади', 'animals' => 'животные',
        'landscape' => 'пейзаж', 'landscapes' => 'пейзаж',
        'flowers' => 'цветы', 'trees' => 'деревья', 'mountains' => 'горы',
        'sea' => 'море', 'seascapes' => 'морской пейзаж', 'rivers' => 'реки',
        'portrait' => 'портрет', 'portraits' => 'портрет',
        'still life' => 'натюрморт', 'still lifes' => 'натюрморт',
        'interiors' => 'интерьер', 'domestic scenes' => 'бытовая сцена',
        'architecture' => 'архитектура', 'cityscapes' => 'городской пейзаж',
        'painting' => 'живопись', 'paintings' => 'живопись',
        'drawing' => 'рисунок', 'drawings' => 'рисунок',
        'print' => 'гравюра', 'prints' => 'гравюра',
        'photograph' => 'фотография', 'photographs' => 'фотография',
        'photography' => 'фотография', 'sculpture' => 'скульптура',
        'sculptures' => 'скульптура', 'watercolor' => 'акварель',
        'watercolors' => 'акварель', 'oil on canvas' => 'масло на холсте',
        'impressionism' => 'импрессионизм', 'post-impressionism' => 'постимпрессионизм',
        'abstract' => 'абстракция', 'abstract art' => 'абстракция',
        'japanese' => 'японское искусство', 'woodblock print' => 'ксилография',
        'window' => 'окно', 'windows' => 'окна', 'beds' => 'кровати',
        'bedrooms' => 'спальня', 'chair' => 'стул', 'chairs' => 'стулья',
    ];

    public function normalize(array $terms): array
    {
        $tags = [];
        foreach ($terms as $term) {
            if (! is_string($term)) {
                continue;
            }
            $name = mb_strtolower(trim(preg_replace('/\s+/u', ' ', strip_tags($term)) ?? ''));
            $name = ltrim($name, '# ');
            if ($name === '' || mb_strlen($name) > 100) {
                continue;
            }
            $tags[$name] = $name;
            if (isset(self::RUSSIAN[$name])) {
                $tags[self::RUSSIAN[$name]] = self::RUSSIAN[$name];
            }
        }

        return array_values($tags);
    }
}
