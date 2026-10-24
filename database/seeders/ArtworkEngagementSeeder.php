<?php

namespace Database\Seeders;

use App\Models\Artwork;
use App\Models\Comment;
use App\Models\Like;
use App\Models\User;
use Illuminate\Database\Seeder;

class ArtworkEngagementSeeder extends Seeder
{
    public function run(): void
    {
        mt_srand(99);

        $users = User::withoutGlobalScopes()->get();
        $artworks = Artwork::withoutGlobalScopes()
            ->where('is_published', true)
            ->where('is_private', false)
            ->get();

        if ($users->isEmpty() || $artworks->isEmpty()) {
            $this->command->warn('No users or published artworks found — skipping engagement.');
            return;
        }

        if (Like::count() > 0) {
            $this->command->info('ArtworkEngagementSeeder: likes already exist, skipping.');
        } else {
            $this->seedLikes($users, $artworks);
        }

        if (Comment::withoutGlobalScopes()->where('commentable_type', Artwork::class)->exists()) {
            $this->command->info('ArtworkEngagementSeeder: comments already exist, skipping.');
        } else {
            $this->seedComments($users, $artworks);
        }

        mt_srand();
    }

    private function seedLikes($users, $artworks): void
    {
        $created = 0;

        foreach ($artworks as $art) {
            $likeCount = $this->likesForViews($art->views_count);

            $candidates = $users->where('id', '!=', $art->user_id)->shuffle();
            $likers = $candidates->take($likeCount);

            foreach ($likers as $user) {
                $exists = Like::where('user_id', $user->id)
                    ->where('artwork_id', $art->id)
                    ->exists();

                if (! $exists) {
                    Like::create([
                        'user_id'    => $user->id,
                        'artwork_id' => $art->id,
                    ]);
                    $created++;
                }
            }
        }

        $this->command->info("ArtworkEngagementSeeder: {$created} likes.");
    }

    private function seedComments($users, $artworks): void
    {
        $commentTexts = [
            'Потрясающая работа!',
            'Очень красиво, спасибо за публикацию.',
            'Wow, incredible detail!',
            'Какой свет! 🔥',
            'Love the composition.',
            'Хочу так же научиться рисовать.',
            'Класс, добавил в коллекцию!',
            'Amazing work, keep it up!',
            'Атмосфера просто невероятная.',
            'Great use of color!',
            'Это прям как живое.',
            'Cool style!',
            'Очень атмосферно 👏',
            'Is this digital or traditional?',
            'Сколько времени ушло на эту работу?',
            'Beautiful shot!',
            'Шедевр!',
            'Nice one!',
        ];

        $created = 0;

        $commentableArtworks = $artworks->where('allow_comments', true);

        foreach ($commentableArtworks as $art) {
            $commentCount = $this->commentsForViews($art->views_count);
            if ($commentCount === 0) {
                continue;
            }

            $commenters = $users->shuffle()->take($commentCount);

            foreach ($commenters as $commenter) {
                $text = $commentTexts[mt_rand(0, count($commentTexts) - 1)];

                Comment::create([
                    'user_id'          => $commenter->id,
                    'commentable_id'   => $art->id,
                    'commentable_type' => Artwork::class,
                    'text'             => $text,
                ]);
                $created++;
            }

            // ~20% chance of a reply on this artwork's first comment
            if (mt_rand(1, 5) === 1) {
                $firstComment = Comment::where('commentable_id', $art->id)
                    ->where('commentable_type', Artwork::class)
                    ->whereNull('parent_id')
                    ->first();

                if ($firstComment) {
                    $replier = $users->where('id', '!=', $firstComment->user_id)->random();
                    $replyTexts = [
                        'Согласен!',
                        'Exactly!',
                        'Спасибо, очень приятно!',
                        'Thanks! Took me about 3 hours.',
                        '+1',
                    ];

                    Comment::create([
                        'user_id'          => $replier->id,
                        'commentable_id'   => $art->id,
                        'commentable_type' => Artwork::class,
                        'parent_id'        => $firstComment->id,
                        'text'             => $replyTexts[mt_rand(0, count($replyTexts) - 1)],
                    ]);
                    $created++;
                }
            }
        }

        $this->command->info("ArtworkEngagementSeeder: {$created} comments.");
    }

    private function likesForViews(int $views): int
    {
        if ($views > 1500) return mt_rand(5, min(10, $views));
        if ($views > 800)  return mt_rand(3, 7);
        if ($views > 300)  return mt_rand(1, 5);
        if ($views > 50)   return mt_rand(0, 3);

        return mt_rand(0, 1);
    }

    private function commentsForViews(int $views): int
    {
        if ($views > 1500) return mt_rand(2, 5);
        if ($views > 800)  return mt_rand(1, 3);
        if ($views > 300)  return mt_rand(0, 2);

        return mt_rand(0, 1);
    }
}
