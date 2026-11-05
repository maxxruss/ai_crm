<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => ['Неверный адрес электронной почты или пароль.'],
            ]);
        }

        $request->session()->regenerate();

        return response()->json(['authenticated' => true]);
    }

    public function session(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenants = $user->tenants()
            ->orderBy('tenants.name')
            ->get(['tenants.id', 'tenants.name'])
            ->map(static fn ($tenant): array => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'role' => $tenant->pivot->role,
            ])
            ->values();

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'tenants' => $tenants,
            'active_tenant_id' => $tenants->first()['id'] ?? null,
            'csrf_token' => csrf_token(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'authenticated' => false,
            'csrf_token' => csrf_token(),
        ]);
    }
}
