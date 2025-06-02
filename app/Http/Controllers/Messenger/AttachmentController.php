<?php

namespace App\Http\Controllers\Messenger;

use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use Illuminate\Http\Request;

class AttachmentController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:20480', // до 20 МБ
        ]);

        $file = $request->file('file');
        $path = $file->store('attachments', 'public');

        $att = MessageAttachment::create([
            'disk' => 'public',
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        return [
            'id'  => $att->id,
            'url' => $att->url(),
            'mime'=> $att->mime,
            'size'=> $att->size,
        ];
    }
}
