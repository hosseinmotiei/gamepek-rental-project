<?php

namespace App\Http\Controllers;

use App\Http\Requests\Cart\AddToCartRequest;
use App\Http\Requests\Cart\UpdateCartRequest;
use App\Services\CartService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CartController extends Controller
{
    public function __construct(private CartService $cartService) {}

    public function index(): View
    {
        $summary = $this->cartService->getCartSummary();

        return view('cart.index', $summary);
    }

    public function add(AddToCartRequest $request): JsonResponse
    {
        try {
            $this->cartService->addItem(
                $request->product_id,
                $request->quantity ?? 1,
                $request->input('selected_options')
            );

            $summary = $this->cartService->getCartSummary();

            return response()->json([
                'success' => true,
                'message' => 'محصول به سبد خرید افزوده شد.',
                'items_count' => $summary['items_count'],
                'subtotal' => $summary['subtotal'],
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'محصول پیدا نشد.',
            ], 404);
        } catch (QueryException $e) {
            // BUG-014: a raw DB failure must never reach the customer as-is.
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'مشکلی پیش آمد. لطفاً دوباره تلاش کنید.',
            ], 500);
        } catch (\Exception $e) {
            report($e);
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : ($e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'مشکلی پیش آمد. لطفاً دوباره تلاش کنید.',
            ], $status);
        }
    }

    public function update(UpdateCartRequest $request, int $itemId): JsonResponse
    {
        try {
            $this->cartService->updateItem($itemId, $request->quantity);
            $summary = $this->cartService->getCartSummary();

            return response()->json([
                'success' => true,
                'items_count' => $summary['items_count'],
                'subtotal' => $summary['subtotal'],
            ]);
        } catch (QueryException $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'مشکلی پیش آمد. لطفاً دوباره تلاش کنید.',
            ], 500);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'مشکلی پیش آمد. لطفاً دوباره تلاش کنید.',
            ], 422);
        }
    }

    public function remove(int $itemId): JsonResponse
    {
        try {
            $this->cartService->removeItem($itemId);
            $summary = $this->cartService->getCartSummary();

            return response()->json([
                'success' => true,
                'message' => 'محصول از سبد خرید حذف شد.',
                'items_count' => $summary['items_count'],
                'subtotal' => $summary['subtotal'],
            ]);
        } catch (QueryException $e) {
            report($e);

            return response()->json(['success' => false, 'message' => 'مشکلی پیش آمد. لطفاً دوباره تلاش کنید.'], 500);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['success' => false, 'message' => $e->getMessage() ?: 'مشکلی پیش آمد. لطفاً دوباره تلاش کنید.'], 422);
        }
    }

    public function applyCoupon(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string|max:50']);

        try {
            $this->cartService->applyCoupon($request->code);
            $summary = $this->cartService->getCartSummary();

            return response()->json([
                'success' => true,
                'message' => 'کد تخفیف با موفقیت اعمال شد.',
                'discount' => $summary['discount'],
                'subtotal' => $summary['subtotal'],
            ]);
        } catch (QueryException $e) {
            report($e);

            return response()->json(['success' => false, 'message' => 'مشکلی پیش آمد. لطفاً دوباره تلاش کنید.'], 500);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['success' => false, 'message' => $e->getMessage() ?: 'مشکلی پیش آمد. لطفاً دوباره تلاش کنید.'], 422);
        }
    }

    public function removeCoupon(): JsonResponse
    {
        $this->cartService->removeCoupon();

        return response()->json(['success' => true, 'message' => 'کد تخفیف حذف شد.']);
    }

    public function count(): JsonResponse
    {
        $summary = $this->cartService->getCartSummary();

        return response()->json(['count' => $summary['items_count']]);
    }

    public function mini(): JsonResponse
    {
        $summary = $this->cartService->getCartSummary();

        $items = $summary['items']->filter(fn ($item) => $item->product !== null)->map(function ($item) {
            $price = $item->line_total;

            return [
                'id' => $item->id,
                'title' => $item->product->title_fa,
                'quantity' => persian_number($item->quantity),
                'price' => $price,
                'price_formatted' => persian_number($price),
                'image' => $item->product->main_image ? asset('storage/'.$item->product->main_image) : null,
                'slug' => $item->product->slug,
            ];
        })->values();

        $total = max(0, $summary['subtotal'] - $summary['discount']);

        return response()->json([
            'items' => $items,
            'count' => $summary['items_count'],
            'count_formatted' => persian_number($summary['items_count']),
            'total' => $total,
            'total_formatted' => persian_number($total),
        ]);
    }
}
