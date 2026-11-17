<?php

namespace App\Http\Controllers;

use App\Services\StoreActivityRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function show(): View
    {
        return view('profile.show', ['user' => Auth::user()]);
    }

    public function edit(): View
    {
        return view('profile.edit', ['user' => Auth::user()]);
    }

    public function update(Request $request, StoreActivityRecorder $activities): RedirectResponse
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => [
                'required',
                'email',
                'max:150',
                Rule::unique('tbl_users', 'email')->ignore($user->id),
            ],
            'password' => ['nullable', 'string', Password::defaults(), 'confirmed'],
        ]);

        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        DB::transaction(function () use ($user, $validated, $activities): void {
            $user->update($validated);
            if ($user->wasChanged(['name', 'email', 'password'])) {
                $activities->record('profile_updated', 'user', $user->id, $user->id);
            }
        });

        return redirect()->route('profile.show')
            ->with('status', 'Profile updated successfully.');
    }
}
