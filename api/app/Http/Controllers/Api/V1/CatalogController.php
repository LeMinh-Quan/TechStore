<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function __construct(private readonly CatalogService $catalogService) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:120'],
            'category' => ['sometimes', 'string', 'max:100'],
            'exclude_category' => ['sometimes', 'string', 'max:100'],
            'brand' => ['sometimes', 'string', 'max:100'],
            'min_price' => ['sometimes', 'numeric', 'min:0'],
            'max_price' => ['sometimes', 'numeric', 'min:0'],
            'on_sale' => ['sometimes', 'boolean'],
            'min_rating' => ['sometimes', 'numeric', 'min:1', 'max:5'],
            'cpu' => ['sometimes', 'string', 'max:100'],
            'ram' => ['sometimes', 'string', 'max:100'],
            'rom' => ['sometimes', 'string', 'max:100'],
            'gpu' => ['sometimes', 'string', 'max:100'],
            'os' => ['sometimes', 'string', 'max:100'],
            'display' => ['sometimes', 'string', 'max:100'],
            'battery' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', 'in:featured,price_asc,price_desc,name,best_selling,top_rated'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:48'],
        ]);
        $products = $this->catalogService->catalog($filters);

        return response()->json([
            'data' => $products->getCollection()->map(
                fn (Product $product) => $this->catalogService->present($product),
            )->values(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        return response()->json([
            'data' => $this->catalogService->present($this->catalogService->findPublished($slug)),
        ]);
    }

    public function categories(): JsonResponse
    {
        return response()->json(['data' => $this->catalogService->categories()]);
    }

    public function filters(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:120'],
            'category' => ['sometimes', 'string', 'max:100'],
        ]);

        return response()->json(['data' => $this->catalogService->filters($filters)]);
    }

    public function brands(): JsonResponse
    {
        return response()->json(['data' => $this->catalogService->brands()]);
    }

    public function chatbotSearch(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'category' => ['sometimes', 'string', 'max:100'],
            'min_price' => ['sometimes', 'numeric', 'min:0'],
            'max_price' => ['sometimes', 'numeric', 'min:0'],
            'gaming' => ['sometimes', 'boolean'],
        ]);

        return response()->json(['data' => $this->catalogService->chatbotSearch($filters)]);
    }
}
