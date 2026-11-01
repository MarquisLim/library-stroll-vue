<?php

namespace App\Http\Controllers\Messenger;

use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttachmentController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:20480', // до 20 МБ
        ]);

        $file = $request->file('file');
        $path = $file->store('attachments', 'local');

        $att = MessageAttachment::create([
            'disk' => 'local',
            'user_id' => $request->user()->id,
            'path' => $path,
            'mime' => $file->getMimeType() ?? 'application/octet-stream',
            'size' => $file->getSize(),
        ]);

        return [
            'id' => $att->id,
            'url' => $att->url(),
            'mime' => $att->mime,
            'size' => $att->size,
        ];
    }

    public function show(Request $request, MessageAttachment $attachment)
    {
        if ($attachment->message_id !== null) {
            $this->authorize('view', $attachment->message->conversation);
        } else {
            abort_unless($attachment->user_id === $request->user()->id, 403);
        }

        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        // Never render uploaded HTML or SVG as a document on the application's origin.
        $inline = in_array($attachment->mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'video/mp4', 'video/webm', 'audio/mpeg', 'audio/ogg'], true);

        $headers = [
            'Content-Type' => $attachment->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];
        $disposition = $inline ? 'inline' : 'attachment';

        if (config('filesystems.disks.'.$attachment->disk.'.driver') === 'local') {
            // BinaryFileResponse preserves byte ranges used by video seeking.
            return response()->file(Storage::disk($attachment->disk)->path($attachment->path), $headers)
                ->setContentDisposition($disposition, basename($attachment->path));
        }

        return Storage::disk($attachment->disk)->response($attachment->path, null, $headers, $disposition);
    }

    public function legacy(Request $request, string $path)
    {
        $attachment = MessageAttachment::where('disk', 'public')
            ->where('path', 'attachments/'.$path)
            ->firstOrFail();

        return $this->show($request, $attachment);
    }
}
