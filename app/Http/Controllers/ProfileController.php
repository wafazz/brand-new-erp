<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'profile' => [
                'name' => $request->user()->name,
                'email' => $request->user()->email,
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
        ]);

        $request->user()->update($data);

        $this->recorder->record('profile_updated', 'users', $request->user(), $request->user());

        return back()->with('success', 'Profile updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], (string) $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That is not your current password.',
            ]);
        }

        if (Hash::check($data['password'], (string) $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => 'The new password must be different from the current one.',
            ]);
        }

        $request->user()->update(['password' => $data['password']]);

        $this->recorder->record('password_changed', 'users', $request->user(), $request->user());

        return back()->with('success', 'Password changed.');
    }
}
