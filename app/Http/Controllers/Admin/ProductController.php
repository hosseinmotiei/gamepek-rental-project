<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Exceptions\ImageUploadFailedException;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\ActivityLogService;
use App\Services\ImageUploadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function __construct(private ImageUploadService $imageUploads) {}

    public function index(Request $request)
    {
        abort_if(! auth()->user()->can('view_products'), 403);

        $query = Product::with('category');

        if ($q = $request->get('q')) {
            $query->where(function ($qb) use ($q) {
                $qb->where('title_fa', 'LIKE', "%{$q}%")
                    ->orWhere('title_en', 'LIKE', "%{$q}%")
                    ->orWhere('sku', 'LIKE', "%{$q}%")
                    ->orWhere('slug', 'LIKE', "%{$q}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }
        if ($request->filled('stock_status')) {
            $query->where('stock_status', $request->stock_status);
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }
        if ($request->get('featured') === '1') {
            $query->where('is_featured', true);
        }
        if ($request->get('best_seller') === '1') {
            $query->where('is_best_seller', true);
        }
        if ($request->get('flash_sale') === '1') {
            $query->where('is_flash_sale', true);
        }
        match ($request->get('sort', 'newest')) {
            'oldest' => $query->orderBy('created_at', 'asc'),
            'price_asc' => $query->orderBy('price', 'asc'),
            'price_desc' => $query->orderBy('price', 'desc'),
            'stock_asc' => $query->orderBy('stock_quantity', 'asc'),
            'stock_desc' => $query->orderBy('stock_quantity', 'desc'),
            default => $query->orderBy('created_at', 'desc'),
        };

        $products = $query->paginate(20)->withQueryString();
        $categories = Category::orderBy('sort_order')->get();

        return view('admin.products.index', compact('products', 'categories'));
    }

    public function create()
    {
        abort_if(! auth()->user()->can('create_products'), 403);

        $categories = Category::orderBy('sort_order')->get();

        return view('admin.products.create', compact('categories'));
    }

    public function store(StoreProductRequest $request)
    {
        $data = collect($request->validated())
            ->except(['main_image', 'gallery_images', 'attributes_json'])
            ->toArray();

        try {
            // Main image
            if ($request->hasFile('main_image')) {
                $data['main_image'] = $this->imageUploads->storeAndVerify($request->file('main_image'), 'products');
            }

            // Gallery images
            if ($request->hasFile('gallery_images')) {
                $galleryPaths = [];
                foreach ($request->file('gallery_images') as $file) {
                    $galleryPaths[] = $this->imageUploads->storeAndVerify($file, 'products');
                }
                $data['gallery_images'] = $galleryPaths;
            }
        } catch (ImageUploadFailedException $e) {
            return back()->withInput()->withErrors(['main_image' => $e->getMessage()]);
        }

        // Custom attributes JSON
        $data['attributes'] = $this->parseAttributesJson($request->attributes_json);

        $product = Product::create($data);

        ActivityLogService::log('product.create', $product, "ایجاد محصول «{$product->title_fa}»", [
            'sku' => $product->sku, 'price' => $product->price, 'is_active' => $product->is_active,
        ]);

        return redirect()->route('admin.products.index')
            ->with('success', 'محصول «'.$product->title_fa.'» با موفقیت ایجاد شد.');
    }

    public function show(Product $product)
    {
        abort_if(! auth()->user()->can('view_products'), 403);

        $product->load('category');
        $orderCount = OrderItem::where('product_id', $product->id)->count();

        return view('admin.products.show', compact('product', 'orderCount'));
    }

    public function edit(Product $product)
    {
        abort_if(! auth()->user()->can('edit_products'), 403);

        $categories = Category::orderBy('sort_order')->get();
        $product->load('optionGroups.values');

        return view('admin.products.edit', compact('product', 'categories'));
    }

    public function update(UpdateProductRequest $request, Product $product)
    {
        $data = collect($request->validated())
            ->except(['main_image', 'gallery_images', 'attributes_json', 'remove_main_image'])
            ->toArray();

        try {
            // Main image -- store and verify the NEW file BEFORE deleting
            // the old one, so a failed upload never leaves the product with
            // no image at all, and the existing image is left untouched.
            if ($request->hasFile('main_image')) {
                $newPath = $this->imageUploads->storeAndVerify($request->file('main_image'), 'products');
                if ($product->main_image) {
                    Storage::disk('public')->delete($product->main_image);
                }
                $data['main_image'] = $newPath;
            } elseif ($request->boolean('remove_main_image') && $product->main_image) {
                Storage::disk('public')->delete($product->main_image);
                $data['main_image'] = null;
            }

            // Gallery images (append)
            if ($request->hasFile('gallery_images')) {
                $existing = $product->gallery_images ?? [];
                foreach ($request->file('gallery_images') as $file) {
                    $existing[] = $this->imageUploads->storeAndVerify($file, 'products');
                }
                $data['gallery_images'] = array_values($existing);
            }
        } catch (ImageUploadFailedException $e) {
            return back()->withInput()->withErrors(['main_image' => $e->getMessage()]);
        }

        // Custom attributes JSON
        if ($request->filled('attributes_json')) {
            $data['attributes'] = $this->parseAttributesJson($request->attributes_json);
        }

        $before = $product->only(['price', 'sale_price', 'stock_quantity', 'is_active']);
        $product->update($data);

        ActivityLogService::log('product.update', $product, "به‌روزرسانی محصول «{$product->title_fa}»", [
            'changed_fields' => array_keys($product->getChanges()),
            'before' => $before,
            'after' => $product->only(['price', 'sale_price', 'stock_quantity', 'is_active']),
        ]);

        return redirect()->route('admin.products.edit', $product)
            ->with('success', 'محصول با موفقیت به‌روزرسانی شد.');
    }

    public function destroy(Product $product)
    {
        abort_if(! auth()->user()->can('delete_products'), 403);

        if (OrderItem::where('product_id', $product->id)->exists()) {
            $product->update(['is_active' => false]);
            ActivityLogService::log('product.deactivate', $product, "غیرفعال‌سازی محصول «{$product->title_fa}» به‌جای حذف (دارای سفارش)");

            return redirect()->route('admin.products.index')
                ->with('success', 'محصول «'.$product->title_fa.'» غیرفعال شد (دارای سفارش — حذف نشد).');
        }

        if ($product->main_image) {
            Storage::disk('public')->delete($product->main_image);
        }
        foreach ($product->gallery_images ?? [] as $img) {
            Storage::disk('public')->delete($img);
        }

        $sku = $product->sku;
        $product->delete();
        ActivityLogService::log('product.delete', $product, "حذف محصول «{$product->title_fa}»", ['sku' => $sku]);

        return redirect()->route('admin.products.index')
            ->with('success', 'محصول «'.$product->title_fa.'» حذف شد.');
    }

    public function toggle(Product $product)
    {
        abort_if(! auth()->user()->can('edit_products'), 403);

        $before = $product->is_active;
        $product->update(['is_active' => ! $product->is_active]);
        $label = $product->is_active ? 'فعال' : 'غیرفعال';

        ActivityLogService::log('product.toggle_status', $product, "تغییر وضعیت محصول «{$product->title_fa}» به {$label}", [
            'before' => $before, 'after' => $product->is_active,
        ]);

        return back()->with('success', 'محصول «'.$product->title_fa.'» '.$label.' شد.');
    }

    public function removeGalleryImage(Request $request, Product $product)
    {
        abort_if(! auth()->user()->can('manage_product_images'), 403);

        $path = $request->input('path');
        $gallery = array_values(array_filter($product->gallery_images ?? [], fn ($img) => $img !== $path));

        if ($path) {
            Storage::disk('public')->delete($path);
        }
        $product->update(['gallery_images' => $gallery]);

        return back()->with('success', 'تصویر حذف شد.');
    }

    private function parseAttributesJson(?string $json): ?array
    {
        if (empty(trim($json ?? ''))) {
            return null;
        }
        $decoded = json_decode($json, true);

        return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : null;
    }
}
