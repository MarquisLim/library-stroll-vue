<?php

namespace App\Http\Controllers\Moderation;

use App\Http\Controllers\Controller;
use App\Models\Artwork;
use App\Models\Comment;
use App\Models\Models\Complaint\Complaint;
use App\Models\Models\Complaint\ComplaintType;
use App\Models\User;
use App\Notifications\ComplaintRejected;
use App\Notifications\ContentBlocked;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ComplaintController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'status'  => 'nullable|in:pending,approved,rejected',
            'type'    => 'nullable|exists:complaint_types,slug',
            'subject' => 'nullable|in:artwork,comment,user',
            'page'    => 'nullable|integer',
        ]);

        $q = Complaint::with(['user','type','complaintable','moderator'])
            ->orderBy('created_at','desc');

        if ($filters['status'] ?? false) {
            $q->where('status', $filters['status']);
        }
        if ($filters['type'] ?? false) {
            $q->whereHas('type', fn($q2)=> $q2->where('slug',$filters['type']));
        }
        if ($filters['subject'] ?? false) {
            $map = [
                'artwork' => Artwork::class,
                'comment' => Comment::class,
                'user'    => User::class,
            ];
            $q->where('complaintable_type', $map[$filters['subject']]);
        }

        $complaints = $q->paginate(12)->withQueryString();
        $types      = ComplaintType::select('slug','name')->get();

        return Inertia::render('Moderation/ComplaintsManager', [
            'complaints'=> $complaints,
            'types'      => $types,
            'filters'    => $filters,
            'statuses'   => Complaint::STATUSES,
        ]);
    }


    public function show(Complaint $complaint)
    {
        $complaint->load(['user', 'type', 'complaintable', 'moderator']);
        return Inertia::render('Moderation/ComplaintShow', [
            'complaint' => $complaint,
            'statuses'  => Complaint::STATUSES,
        ]);
    }

    public function review(Request $request, Complaint $complaint)
    {
        $data = $request->validate([
            'status'         => 'required|in:'.implode(',', array_keys(Complaint::STATUSES)),
            'moderator_note' => 'nullable|string|max:2000',
        ]);

        $complaint->update([
            'status'         => $data['status'],
            'moderator_note' => $data['moderator_note'] ?? null,
            'moderator_id'   => Auth::id(),
            'reviewed_at'    => now(),
        ]);

        $subject = $complaint->complaintable;
        $owner   = $subject instanceof User ? $subject : $subject->user;

        if ($data['status'] === 'approved') {
            optional($subject)->block();
            $owner->notify(new ContentBlocked($complaint, $request->user()));
        } else {
            optional($subject)->unblock();
            $complaint->user->notify(
                new ComplaintRejected($complaint, $request->user())
            );
        }

        return back()->with('success', 'Жалоба обработана');
    }

}
