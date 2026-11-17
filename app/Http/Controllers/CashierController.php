<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\StoreActivityRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class CashierController extends Controller
{
    public function __construct(private StoreActivityRecorder $activities) {}

    public function index(): View
    {
        $cashiers = User::where('role', 'cashier')
            ->orderBy('status')
            ->orderBy('name')
            ->get();

        $activeCount = $cashiers->where('status', 'active')->count();

        return view('cashiers.index', [
            'cashiers' => $cashiers,
            'activeCount' => $activeCount,
        ]);
    }

    public function create(): View
    {
        return view('cashiers.form', ['cashier' => new User]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateCashier($request);

        DB::transaction(function () use ($validated): void {
            $cashier = User::create([
                ...$validated,
                'role' => 'cashier',
                'status' => 'active',
                'profile_image' => 'default-profile.png',
            ]);
            $this->activities->record('cashier_created', 'cashier', $cashier->id, (int) Auth::id());
        });

        return redirect()->route('cashiers.index')
            ->with('status', 'Cashier account created successfully.');
    }

    public function edit(User $cashier): View
    {
        if ($cashier->role !== 'cashier') {
            abort(403);
        }

        return view('cashiers.form', ['cashier' => $cashier]);
    }

    public function update(Request $request, User $cashier): RedirectResponse
    {
        if ($cashier->role !== 'cashier') {
            return back()->withErrors([
                'cashier' => 'Only cashier accounts can be managed here.',
            ]);
        }

        $validated = $this->validateCashier($request, $cashier);

        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        DB::transaction(function () use ($cashier, $validated): void {
            $cashier->update($validated);
            if ($cashier->wasChanged(['name', 'email', 'phone', 'address', 'password'])) {
                $this->activities->record('cashier_updated', 'cashier', $cashier->id, (int) Auth::id());
            }
        });

        return redirect()->route('cashiers.index')
            ->with('status', 'Cashier account updated successfully.');
    }

    public function toggleStatus(User $cashier): RedirectResponse
    {
        if ($cashier->role !== 'cashier') {
            return back()->withErrors([
                'cashier' => 'Only cashier accounts can be managed here.',
            ]);
        }

        if ($cashier->id === Auth::id()) {
            return back()->withErrors([
                'cashier' => 'You cannot deactivate your own account.',
            ]);
        }

        $newStatus = $cashier->status === 'active' ? 'inactive' : 'active';

        DB::transaction(function () use ($cashier, $newStatus): void {
            $cashier->update(['status' => $newStatus]);
            $this->activities->record('cashier_status_changed', 'cashier', $cashier->id, (int) Auth::id());
        });

        return redirect()->route('cashiers.index')
            ->with('status', "Cashier account {$newStatus}.");
    }

    public function destroy(User $cashier): RedirectResponse
    {
        if ($cashier->role !== 'cashier') {
            return back()->withErrors([
                'cashier' => 'Only cashier accounts can be managed here.',
            ]);
        }

        if ($cashier->status === 'active') {
            return back()->withErrors([
                'cashier' => 'Deactivate the account before deleting it.',
            ]);
        }

        DB::transaction(function () use ($cashier): void {
            $cashier->delete();
            $this->activities->record('cashier_deleted', 'cashier', $cashier->id, (int) Auth::id());
        });

        return redirect()->route('cashiers.index')
            ->with('status', 'Cashier account deleted.');
    }

    private function validateCashier(Request $request, ?User $cashier = null): array
    {
        $passwordRules = $cashier?->exists
            ? ['nullable', 'string', Password::defaults(), 'confirmed']
            : ['required', 'string', Password::defaults(), 'confirmed'];

        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => [
                'required',
                'email',
                'max:150',
                Rule::unique('tbl_users', 'email')->ignore($cashier?->id),
            ],
            'password' => $passwordRules,
            'phone' => ['required', 'string', 'max:20'],
            'address' => ['required', 'string', 'max:255'],
        ]);
    }
}
