<?php

namespace App\Providers;

use App\Models\Artwork;
use App\Models\Conversation;
use App\Policies\ArtworkPolicy;
use App\Policies\ConversationPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Artwork::class => ArtworkPolicy::class,
        Conversation::class => ConversationPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
