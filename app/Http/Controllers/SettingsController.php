<?php

namespace App\Http\Controllers;

use App\Models\AnimalBreed;
use App\Models\InventoryItemTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Settings Controller
//
//  Manages company-level lookup tables used as selectors across
//  the application:
//    • AnimalBreed          → Animals/CreateEdit, Animals/BornCreate
//    • InventoryItemTemplate → Inventory/CreateEdit
//
//  Single page (Settings/Index.vue) with two tabs.
//  Permission: 'manage settings' (Spatie, per-user).
// ══════════════════════════════════════════════════════════════════

class SettingsController extends Controller
{
    // ── Index — render the single settings page ───────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view settings'),
            403
        );

        $companyId = $this->resolveCompanyId($request);

        $breeds = AnimalBreed::where('company_id', $companyId)
            ->orderBy('name_en')
            ->get()
            ->map(fn($b) => $this->formatBreed($b));

        $templates = InventoryItemTemplate::where('company_id', $companyId)
            ->orderBy('name_en')
            ->get()
            ->map(fn($t) => $this->formatTemplate($t));

        return Inertia::render('Settings/Index', [
            'breeds'    => $breeds,
            'templates' => $templates,
        ]);
    }

    // ══════════════════════════════════════════════════════════════
    //  BREEDS
    // ══════════════════════════════════════════════════════════════

    public function storeBreed(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view settings'),
            403
        );

        $this->sanitizeNullableFields($request);
        $companyId = $this->resolveCompanyId($request);

        $request->validate([
            'name_en' => ['nullable', 'string', 'max:255',
                Rule::unique('animal_breeds')->where('company_id', $companyId)],
            'name_ar' => ['nullable', 'string', 'max:255',
                Rule::unique('animal_breeds')->where('company_id', $companyId)],
        ]);

        if (empty($request->name_en) && empty($request->name_ar)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name_en' => __('validation.required', ['attribute' => 'name']),
            ]);
        }

        $breed = AnimalBreed::create([
            'company_id' => $companyId,
            'name_en'    => $request->name_en,
            'name_ar'    => $request->name_ar,
            'is_active'  => true,
        ]);

        return back()->with('success', __('messages.breed_created'));
    }

    public function updateBreed(Request $request, AnimalBreed $animalBreed)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view settings'),
            403
        );

        $this->authorizeCompany($authUser, $animalBreed->company_id);
        $this->sanitizeNullableFields($request);

        $request->validate([
            'name_en' => ['nullable', 'string', 'max:255',
                Rule::unique('animal_breeds')->where('company_id', $animalBreed->company_id)->ignore($animalBreed->id)],
            'name_ar' => ['nullable', 'string', 'max:255',
                Rule::unique('animal_breeds')->where('company_id', $animalBreed->company_id)->ignore($animalBreed->id)],
        ]);

        if (empty($request->name_en) && empty($request->name_ar)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name_en' => __('validation.required', ['attribute' => 'name']),
            ]);
        }

        $animalBreed->update([
            'name_en' => $request->name_en,
            'name_ar' => $request->name_ar,
        ]);

        return back()->with('success', __('messages.breed_updated'));
    }

    public function destroyBreed(AnimalBreed $animalBreed)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view settings'),
            403
        );

        $this->authorizeCompany($authUser, $animalBreed->company_id);
        $animalBreed->delete();

        return back()->with('success', __('messages.breed_deleted'));
    }

    public function toggleActiveBreed(AnimalBreed $animalBreed)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view settings'),
            403
        );

        $this->authorizeCompany($authUser, $animalBreed->company_id);
        $animalBreed->update(['is_active' => !$animalBreed->is_active]);

        return back()->with('success', __('messages.status_updated'));
    }

    // ══════════════════════════════════════════════════════════════
    //  INVENTORY ITEM TEMPLATES
    // ══════════════════════════════════════════════════════════════

    public function storeTemplate(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view settings'),
            403
        );

        $this->sanitizeNullableFields($request);
        $companyId = $this->resolveCompanyId($request);

        $request->validate([
            'name_en'  => ['nullable', 'string', 'max:255',
                Rule::unique('inventory_item_templates')->where('company_id', $companyId)],
            'name_ar'  => ['nullable', 'string', 'max:255',
                Rule::unique('inventory_item_templates')->where('company_id', $companyId)],
            'category' => ['required', Rule::in([
                'medicine', 'vaccine', 'feed', 'equipment', 'supply', 'other',
            ])],
        ]);

        if (empty($request->name_en) && empty($request->name_ar)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name_en' => __('validation.required', ['attribute' => 'name']),
            ]);
        }

        InventoryItemTemplate::create([
            'company_id' => $companyId,
            'name_en'    => $request->name_en,
            'name_ar'    => $request->name_ar,
            'category'   => $request->category,
            'is_active'  => true,
        ]);

        return back()->with('success', __('messages.template_created'));
    }

    public function updateTemplate(Request $request, InventoryItemTemplate $inventoryItemTemplate)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view settings'),
            403
        );

        $this->authorizeCompany($authUser, $inventoryItemTemplate->company_id);
        $this->sanitizeNullableFields($request);

        $request->validate([
            'name_en'  => ['nullable', 'string', 'max:255',
                Rule::unique('inventory_item_templates')
                    ->where('company_id', $inventoryItemTemplate->company_id)
                    ->ignore($inventoryItemTemplate->id)],
            'name_ar'  => ['nullable', 'string', 'max:255',
                Rule::unique('inventory_item_templates')
                    ->where('company_id', $inventoryItemTemplate->company_id)
                    ->ignore($inventoryItemTemplate->id)],
            'category' => ['required', Rule::in([
                'medicine', 'vaccine', 'feed', 'equipment', 'supply', 'other',
            ])],
        ]);

        if (empty($request->name_en) && empty($request->name_ar)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name_en' => __('validation.required', ['attribute' => 'name']),
            ]);
        }

        $inventoryItemTemplate->update([
            'name_en'  => $request->name_en,
            'name_ar'  => $request->name_ar,
            'category' => $request->category,
        ]);

        return back()->with('success', __('messages.template_updated'));
    }

    public function destroyTemplate(InventoryItemTemplate $inventoryItemTemplate)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view settings'),
            403
        );

        $this->authorizeCompany($authUser, $inventoryItemTemplate->company_id);
        $inventoryItemTemplate->delete();

        return back()->with('success', __('messages.template_deleted'));
    }

    public function toggleActiveTemplate(InventoryItemTemplate $inventoryItemTemplate)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view settings'),
            403
        );

        $this->authorizeCompany($authUser, $inventoryItemTemplate->company_id);
        $inventoryItemTemplate->update(['is_active' => !$inventoryItemTemplate->is_active]);

        return back()->with('success', __('messages.status_updated'));
    }

    // ══════════════════════════════════════════════════════════════
    //  FORMAT HELPERS
    // ══════════════════════════════════════════════════════════════

    private function formatBreed(AnimalBreed $breed): array
    {
        return [
            'id'        => $breed->id,
            'name_en'   => $breed->name_en,
            'name_ar'   => $breed->name_ar,
            'is_active' => $breed->is_active,
        ];
    }

    private function formatTemplate(InventoryItemTemplate $template): array
    {
        return [
            'id'        => $template->id,
            'name_en'   => $template->name_en,
            'name_ar'   => $template->name_ar,
            'category'  => $template->category,
            'is_active' => $template->is_active,
        ];
    }

    // ── Company authorization helper ──────────────────────────────
    private function authorizeCompany($authUser, int $recordCompanyId): void
    {
        if ($authUser->is_super_admin) return;
        abort_unless((int) $authUser->company_id === $recordCompanyId, 403);
    }
}