<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Helpers\ApiResponse;
use App\Http\Requests\GetTransactionsRequest;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Resources\PaginatedResource;
use App\Http\Resources\TransactionResource;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TransactionController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GetTransactionsRequest $request)
    {
        $transactions = Transaction::with(['customer', 'items.product'])
            ->search($request->search)
            ->latest()
            ->paginate($request->limit ?? 10);

        return ApiResponse::success(
            new PaginatedResource($transactions, TransactionResource::class),
            'Transactions lists'
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTransactionRequest $request)
    {
        try {
            $transaction = DB::transaction(function () use ($request) {
                $items = [];
                $subtotal = 0;

                foreach ($request->items as $item) {
                    // Lock for update to prevent race conditions on stock check/decrement
                    $product = Product::lockForUpdate()->findOrFail($item['product_id']);

                    if ($product->stock < $item['quantity']) {
                        throw new \InvalidArgumentException("Insufficient stock for product: {$product->name} (Available: {$product->stock})");
                    }

                    $itemSubtotal = $product->price * $item['quantity'];

                    $items[] = [
                        'product_id' => $product->id,
                        'price' => $product->price,
                        'quantity' => $item['quantity'],
                        'subtotal' => $itemSubtotal,
                    ];

                    $subtotal += $itemSubtotal;

                    // Decrease product stock
                    $product->decrement('stock', $item['quantity']);
                }

                $tax = $subtotal * 0.11; // 11% tax
                $total = $subtotal + $tax;

                $transaction = Transaction::create([
                    'code' => 'TRX-' . strtoupper(Str::random(8)),
                    'customer_id' => $request->customer_id,
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => $total,
                ]);

                $transaction->items()->createMany($items);

                return $transaction->load(['customer', 'items.product']);
            });

            return ApiResponse::success(
                new TransactionResource($transaction),
                'Transaction created successfully',
                Response::HTTP_CREATED
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error(
                $e->getMessage(),
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        } catch (\Exception $e) {
            return ApiResponse::error(
                'Failed to create transaction: ' . $e->getMessage(),
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $transaction = Transaction::with(['customer', 'items.product'])->find($id);

        if (!$transaction) {
            return ApiResponse::error(
                'Transaction not found',
                Response::HTTP_NOT_FOUND
            );
        }

        return ApiResponse::success(
            new TransactionResource($transaction),
            'Transaction details'
        );
    }
}
