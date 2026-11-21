<?php

namespace App\Http\Controllers\Moderation;

use App\Http\Controllers\Controller;
use App\Models\Artwork;
use App\Models\Comment;
use App\Models\Models\Complaint\Complaint;
use App\Models\Models\Complaint\ComplaintType;
use App\Models\User;
use App\Notifications\ComplaintApproved;
use App\Notifications\ComplaintRejected;
use App\Notifications\ContentBlocked;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        [$complaint, $owner] = DB::transaction(function () use ($complaint, $data) {
            $subject = $complaint->complaintable()->lockForUpdate()->firstOrFail();
            $complaint = Complaint::lockForUpdate()->findOrFail($complaint->id);
            $owner = $subject instanceof User ? $subject : $subject->user;
            $complaint->update([
                'status'         => $data['status'],
                'moderator_note' => $data['moderator_note'] ?? null,
                'moderator_id'   => Auth::id(),
                'reviewed_at'    => now(),
            ]);
            if ($data['status'] === 'approved') {
                $subject->block();
            } else {
                $subject->unblock();
            }

            return [$complaint, $owner];
        });

        if ($data['status'] === 'approved') {
            $owner?->notify(new ContentBlocked($complaint, $request->user()));
            $complaint->user->notify(new ComplaintApproved($complaint, $request->user()));
        } else {
            $complaint->user->notify(
                new ComplaintRejected($complaint, $request->user())
            );
        }

        return back()->with('success', 'Жалоба обработана');
    }

}
