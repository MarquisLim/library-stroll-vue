<?php

namespace App\Services\Search;

use App\Services\AiService\AssistantIntentClassifier;
use Illuminate\Support\Str;

class AssistantIntentRouter
{
    private const GREETING = 'привет(?:ствую)?|здравствуй(?:те)?|доброе утро|добрый (?:день|вечер)|hi|hello|hey|good (?:morning|afternoon|evening)';

    private const REQUEST = 'найди(?:те)?|покажи(?:те)?|подбери(?:те)?|ищу|хочу|мне нуж(?:ен|на|но|ны)|find(?: me)?|show(?: me)?|search for|look for|i want|i would like';

    public function __construct(private AssistantIntentClassifier $classifier, private AssistantTurnContext $context) {}

    public function route(string $query): array
    {
        $text = preg_replace('~[.!?。！？…]+\z~u', '', Str::squish($query));
        $request = preg_replace('~\A(?:'.self::GREETING.')\b[\s,!.—-]+~iu', '', $text);
        $subject = preg_replace('~\A(?:(?:пожалуйста|please)[,\s]+)?(?:'.self::REQUEST.')\s+~iu', '', $request);
        $local = $this->obvious($text, $subject);
        if ($local !== null) {
            return ['intent' => $local, 'method' => 'rule', 'fallback_reason' => null, 'requests' => 0, 'llm_calls' => 0, 'tokens' => []];
        }

        $classified = $this->classifier->classify($query);
        if ($classified['intent'] !== null) {
            return [...$classified, 'method' => 'llm'];
        }

        $explicitSearch = $subject !== $request && ! $this->context->dependsOnHistory($query) && $this->visualSubject($subject);

        return [...$classified, 'intent' => $explicitSearch ? AssistantIntent::SearchMedia : AssistantIntent::Clarify, 'method' => 'fallback'];
    }

    private function obvious(string $text, string $subject): ?AssistantIntent
    {
        if (preg_match('~<\|im_(?:start|end)\|>|\bignore (?:all |the )?(?:previous|prior|system) instructions\b'
            .'|\bигнорируй (?:все )?(?:предыдущие|системные) инструкции\b~iu', $text)) {
            return AssistantIntent::OutOfScope;
        }
        if (preg_match('~\A(?:'.self::GREETING.')\z~iu', $text)) {
            return AssistantIntent::Greeting;
        }
        if (preg_match('~\A(?:(?:a|an|some|the)\s+)?(?:(?:short|long|playable|коротк\pL*|длинн\pL*)\s+)?'
            .'(?:видео(?:ролик\pL*)?|ролик\pL*|аудио(?:запис\pL*)?|подкаст\pL*|videos?|clips?|audio|podcasts?|mp3)\b'
            .'(?!\s+(?:camera|game|editing|player|card|icon|poster|still|thumbnail)\b)~iu', $subject)) {
            return AssistantIntent::UnsupportedMedia;
        }
        if (preg_match('~\A(?:|покажи(?:те)?|найди(?:те)?|show(?: me)?|find(?: me)?)\z~iu', $text)) {
            return AssistantIntent::Clarify;
        }

        return null;
    }

    private function visualSubject(string $subject): bool
    {
        return (bool) preg_match('~\A(?:(?:a|an|some|the)\s+)?(?:картинк\pL*|фотограф\pL*|фото|изображени\pL*|рисун\pL*|иллюстраци\pL*'
            .'|портрет\pL*|пейзаж\pL*|pictures?|photos?|photographs?|images?|illustrations?|drawings?|portraits?)'
            .'\s+(?:(?:of|с)\s+)?(?!(?:it|this|anything|something|please|это|что-нибудь|пожалуйста)\z)\S~iu', $subject)
            || (bool) preg_match('~\A(?:[\pL-]+\s+){0,3}(?:птиц\pL*|попуга\pL*|тарел\pL*|цвет\pL*|дракон\pL*|костюм\pL*|видеокамер\pL*'
                .'|birds?|parrots?|plates?|flowers?|dragons?|suits?|cameras?)\z~iu', $subject);
    }
}
