<?php

namespace App\Http\Controllers\Front;

use App\Http\Controllers\Controller;
use App\Models\Content\Reference\ReferenceProject;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReferenceProjectController extends Controller
{
    public function index(Request $request): View
    {
        $locales = array_unique([app()->getLocale(), (string) config('app.locale', 'hr')]);
        $projects = ReferenceProject::query()->published()
            ->whereHas('translations', fn ($query) => $query->whereIn('locale', $locales))
            ->with(['translations' => fn ($query) => $query->whereIn('locale', $locales), 'media'])
            ->orderBy('sort_order')->orderByDesc('completed_year')->orderByDesc('id')
            ->paginate(12)->withQueryString();

        return view('front.desktop.references.index', compact('projects'));
    }

    public function show(Request $request, string $slug): View
    {
        $locales = array_unique([app()->getLocale(), (string) config('app.locale', 'hr')]);
        $project = ReferenceProject::query()->published()
            ->whereHas('translations', fn ($query) => $query->whereIn('locale', $locales)->where('slug', $slug))
            ->with(['translations' => fn ($query) => $query->whereIn('locale', $locales), 'media'])
            ->firstOrFail();
        $translation = $project->localizedTranslation();
        $photos = $project->getMedia(ReferenceProject::GALLERY)->sortBy('order_column')->values();

        return view('front.desktop.references.show', compact('project', 'translation', 'photos'));
    }

    public function photo(Request $request, ReferenceProject $project, Media $photo, string $size = 'card'): BinaryFileResponse
    {
        abort_unless(in_array($size, ['card', 'large', 'original'], true), 404);
        abort_unless($photo->model_type === $project->getMorphClass()
            && (int) $photo->model_id === (int) $project->id
            && $photo->collection_name === ReferenceProject::GALLERY, 404);

        $user = $request->user();
        $mayPreview = $user && ($user->account_type !== 'staff' || $user->admin_login_enabled)
            && ($user->isA('superadmin') || ($user->can('admin.access') && $user->can('content.references.view')));
        abort_unless($mayPreview || ReferenceProject::query()->published()->whereKey($project->id)->exists(), 404);

        $path = $photo->getPath();
        if ($size !== 'original') {
            foreach (['reference_'.$size.'_webp', 'reference_'.$size] as $conversion) {
                if ($photo->hasGeneratedConversion($conversion) && is_file($photo->getPath($conversion))) {
                    $path = $photo->getPath($conversion);
                    break;
                }
            }
        }
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type' => mime_content_type($path) ?: 'application/octet-stream',
        ]);
    }
}
