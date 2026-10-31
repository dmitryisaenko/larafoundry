<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Help\Http\Controllers\Admin;

use Dmitryisaenko\LaraFoundry\ActivityLog\Facades\Activity;
use Dmitryisaenko\LaraFoundry\Help\Exceptions\HelpTranslationRefused;
use Dmitryisaenko\LaraFoundry\Help\Models\HelpArticle;
use Dmitryisaenko\LaraFoundry\Help\Models\HelpImage;
use Dmitryisaenko\LaraFoundry\Help\Models\HelpSection;
use Dmitryisaenko\LaraFoundry\Help\Support\HelpEditor;
use Dmitryisaenko\LaraFoundry\Help\Support\HelpText;
use Dmitryisaenko\LaraFoundry\Help\Support\HelpTranslation;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The help editor in the core's admin section (super-admin only).
 *
 * The page loads once and then talks JSON: the tree is tens of rows, and an editor
 * that reloaded the page on every save would throw away what is typed in the other
 * articles. Every write goes through {@see HelpEditor}, which cleans the text.
 *
 * A translation is ANSWERED, never saved: the operator reads it before it becomes
 * the help, and saving is the separate press it always is.
 */
class HelpController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly HelpEditor $editor) {}

    public function index(HelpTranslation $translation): Response
    {
        $this->authorize('manage', HelpSection::class);

        return Inertia::render('Admin/Help/Editor', [
            'sections' => $this->editor->tree(),
            'pictures' => $this->editor->pictures(),
            'locales' => HelpText::locales(),
            'source' => HelpText::sourceLocale(),
            'can_translate' => $translation->available(),
        ]);
    }

    public function data(): JsonResponse
    {
        $this->authorize('manage', HelpSection::class);

        return response()->json([
            'sections' => $this->editor->tree(),
            'pictures' => $this->editor->pictures(),
        ]);
    }

    // ------------------------------------------------------------------ sections

    public function storeSection(Request $request): JsonResponse
    {
        $this->authorize('manage', HelpSection::class);

        $section = $this->editor->createSection($request->validate(HelpEditor::sectionRules()));
        $this->log('section_created', $section->id);

        return response()->json(['section' => $this->editor->section($section)]);
    }

    public function updateSection(Request $request, HelpSection $section): JsonResponse
    {
        $this->authorize('manage', HelpSection::class);

        $section = $this->editor->updateSection($section, $request->validate(HelpEditor::sectionRules($section)));
        $this->log('section_updated', $section->id);

        return response()->json(['section' => $this->editor->section($section)]);
    }

    public function destroySection(HelpSection $section): JsonResponse
    {
        $this->authorize('manage', HelpSection::class);

        $this->editor->deleteSection($section);
        $this->log('section_deleted', $section->id);

        return response()->json(['ok' => true]);
    }

    public function reorderSections(Request $request): JsonResponse
    {
        $this->authorize('manage', HelpSection::class);

        $this->editor->reorderSections($request->validate(HelpEditor::orderRules(HelpSection::class))['ids']);

        return response()->json(['sections' => $this->editor->tree()]);
    }

    // ------------------------------------------------------------------ articles

    public function storeArticle(Request $request, HelpSection $section): JsonResponse
    {
        $this->authorize('manage', HelpSection::class);

        $article = $this->editor->createArticle($section, $request->validate(HelpEditor::articleRules()));
        $this->log('article_created', $article->id);

        return response()->json(['article' => $this->editor->article($article)]);
    }

    public function updateArticle(Request $request, HelpArticle $article): JsonResponse
    {
        $this->authorize('manage', HelpSection::class);

        $article = $this->editor->updateArticle($article, $request->validate(HelpEditor::articleRules()));
        $this->log('article_updated', $article->id);

        return response()->json(['article' => $this->editor->article($article)]);
    }

    public function destroyArticle(HelpArticle $article): JsonResponse
    {
        $this->authorize('manage', HelpSection::class);

        $this->editor->deleteArticle($article);
        $this->log('article_deleted', $article->id);

        return response()->json(['ok' => true]);
    }

    public function reorderArticles(Request $request, HelpSection $section): JsonResponse
    {
        $this->authorize('manage', HelpSection::class);

        $this->editor->reorderArticles($section, $request->validate(HelpEditor::orderRules(HelpArticle::class))['ids']);

        return response()->json(['section' => $this->editor->section($section->fresh('articles') ?? $section)]);
    }

    // ------------------------------------------------------------------ pictures

    public function pictures(): JsonResponse
    {
        $this->authorize('manage', HelpSection::class);

        return response()->json(['pictures' => $this->editor->pictures()]);
    }

    public function uploadPicture(Request $request): JsonResponse
    {
        $this->authorize('manage', HelpSection::class);

        $request->validate(HelpEditor::uploadRules());

        $image = $this->editor->uploadPicture($request->file('image'), $request->string('ref')->toString());
        $this->log('picture_uploaded', $image->id);

        return response()->json(['picture' => $this->editor->picture($image)]);
    }

    public function updatePicture(Request $request, HelpImage $image): JsonResponse
    {
        $this->authorize('manage', HelpSection::class);

        $image = $this->editor->updatePicture($image, $request->validate(HelpEditor::pictureRules($image)));
        $this->log('picture_updated', $image->id);

        return response()->json(['picture' => $this->editor->picture($image)]);
    }

    public function destroyPicture(HelpImage $image): JsonResponse
    {
        $this->authorize('manage', HelpSection::class);

        $this->editor->deletePicture($image);
        $this->log('picture_deleted', $image->id);

        return response()->json(['ok' => true]);
    }

    // ------------------------------------------------------------------ translation

    public function translate(Request $request, HelpTranslation $translation): JsonResponse
    {
        $this->authorize('manage', HelpSection::class);

        abort_unless($translation->available(), 404);

        $data = $request->validate([
            'text' => ['required', 'string', 'max:20000'],
            'to' => ['required', 'string', Rule::in(array_values(array_diff(HelpText::locales(), [HelpText::sourceLocale()])))],
        ]);

        try {
            $text = $translation->translate($data['text'], $data['to']);
        } catch (HelpTranslationRefused $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => __('larafoundry::help.translation_failed')], 502);
        }

        return response()->json(['text' => $text]);
    }

    private function log(string $what, int $id): void
    {
        Activity::log(
            description: 'admin.help.'.$what,
            logName: 'admin',
            properties: ['id' => $id],
            geoSync: false,
        );
    }
}
