<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Photo;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ProductController extends Controller
{
    public function show(Product $product)
    {
        $product->load(['user', 'photos']);

        // dd($product);

        $user = Auth::user();
        $userCountry = $user->country;
        $sellerCountry = $product->user->country;
        $price = null;

        if ($user->type == 'user' && $userCountry != $sellerCountry) {
            abort(403);
        }

        $priceInUsd = $product->price / ((int) $sellerCountry->usd_value);
        $convertedPrice = round($priceInUsd * $userCountry->usd_value, 2);

        if ($userCountry == $sellerCountry) {
            $price = round($product->price * ((100 - $product->discount) / 100), 2);
        }

        $isSameCountry = ($userCountry == $sellerCountry);
        $symbol = $user->country->currency_symbol;

        return view('product.show', compact('product', 'price', 'isSameCountry', 'convertedPrice', 'symbol'));
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        $query = Product::query();

        if ($user->type === 'user') {
            $query->whereHas('user', function ($q) use ($user) {
                $q->where('country_id', $user->country_id);
            });
        }

        if ($request->filled('category')) {
            $query->where('category_id', $request->category);
        }

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->search.'%');
        }

        $products = $query->with("user.country", "photos")->paginate(12);
        $categories = \App\Models\Category::all();

        return view('product.index', compact('products', 'categories'));
    }

    public function indexAll(Request $request)
    {
        $products = Product::orderBy('id')->with("user", "category")->get();

        return view('dashboard.products.index', compact('products'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'price' => 'required|integer',
            'discount' => 'required|integer',
            'details' => 'required|string',
            'quantity' => 'required|integer',
            'photos.*' => 'required|image|mimes:jpeg,png,jpg,gif,svg',
        ]);

        $product = Product::create([
            'name' => $request->name,
            'user_id' => Auth::id(),
            'category_id' => $request->category_id,
            'price' => $request->price,
            'discount' => $request->discount,
            'details' => $request->details,
            'quantity' => $request->quantity,
        ]);

        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                $path = $photo->store('products', 'public');
                $product->photos()->create(['photo_url' => $path]);
            }
        }

        return redirect()->route('products.index')->with('success', 'Product created successfully.');
    }

    public function create()
    {
        $categories = Category::all();
        $users = User::all();

        return view('dashboard.products.create', [
            'categories' => $categories,
            'users' => $users,
        ]);
    }

    public function edit(Product $product)
    {
        $categories = Category::all();
        $users = User::all();

        return view('dashboard.products.edit', [
            'product' => $product,
            'users' => $users,
            'categories' => $categories,
        ]);
    }

    public function update(Request $request, Product $product)
    {

        $request->validate([
            'name' => 'required|string|max:255',
            'user_id' => 'required|exists:users,id',
            'category_id' => 'required|exists:categories,id',
            'price' => 'required|integer',
            'discount' => 'required|integer',
            'details' => 'required|string',
            'enable' => 'required|in:TRUE,FALSE',
            'quantity' => 'required|integer',
            'photos.*' => 'image|mimes:jpeg,png,jpg,gif,svg',
        ]);

        $product->update([
            'name' => $request->name,
            'user_id' => $request->user_id,
            'category_id' => $request->category_id,
            'price' => $request->price,
            'discount' => $request->discount,
            'details' => $request->details,
            'enabled' => $request->enable,
            'quantity' => $request->quantity,
        ]);

        if ($request->hasFile('photos')) {
            foreach ($request->file('photos') as $photo) {
                $path = $photo->store('products', 'public');
                $product->photos()->create(['photo' => $path]);
            }
        }

        return redirect()->route('products.index')->with('success', 'Product updated successfully.');
    }

    public function destroy(Product $product)
    {
        try {
            $product->delete();

            return redirect()->route('products.index')->with('success', 'Product deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->route('products.index')->with('error', 'An error occurred while deleting the product.');
        }
    }

    public function destroyPhoto(Product $product, Photo $photo)
    {
        Storage::disk('public')->delete($photo->photo_url);
        $photo->delete();

        return redirect()->route('products.edit', [$product])->with('success', 'Photo removed successfully.');
    }
}
