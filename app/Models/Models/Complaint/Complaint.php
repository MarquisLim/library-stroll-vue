<?php

namespace App\Models\Models\Complaint;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Complaint extends Model
{
    protected $fillable = [
        'user_id',
        'complaint_type_id',
        'details',
        'status',
        'moderator_note',
        'moderator_id',
        'reviewed_at',
        'complaintable_id',
        'complaintable_type',
    ];

    public const STATUSES = [
        'pending'  => 'Ожидает модерации',
        'approved' => 'Одобрена',
        'rejected' => 'Отклонена',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function type()
    {
        return $this->belongsTo(ComplaintType::class, 'complaint_type_id');
    }

    public function complaintable()
    {
        return $this->morphTo()->withoutGlobalScopes();
    }

    public function moderator()
    {
        return $this->belongsTo(User::class, 'moderator_id');
    }
}
