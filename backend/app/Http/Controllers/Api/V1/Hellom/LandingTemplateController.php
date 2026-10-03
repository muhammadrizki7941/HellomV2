<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Http\Controllers\Api\V1\Hellom\Concerns\ResolvesSellerOrganization;
use App\Models\OrganizationLandingPage;
use App\Services\Landing\LandingRenderer;
use App\Services\Landing\TemplateLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Seller side of the template gallery (Fase 7.4): visible templates and a live preview with the shop's data. */
class LandingTemplateController extends BaseApiController
{
    use ResolvesSellerOrganization;

    public function __construct(private readonly TemplateLibrary $library)
    {
    }

    public function index(Request $request): JsonResponse
    {
        [, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }

        return $this->ok([
            'categories' => collect(TemplateLibrary::CATEGORIES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'templates' => $this->library->forSellers(),
        ], 'Template');
    }

    /** The template as the shop's page would look (catalog shows the shop's own products). */
    public function preview(Request $request, string $templateId): JsonResponse
    {
        [$organization, $error] = $this->sellerOrganization($request);
        if ($error) {
            return $error;
        }
        $visible = collect($this->library->forSellers())->firstWhere('id', $templateId);
        if (!$visible) {
            return $this->fail('Template tidak ditemukan', ['code' => 'TEMPLATE_NOT_FOUND'], 404);
        }
        $page = new OrganizationLandingPage(['title' => $visible['name'], 'slug' => 'contoh', 'is_home' => true]);
        $page->organization_id = $organization->id;
        $data = app(LandingRenderer::class)->page($organization, $page, $visible['document'], true);

        return $this->ok(['html' => view('landing.page', $data + ['editor' => true, 'sample' => true])->render()], 'Pratinjau template');
    }
}
