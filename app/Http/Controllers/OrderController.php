<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class OrderController extends Controller
{
    // GET /api/orders
    public function index()
    {
        $orders = Order::with('items')->latest()->get();
        return new OrderResource('Success', 'List of orders', $orders);
    }

    // GET /api/orders/{id}
    public function show($id)
    {
        $order = Order::with('items')->find($id);

        if (!$order) {
            return new OrderResource('Failed', 'Order not found', null);
        }

        $data = $order->toArray();

        // Consume UserService — enrich data user
        $userResponse = Http::get(env('USER_SERVICE_URL') . '/api/users/' . $order->user_id);
        $data['user'] = $userResponse->successful() ? $userResponse->json()['data'] : null;

        // Consume ProductService — enrich data tiap item
        foreach ($data['items'] as $index => $item) {
            $productResponse = Http::get(env('PRODUCT_SERVICE_URL') . '/api/products/' . $item['product_id']);
            $data['items'][$index]['product'] = $productResponse->successful()
                ? $productResponse->json()['data']
                : null;
        }

        return new OrderResource('Success', 'Order found', $data);
    }

    // GET /api/orders/user/filter?user_id=
    public function getByUser(Request $request)
    {
        $userId = $request->query('user_id');

        if (!$userId) {
            return new OrderResource('Failed', 'Parameter user_id diperlukan', null);
        }

        $orders = Order::with('items')
            ->where('user_id', $userId)
            ->latest()
            ->get();

        return new OrderResource('Success', 'List of orders by user', $orders);
    }

    // POST /api/orders
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id'            => 'required|integer',
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity'   => 'required|integer|min:1',
            'notes'              => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return new OrderResource('Failed', 'Validation error', $validator->errors());
        }

        // Consume UserService — validasi user ada
        $userResponse = Http::get(env('USER_SERVICE_URL') . '/api/users/' . $request->user_id);

        if ($userResponse->failed()) {
            return new OrderResource('Failed', 'User tidak ditemukan', null);
        }

        $items      = [];
        $totalPrice = 0;

        foreach ($request->items as $item) {
            $productResponse = Http::get(env('PRODUCT_SERVICE_URL') . '/api/products/' . $item['product_id']);

            if ($productResponse->failed()) {
                return new OrderResource('Failed', 'Produk dengan ID ' . $item['product_id'] . ' tidak ditemukan', null);
            }

            $product = $productResponse->json('data');

            if ($product['stock'] < $item['quantity']) {
                return new OrderResource('Failed', 'Stok produk ' . $product['name'] . ' tidak mencukupi', null);
            }

            $subtotal    = $product['price'] * $item['quantity'];
            $totalPrice += $subtotal;

            $items[] = [
                'product_id'   => $product['id'],
                'product_name' => $product['name'],
                'price'        => $product['price'],
                'quantity'     => $item['quantity'],
                'subtotal'     => $subtotal,
            ];
        }

        DB::beginTransaction();
        try {
            $order = Order::create([
                'user_id'     => $request->user_id,
                'status'      => 'pending',
                'total_price' => $totalPrice,
                'notes'       => $request->notes,
            ]);

            foreach ($items as $item) {
                $order->items()->create($item);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return new OrderResource('Failed', 'Gagal membuat order: ' . $e->getMessage(), null);
        }

        return new OrderResource('Success', 'Order created successfully', $order->load('items'));
    }

    // PUT /api/orders/{id}/status
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,confirmed,cancelled',
        ]);

        if ($validator->fails()) {
            return new OrderResource('Failed', 'Validation error', $validator->errors());
        }

        $order = Order::find($id);

        if (!$order) {
            return new OrderResource('Failed', 'Order not found', null);
        }

        $order->update(['status' => $request->status]);

        return new OrderResource('Success', 'Status order berhasil diupdate', $order);
    }
}
