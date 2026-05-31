<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Helpers\ApiResponse;
use App\Http\Requests\UploadImageProductCategoryRequest;
use App\Http\Resources\ProductCategoryResource;
use App\Models\ProductCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class ProductCategoryImageController extends Controller
{
    public function store(UploadImageProductCategoryRequest $request, string $id)
    {
        $category = ProductCategory::findOrFail($id);

        if (!$category) {
            return ApiResponse::error(
                'Product Category not found',
                Response::HTTP_NOT_FOUND
            );
        }

        if ($category->image) {
            // Delete old image if exists
            Storage::disk('public')->delete($category->image);
        }

        $path = $request->file('image')->store('product_categories', 'public');
        $category->update(['image' => $path]);

        return ApiResponse::success(
            new ProductCategoryResource($category),
            'Product Category image uploaded successfully',
        );
    }
}
