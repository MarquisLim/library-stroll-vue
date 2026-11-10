<?php

namespace App\Services\DemoContent;

interface ContentSource
{
    /** @return iterable<ContentItem> */
    public function candidates(string $query, string $type): iterable;
}
