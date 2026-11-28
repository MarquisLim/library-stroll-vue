<?php

namespace App\Services\Search;

final class AssistantInformationalReply
{
    private const MESSAGES = [
        'greeting' => [
            'ru' => 'Привет! Опишите изображение, которое хотите найти в каталоге.',
            'en' => 'Hello! Describe the image you would like to find in the catalog.',
        ],
        'capabilities' => [
            'ru' => 'Я ищу изображения в каталоге по описанию и могу показать подтверждённые сведения о найденных работах.',
            'en' => 'I search the catalog for images by description and can show verified details about the results.',
        ],
        'help' => [
            'ru' => 'Напишите, что должно быть на изображении. Можно указать объект, цвет или стиль. Например, «две птицы на ветке».',
            'en' => 'Describe what should be in the image. You can include a subject, color, or style, such as “two birds on a branch.”',
        ],
        'unsupported_media' => [
            'ru' => 'Здесь доступен поиск изображений. Если подойдёт кадр или иллюстрация, опишите, что должно быть на ней.',
            'en' => 'This assistant searches for images. If a still image would work, describe what you would like it to show.',
        ],
        'organize_media' => [
            'ru' => 'Создание коллекций и назначение тегов через ассистента пока недоступны. Я могу помочь найти работы для подборки.',
            'en' => 'Creating collections and assigning tags through the assistant is unavailable. I can help find works for a collection.',
        ],
        'out_of_scope' => [
            'ru' => 'Я помогаю искать изображения в каталоге. Опишите, что хотите увидеть на изображении.',
            'en' => 'I help find images in the catalog. Describe what you would like to see in an image.',
        ],
    ];

    public static function code(AssistantIntent $intent, string $query): string
    {
        $locale = preg_match('/\p{Cyrillic}/u', $query) ? 'ru' : 'en';

        return $locale.'.'.$intent->value;
    }

    public static function restore(?string $code): array
    {
        if ($code === null || substr_count($code, '.') !== 1) {
            return [];
        }
        [$locale, $intent] = explode('.', $code, 2);
        $text = self::MESSAGES[$intent][$locale] ?? null;

        return $text === null ? [] : ['reply' => ['code' => $code, 'text' => $text]];
    }
}
