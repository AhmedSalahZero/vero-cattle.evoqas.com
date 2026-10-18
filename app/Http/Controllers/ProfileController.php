<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Profile Controller
//
//  Scope: the currently authenticated user editing their OWN profile.
//  No permission check needed — every authenticated user can manage
//  their own profile.
//
//  This is separate from UserController (admin managing OTHER users).
//
//  Routes:
//    GET    /profile        → edit()    renders Profile/Edit.vue
//    PATCH  /profile        → update()  saves personal info
//    PUT    /profile/password → updatePassword()  changes password
//    DELETE /profile        → destroy() deletes own account
// ══════════════════════════════════════════════════════════════════

class ProfileController extends Controller
{
    // ── Edit — show the profile form ─────────────────────────────
    public function edit(Request $request)
    {
        $user = Auth::user();

        return Inertia::render('Profile/Edit', [
            'user' => $this->formatUser($user),
        ]);
    }

    // ── Update — save personal info ───────────────────────────────
    public function update(Request $request)
    {
        $this->sanitizeNullableFields($request);

        $user = Auth::user();

        $validated = $request->validate([
            'name_en'       => 'nullable|string|max:255',
            'name_ar'       => 'nullable|string|max:255',
            'email'         => 'required|email|max:255|unique:users,email,' . $user->id,
            'phone'         => 'nullable|string|max:50',
            'job_title_en'  => 'nullable|string|max:255',
            'job_title_ar'  => 'nullable|string|max:255',
            'language'      => 'required|in:en,ar',
            'theme'         => 'required|in:light,dark',
        ]);

        // Cross-field guard — at least one name must be present
        if (empty($validated['name_en']) && empty($validated['name_ar'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name_en' => __('validation.required', ['attribute' => 'name']),
            ]);
        }

        // Derive the legacy `name` field from whichever name is present
        $validated['name'] = $validated['name_en'] ?? $validated['name_ar'];

        $user->update($validated);

        return back()->with('success', __('messages.profile_updated'));
    }

    // ── Update password ───────────────────────────────────────────
    public function updatePassword(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password'         => ['required', 'confirmed', Password::min(8)],
        ]);

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return back()->with('success', __('messages.password_updated'));
    }

    // ── Destroy — delete own account ──────────────────────────────
    public function destroy(Request $request)
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = Auth::user();

        // Cannot delete yourself if you are the only Super Admin
        if ($user->is_super_admin) {
            $otherAdmins = \App\Models\User::where('is_super_admin', true)
                ->where('id', '!=', $user->id)
                ->count();

            if ($otherAdmins === 0) {
                return back()->withErrors([
                    'password' => __('messages.cannot_delete_last_super_admin'),
                ]);
            }
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/')->with('success', 'Your account has been deleted.');
    }

    // ── Private helpers ───────────────────────────────────────────

    private function formatUser($user): array
    {
        return [
            'id'            => $user->id,
            'name'          => $user->name,
            'name_en'       => $user->name_en,
            'name_ar'       => $user->name_ar,
            'email'         => $user->email,
            'phone'         => $user->phone,
            'avatar'        => $user->avatar,
            'job_title_en'  => $user->job_title_en,
            'job_title_ar'  => $user->job_title_ar,
            'language'      => $user->language,
            'theme'         => $user->theme,
            'is_super_admin'=> $user->is_super_admin,
            'company'       => $user->company
                ? ['id' => $user->company->id, 'name_en' => $user->company->name_en, 'name_ar' => $user->company->name_ar]
                : null,
            'created_at'    => $user->created_at?->format('d M Y'),
        ];
    }
}