<?php
// app/Services/ComplaintSubjectInfo.php
namespace App\Services;

use App\Models\Artwork;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Возвращает title + url для любого complaintable.
 */
class ComplaintSubjectInfo
{
    public static function for(object $subject): array
    {
        return match (true) {
            $subject instanceof Artwork => [
                'type'  => 'artwork',
                'title' => $subject->title ?: 'Без названия',
                'url'   => route('artworks.show', $subject->id),
            ],

            $subject instanceof Comment => [
                'type'  => 'comment',
                'title' => Str::limit($subject->text, 40),
                'url'   => route('artworks.show', [
                    $subject->commentable_id,
                    '#comment-'.$subject->id,
                ]),
            ],

            $subject instanceof User    => [
                'type'  => 'profile',
                'title' => $subject->name,
                'url'   => route('user.profile.show', $subject->id),
            ],

            default => [
                'type'  => 'unknown',
                'title' => '—',
                'url'   => null,
            ],
        };
    }
}
