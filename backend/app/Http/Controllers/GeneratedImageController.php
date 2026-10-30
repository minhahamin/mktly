<?php

namespace App\Http\Controllers;

use App\Models\GeneratedImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

/**
 * 생성한 이미지를 내려준다. PNG는 DB에 있고 이 라우트로만 나가므로, 로그인한 사용자의 워크스페이스 이미지만 받을 수 있다
 * (모델의 워크스페이스 스코프가 다른 워크스페이스의 id를 404로 만든다).
 */
class GeneratedImageController extends Controller
{
    public function show(Request $request, int $image): Response
    {
        Gate::authorize('viewAny', GeneratedImage::class);

        $record = GeneratedImage::query()->findOrFail($image);
        $name = 'mktly-'.$record->kind.'-'.Str::substr($record->set_uuid, 0, 8).'-'.($record->slide_index + 1).'.png';

        return response($record->data, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function zip(string $set): Response
    {
        Gate::authorize('viewAny', GeneratedImage::class);

        $images = GeneratedImage::query()->where('set_uuid', $set)->orderBy('slide_index')->get();
        abort_if($images->isEmpty(), 404);

        $path = tempnam(sys_get_temp_dir(), 'mktly');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($images as $image) {
            $zip->addFromString(($image->slide_index + 1).'.png', $image->data);
        }

        $zip->close();

        return response()->download($path, 'mktly-'.$images->first()->kind.'-'.Str::substr($set, 0, 8).'.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }
}
