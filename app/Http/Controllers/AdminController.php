<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Trip;
use App\Models\BlogPost;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    // Dashboard Stats
    public function stats()
    {
        return response()->json([
            'users_count' => User::count(),
            'trips_count' => Trip::count(),
            'blogs_count' => BlogPost::count(),
            'recent_users' => User::latest()->take(5)->get(),
            'popular_destinations' => Trip::select('destination')
                ->selectRaw('count(*) as count')
                ->groupBy('destination')
                ->orderByDesc('count')
                ->take(5)
                ->get()
        ]);
    }

    // --- User Management ---
    public function getUsers()
    {
        return response()->json(User::latest()->get());
    }

    public function createUser(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|in:user,admin',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => bcrypt($validated['password']),
            'role' => $validated['role'],
        ]);

        return response()->json($user, 201);
    }

    public function updateUser(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|string|email|max:255|unique:users,email,' . $id,
            'role' => 'sometimes|in:user,admin',
            'password' => 'nullable|string|min:8',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = bcrypt($validated['password']);
        }

        $user->update($validated);
        return response()->json($user);
    }

    public function deleteUser($id)
    {
        $user = User::findOrFail($id);
        $user->delete();
        return response()->json(['message' => 'User deleted successfully']);
    }

    // --- Trip Management ---
    public function getTrips()
    {
        return response()->json(Trip::with('user')->latest()->get());
    }

    public function updateTrip(Request $request, $id)
    {
        $trip = Trip::findOrFail($id);

        $validated = $request->validate([
            'trip_title' => 'sometimes|string|max:255',
            'destination' => 'sometimes|string|max:255',
            'duration' => 'sometimes|integer|min:1',
            'budget' => 'sometimes|string',
        ]);

        $trip->update($validated);
        return response()->json($trip);
    }

    public function deleteTrip($id)
    {
        $trip = Trip::findOrFail($id);
        $trip->delete();
        return response()->json(['message' => 'Trip deleted successfully']);
    }

    // --- Blog Management ---
    public function getBlogs()
    {
        return response()->json(BlogPost::latest()->get());
    }

    public function deleteBlog($id)
    {
        $blog = BlogPost::findOrFail($id);
        $blog->delete();
        return response()->json(['message' => 'Blog post deleted successfully']);
    }
    
    public function createBlog(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'image_url' => 'nullable|url',
            'author' => 'required|string',
            'excerpt' => 'nullable|string',
            'category' => 'required|string',
        ]);

        $blog = BlogPost::create($validated);
        return response()->json($blog, 201);
    }

    public function updateBlog(Request $request, $id)
    {
        $blog = BlogPost::findOrFail($id);
        
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'image_url' => 'nullable|url',
            'author' => 'sometimes|string',
            'excerpt' => 'nullable|string',
            'category' => 'sometimes|string',
        ]);

        $blog->update($validated);
        return response()->json($blog);
    }
}
