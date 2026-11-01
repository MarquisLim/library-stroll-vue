<?php

namespace Tests\Feature;

use App\Http\Middleware\UseRequestStorageUrl;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class StorageOriginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://localhost:8088', 'filesystems.disks.public.url' => 'http://localhost:8088/storage']);
        Storage::forgetDisk('public');
        Route::middleware('web')->get('/_test/storage-origin', function () {
            $user = (new User(['name' => 'Example']))->forceFill(['profile_photo_path' => 'profile-photos/avatar.jpg']);
            $media = new Media(['disk' => 'public', 'file_name' => 'artwork.jpg']);
            $media->id = 14;

            return response()->json(['file' => Storage::disk('public')->url('14/artwork.jpg'), 'avatar' => $user->profile_photo_url, 'media' => $media->getUrl()]);
        });
    }

    public function test_files_media_and_header_avatars_use_the_browser_origin_on_both_hosts(): void
    {
        // Resolve the adapter early to ensure the middleware also handles cached disks.
        $this->assertSame('http://localhost:8088/storage/14/artwork.jpg', Storage::disk('public')->url('14/artwork.jpg'));
        foreach (['127.0.0.1', 'localhost'] as $host) {
            $this->getJson("http://{$host}:8088/_test/storage-origin")->assertOk()
                ->assertJsonPath('file', '/storage/14/artwork.jpg')
                ->assertJsonPath('avatar', '/storage/profile-photos/avatar.jpg')
                ->assertJsonPath('media', '/storage/14/artwork.jpg');
        }
        $this->assertSame('http://localhost:8088/storage/14/artwork.jpg', Storage::disk('public')->url('14/artwork.jpg'));
    }

    public function test_explicit_cdn_storage_url_is_preserved(): void
    {
        config(['filesystems.disks.public.url' => 'https://cdn.example.test/uploads']);
        Storage::forgetDisk('public');
        $this->getJson('http://127.0.0.1:8088/_test/storage-origin')->assertOk()
            ->assertJsonPath('file', 'https://cdn.example.test/uploads/14/artwork.jpg')
            ->assertJsonPath('avatar', 'https://cdn.example.test/uploads/profile-photos/avatar.jpg');
    }

    public function test_existing_disk_root_and_files_are_preserved(): void
    {
        Storage::fake('public');
        $disk = Storage::disk('public');
        $disk->put('attachments/example.txt', 'example content');
        app(UseRequestStorageUrl::class)->handle(Request::create('http://127.0.0.1:8088'), function () {
            $this->assertSame('example content', Storage::disk('public')->get('attachments/example.txt'));
            $this->assertSame('/storage/attachments/example.txt', Storage::disk('public')->url('attachments/example.txt'));

            return response('ok');
        });
        $this->assertSame($disk, Storage::disk('public'));
    }

    public function test_request_storage_settings_are_restored_even_when_the_request_throws(): void
    {
        try {
            app(UseRequestStorageUrl::class)->handle(Request::create('http://127.0.0.1:8088'), function () {
                $this->assertSame('/storage', config('filesystems.disks.public.url'));
                throw new \RuntimeException('request failed');
            });
            $this->fail('Expected the request failure.');
        } catch (\RuntimeException $error) {
            $this->assertSame('request failed', $error->getMessage());
        }
        $this->assertSame('http://localhost:8088/storage', config('filesystems.disks.public.url'));
    }
}
