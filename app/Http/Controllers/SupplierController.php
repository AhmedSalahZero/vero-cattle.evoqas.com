<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Supplier Controller
//
//  Scope:     company_id (company-level entity — no farm_id)
//  Bilingual: name_en / name_ar  |  address_en / address_ar
//             Both pairs follow §5 bilingual rule:
//             - sanitizeNullableFields() converts '' → null (Inertia quirk)
//             - Both _en and _ar are validated as nullable|string
//             - Cross-field guard: at least one name must be non-null
//
//  Accounting link (Phase 5):
//    odoo_partner_id is populated by OdooAccountingDriver::syncSupplier()
//    It is never set from user input here — server-side only.
//
//  Routes (all inside auth + verified middleware group):
//    GET    /suppliers              → index
//    GET    /suppliers/create       → create  (→ CreateEdit mode:'create')
//    POST   /suppliers              → store
//    GET    /suppliers/{id}/edit    → edit    (→ CreateEdit mode:'edit')
//    PATCH  /suppliers/{id}         → update
//    DELETE /suppliers/{id}         → destroy
//    PATCH  /suppliers/{id}/toggle-active → toggleActive
// ══════════════════════════════════════════════════════════════════

class SupplierController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view suppliers'),
            403
        );

        $query = Supplier::withCount(['apInvoices', 'procurementRecords', 'animals'])
            ->orderBy('name_en');

        // Scope: super admin can see all; others see own company only
        if (! $authUser->is_super_admin) {
            $query->where('company_id', $authUser->company_id);
        }

        // ── Filters ───────────────────────────────────────────────
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name_en', 'like', "%{$search}%")
                  ->orWhere('name_ar', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        if ($request->has('is_active') && $request->input('is_active') !== '') {
            $query->where('is_active', (bool) $request->input('is_active'));
        }

        $suppliers = $query->get();

        // Super admin gets companies list for filter display
        $companies = $authUser->is_super_admin
            ? Company::orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
            : collect();

        return Inertia::render('Suppliers/Index', [
            'suppliers' => $suppliers->map(fn($s) => $this->formatSupplier($s)),
            'companies' => $companies,
            'filters'   => $request->only(['search', 'type', 'is_active']),
        ]);
    }

    // ── Create form ───────────────────────────────────────────────
    public function create()
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create suppliers'),
            403
        );

        $companies = $authUser->is_super_admin
            ? Company::where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
            : Company::where('id', $authUser->company_id)->get(['id', 'name_en', 'name_ar']);

        return Inertia::render('Suppliers/CreateEdit', [
            'supplier'  => null,
            'companies' => $companies,
            'mode'      => 'create',
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create suppliers'),
            403
        );

        // §5.3 — convert Inertia '' → null before validation
        $this->sanitizeNullableFields($request);

        // §6.5 — session-based company resolution for Super Admin
        $companyId = $this->resolveCompanyId($request);

        $validated = $this->validateSupplier($request);

        // Odoo partner id is server-side only — never accept from user input
        unset($validated['odoo_partner_id']);

        Supplier::create(array_merge($validated, ['company_id' => $companyId]));

        return redirect()->route('suppliers.index')
            ->with('success', __('messages.supplier_created'));
    }

    // ── Edit form ─────────────────────────────────────────────────
    public function edit(Supplier $supplier)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit suppliers') &&
             $authUser->company_id === $supplier->company_id),
            403
        );

        $companies = $authUser->is_super_admin
            ? Company::where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
            : Company::where('id', $authUser->company_id)->get(['id', 'name_en', 'name_ar']);

        return Inertia::render('Suppliers/CreateEdit', [
            'supplier'  => $this->formatSupplierFull($supplier),
            'companies' => $companies,
            'mode'      => 'edit',
        ]);
    }

    // ── Update ────────────────────────────────────────────────────
    public function update(Request $request, Supplier $supplier)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit suppliers') &&
             $authUser->company_id === $supplier->company_id),
            403
        );

        // §5.3 — convert Inertia '' → null before validation
        $this->sanitizeNullableFields($request);

        $validated = $this->validateSupplier($request, $supplier->id);

        // Odoo partner id is server-side only — never accept from user input
        unset($validated['odoo_partner_id']);

        // company_id is immutable after creation — drop if accidentally sent
        unset($validated['company_id']);

        $supplier->update($validated);

        return redirect()->route('suppliers.index')
            ->with('success', __('messages.supplier_updated'));
    }

    // ── Toggle Active ─────────────────────────────────────────────
    // Route: PATCH /suppliers/{supplier}/toggle-active
    // Name:  suppliers.toggle-active
    public function toggleActive(Supplier $supplier)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit suppliers') &&
             $authUser->company_id === $supplier->company_id),
            403
        );

        $supplier->update(['is_active' => ! $supplier->is_active]);

        return back()->with('success', __('messages.supplier_status_updated'));
    }

    // ── Destroy ───────────────────────────────────────────────────
    public function destroy(Supplier $supplier)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('delete suppliers') &&
             $authUser->company_id === $supplier->company_id),
            403
        );

        // Guard: cannot delete if linked to AP invoices or procurement records
        if ($supplier->apInvoices()->exists()) {
            return back()->withErrors([
                'supplier' => __('messages.supplier_has_ap_invoices'),
            ]);
        }

        if ($supplier->procurementRecords()->exists()) {
            return back()->withErrors([
                'supplier' => __('messages.supplier_has_procurement_records'),
            ]);
        }

        $supplier->delete(); // soft delete — SoftDeletes trait

        return redirect()->route('suppliers.index')
            ->with('success', __('messages.supplier_deleted'));
    }

    // ══════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════

   
    /**
     * Validate supplier fields.
     * Bilingual rule §5.2:
     *   - Both _en / _ar pairs are nullable|string
     *   - Cross-field guard checks at least one name is non-null
     *
     * @param  int|null  $ignoreId  Pass for uniqueness on update.
     */
    private function validateSupplier(Request $request, ?int $ignoreId = null): array
    {
        $validated = $request->validate([
            'company_id'          => 'sometimes|exists:companies,id',
            // Bilingual name pair — both nullable (§5.3)
            'name_en'             => 'nullable|string|max:255',
            'name_ar'             => 'nullable|string|max:255',
            // Code unique within company
            'code'                => [
                'nullable', 'string', 'max:50',
                Rule::unique('suppliers', 'code')
                    ->where('company_id', request()->input('company_id', auth()->user()->company_id))
                    ->ignore($ignoreId),
            ],
            'type'                => 'required|in:animal,feed,medicine,equipment,other',
            'contact_person'      => 'nullable|string|max:255',
            'phone'               => 'nullable|string|max:50',
            'email'               => 'nullable|email|max:255',
            'website'             => 'nullable|url|max:255',
            // Bilingual address pair — both nullable (§5.3)
            'address_en'          => 'nullable|string|max:255',
            'address_ar'          => 'nullable|string|max:255',
            'city'                => 'nullable|string|max:255',
            'country'             => 'nullable|string|max:2',
            'tax_number'          => 'nullable|string|max:50',
            'bank_name'           => 'nullable|string|max:255',
            'bank_account'        => 'nullable|string|max:100',
            'payment_terms_days'  => 'nullable|integer|min:0|max:365',
            'is_active'           => 'sometimes|boolean',
            'notes'               => 'nullable|string',
        ]);

        // ── Cross-field guard (§5.2) ──────────────────────────────
        // At least one of name_en / name_ar must be non-null
        if (empty($validated['name_en']) && empty($validated['name_ar'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name_en' => __('validation.required', ['attribute' => 'supplier name']),
            ]);
        }

        return $validated;
    }

    /**
     * Format supplier for Index page (list view — counts only, no relations).
     */
    private function formatSupplier(Supplier $supplier): array
    {
        return [
            'id'                      => $supplier->id,
            'company_id'              => $supplier->company_id,
            'name_en'                 => $supplier->name_en,
            'name_ar'                 => $supplier->name_ar,
            'code'                    => $supplier->code,
            'type'                    => $supplier->type,
            'contact_person'          => $supplier->contact_person,
            'phone'                   => $supplier->phone,
            'email'                   => $supplier->email,
            'city'                    => $supplier->city,
            'country'                 => $supplier->country,
            'payment_terms_days'      => $supplier->payment_terms_days,
            'is_active'               => $supplier->is_active,
            'odoo_partner_id'         => $supplier->odoo_partner_id,
            // Aggregates for card display
            'ap_invoices_count'       => $supplier->ap_invoices_count ?? 0,
            'procurement_records_count' => $supplier->procurement_records_count ?? 0,
            'animals_count'           => $supplier->animals_count ?? 0,
            'created_at'              => $supplier->created_at?->format('d M Y'),
        ];
    }

    /**
     * Format supplier for CreateEdit page (full fields).
     */
    private function formatSupplierFull(Supplier $supplier): array
    {
        return [
            'id'                   => $supplier->id,
            'company_id'           => $supplier->company_id,
            'name_en'              => $supplier->name_en,
            'name_ar'              => $supplier->name_ar,
            'code'                 => $supplier->code,
            'type'                 => $supplier->type,
            'contact_person'       => $supplier->contact_person,
            'phone'                => $supplier->phone,
            'email'                => $supplier->email,
            'website'              => $supplier->website,
            'address_en'           => $supplier->address_en,
            'address_ar'           => $supplier->address_ar,
            'city'                 => $supplier->city,
            'country'              => $supplier->country,
            'tax_number'           => $supplier->tax_number,
            'bank_name'            => $supplier->bank_name,
            'bank_account'         => $supplier->bank_account,
            'payment_terms_days'   => $supplier->payment_terms_days,
            'is_active'            => $supplier->is_active,
            'notes'                => $supplier->notes,
            'odoo_partner_id'      => $supplier->odoo_partner_id,
            'created_at'           => $supplier->created_at?->format('d M Y'),
            'updated_at'           => $supplier->updated_at?->format('d M Y'),
        ];
    }
}