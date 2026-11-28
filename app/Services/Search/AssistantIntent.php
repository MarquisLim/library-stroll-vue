<?php

namespace App\Services\Search;

enum AssistantIntent: string
{
    case SearchMedia = 'search_media';
    case Greeting = 'greeting';
    case Capabilities = 'capabilities';
    case Help = 'help';
    case Followup = 'followup';
    case MediaQuestion = 'media_question';
    case UnsupportedMedia = 'unsupported_media';
    case OrganizeMedia = 'organize_media';
    case OutOfScope = 'out_of_scope';
    case Clarify = 'clarify';

    public function usesContext(): bool
    {
        return $this === self::Followup || $this === self::MediaQuestion;
    }
}
