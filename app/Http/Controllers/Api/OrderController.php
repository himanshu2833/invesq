<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Http\Resources\OrderResource;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        $total = 0;
        $itemsData = [];

        DB::beginTransaction();

        try {
            foreach ($validated['items'] as $item) {
                // Lock the product for update
                $product = Product::lockForUpdate()->findOrFail($item['product_id']);

                if (!$product){
                    throw new \Exception("Product not found");
                }

                if ($product->stock === 0) {
                    throw new \Exception("Product {$product->name} is out of stock");
                }

                if ($product->stock < $item['quantity']) {
                    throw new \Exception("Insufficient stock for {$product->name}");
                }

                $lineTotal = $product->price * $item['quantity'];
                $total += $lineTotal;

                $product->decrement('stock', $item['quantity']);

                $itemsData[] = [
                    'product_id' => $product->id,
                    'quantity'   => $item['quantity'],
                    'price'      => $product->price,
                ];
            }

            $order = Order::create(['total_price' => $total]);

            foreach ($itemsData as $item) {
                $order->items()->create($item);
            }

            DB::commit();

            return new OrderResource($order->load('items.product'));

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function index()
    {
        return OrderResource::collection(Order::with('items.product')->get());
    }
}
