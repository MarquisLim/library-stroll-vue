<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Models\Complaint\Complaint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Jetstream\HasProfilePhoto;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasProfilePhoto;

    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory;
    use HasRoles;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'description',
        'is_blocked',
        'views_count'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_recovery_codes',
        'two_factor_secret',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'profile_photo_url',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    protected static function booted()
    {
        static::addGlobalScope('not_blocked', function (Builder $b) {
            $b->where('is_blocked', false);
        });
    }

    public function artworks()
    {
        return $this->hasMany(Artwork::class);
    }

    public function collections()
    {
        return $this->hasMany(Collection::class);
    }

    public function preferences()
    {
        return $this->hasMany(UserPreference::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function likes()
    {
        return $this->hasMany(Like::class);
    }

    public function replies()
    {
        return $this->hasMany(User::class, 'parent_id');
    }

    public function conversations()
    {
        return $this->belongsToMany(Conversation::class)
            ->withPivot(['joined_at', 'role', 'last_read_at']);
    }

    public function conversationsWithPartner()
    {
        return $this->conversations()->with('users')
            ->get()
            ->map(function ($conv) {
                $conv->partner = $conv->users->firstWhere('id', '!=', $this->id);
                return $conv;
            });
    }

    public function complaints()
    {
        return $this->morphMany(Complaint::class, 'complaintable');
    }

    public function blockedUsers()
    {
        return $this->belongsToMany(User::class, 'user_blocks', 'blocker_id', 'blocked_id');
    }

    public function hasBlocked(User $user): bool
    {
        return $this->blockedUsers()->where('blocked_id', $user->id)->exists();
    }

    public function block(): void
    {
        $this->is_blocked = true;
        $this->save();
    }

    public function unblock(): void
    {
        $this->is_blocked = false;
        $this->save();
    }
}
