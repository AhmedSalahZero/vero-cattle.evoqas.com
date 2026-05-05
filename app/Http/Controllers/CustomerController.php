<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// ══════════════════════════════════════════════════════════════════
//  VERO Cattle Management — Customer Controller
//
//  Scope:     company_id (company-level entity — no farm_id)
//  Bilingual: name_en / name_ar  |  address_en / address_ar
//             Both pairs follow §5 bilingual rule.
//
//  Partner link logic:
//    When a customer is created/updated with is_supplier = true
//    AND a supplier_id is provided → the linked Supplier record
//    gets is_customer = true automatically.
//    When is_supplier is toggled off → Supplier.is_customer = false
//    and supplier_id is cleared.
//
//  Phase 5 note:
//    odoo_partner_id is never accepted from user input.
//    OdooAccountingDriver::syncCustomer() writes it directly.
//    If customer.supplier_id is set, both share the same Odoo
//    res.partner record.
//
//  Routes (inside auth + verified middleware):
//    GET    /customers              → index
//    GET    /customers/create       → create
//    POST   /customers              → store
//    GET    /customers/{id}/edit    → edit
//    PATCH  /customers/{id}         → update
//    DELETE /customers/{id}         → destroy
//    PATCH  /customers/{id}/toggle-active → toggleActive
// ══════════════════════════════════════════════════════════════════

class CustomerController extends Controller
{
    // ── Index ─────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('view customers'),
            403
        );

        $query = Customer::withCount(['arInvoices', 'salesRecords'])
            ->orderBy('name_en');

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

        if ($request->has('is_active') && $request->input('is_active') !== '') {
            $query->where('is_active', (bool) $request->input('is_active'));
        }

        $customers = $query->get();

        $companies = $authUser->is_super_admin
            ? Company::orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
            : collect();

        return Inertia::render('Customers/Index', [
            'customers' => $customers->map(fn($c) => $this->formatCustomer($c)),
            'companies' => $companies,
            'filters'   => $request->only(['search', 'is_active']),
        ]);
    }

    // ── Create ────────────────────────────────────────────────────
    public function create()
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create customers'),
            403
        );

        $companies = $authUser->is_super_admin
            ? Company::where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
            : Company::where('id', $authUser->company_id)->get(['id', 'name_en', 'name_ar']);

        // Available suppliers to link (active, same company)
        $suppliers = $this->availableSuppliers($authUser);

        return Inertia::render('Customers/CreateEdit', [
            'customer'  => null,
            'companies' => $companies,
            'suppliers' => $suppliers,
            'mode'      => 'create',
        ]);
    }

    // ── Store ─────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            $authUser->hasPermissionTo('create customers'),
            403
        );

        $this->sanitizeNullableFields($request);

        $companyId = $authUser->is_super_admin
            ? $request->input('company_id', $authUser->company_id)
            : $authUser->company_id;

        $validated = $this->validateCustomer($request);

        // Never accept odoo_partner_id from user input
        unset($validated['odoo_partner_id']);

        $customer = Customer::create(array_merge($validated, [
            'company_id' => $companyId,
        ]));

        // ── Partner link: update linked Supplier ──────────────────
        $this->syncPartnerLink($customer, $validated);

        return redirect()->route('customers.index')
            ->with('success', __('messages.customer_created'));
    }

    // ── Edit ──────────────────────────────────────────────────────
    public function edit(Customer $customer)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit customers') &&
             $authUser->company_id === $customer->company_id),
            403
        );

        $companies = $authUser->is_super_admin
            ? Company::where('is_active', true)->orderBy('name_en')->get(['id', 'name_en', 'name_ar'])
            : Company::where('id', $authUser->company_id)->get(['id', 'name_en', 'name_ar']);

        $suppliers = $this->availableSuppliers($authUser);

        return Inertia::render('Customers/CreateEdit', [
            'customer'  => $this->formatCustomerFull($customer),
            'companies' => $companies,
            'suppliers' => $suppliers,
            'mode'      => 'edit',
        ]);
    }

    // ── Update ────────────────────────────────────────────────────
    public function update(Request $request, Customer $customer)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit customers') &&
             $authUser->company_id === $customer->company_id),
            403
        );

        $this->sanitizeNullableFields($request);

        $validated = $this->validateCustomer($request, $customer->id);

        unset($validated['odoo_partner_id']);
        unset($validated['company_id']);

        // ── Partner link: clear old link before updating ──────────
        $this->clearOldPartnerLink($customer, $validated);

        $customer->update($validated);

        // ── Partner link: set new link after updating ─────────────
        $this->syncPartnerLink($customer->fresh(), $validated);

        return redirect()->route('customers.index')
            ->with('success', __('messages.customer_updated'));
    }

    // ── Toggle Active ─────────────────────────────────────────────
    public function toggleActive(Customer $customer)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('edit customers') &&
             $authUser->company_id === $customer->company_id),
            403
        );

        $customer->update(['is_active' => ! $customer->is_active]);

        return back()->with('success', __('messages.customer_status_updated'));
    }

    // ── Destroy ───────────────────────────────────────────────────
    public function destroy(Customer $customer)
    {
        $authUser = Auth::user();

        abort_unless(
            $authUser->is_super_admin ||
            ($authUser->hasPermissionTo('delete customers') &&
             $authUser->company_id === $customer->company_id),
            403
        );

        // Guard: cannot delete if linked to AR invoices or sales records
        if ($customer->arInvoices()->exists()) {
            return back()->withErrors([
                'customer' => __('messages.customer_has_ar_invoices'),
            ]);
        }

        if ($customer->salesRecords()->exists()) {
            return back()->withErrors([
                'customer' => __('messages.customer_has_sales_records'),
            ]);
        }

        // Clear partner link on the supplier side before deleting
        if ($customer->supplier_id) {
            Supplier::where('id', $customer->supplier_id)
                ->update(['is_customer' => false]);
        }

        $customer->delete();

        return redirect()->route('customers.index')
            ->with('success', __('messages.customer_deleted'));
    }

    // ══════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ══════════════════════════════════════════════════════════════

    /**
     * §5.3 — Convert Inertia '' → null before validation.
     */
  

    /**
     * Validate customer input.
     * Bilingual rule §5.2: both _en/_ar nullable, cross-field guard applied.
     */
    private function validateCustomer(Request $request, ?int $ignoreId = null): array
    {
        $validated = $request->validate([
            'company_id'         => 'sometimes|exists:companies,id',
            'name_en'            => 'nullable|string|max:255',
            'name_ar'            => 'nullable|string|max:255',
            'code'               => [
                'nullable', 'string', 'max:50',
                Rule::unique('customers', 'code')
                    ->where('company_id', request()->input('company_id', auth()->user()->company_id))
                    ->ignore($ignoreId),
            ],
            'contact_person'     => 'nullable|string|max:255',
            'phone'              => 'nullable|string|max:50',
            'email'              => 'nullable|email|max:255',
            'address_en'         => 'nullable|string|max:255',
            'address_ar'         => 'nullable|string|max:255',
            'city'               => 'nullable|string|max:255',
            'country'            => 'nullable|string|max:2',
            'tax_number'         => 'nullable|string|max:50',
            'payment_terms_days' => 'nullable|integer|min:0|max:365',
            'is_active'          => 'sometimes|boolean',
            // Partner link fields
            'is_supplier'        => 'sometimes|boolean',
            'supplier_id'        => 'nullable|exists:suppliers,id',
            'notes'              => 'nullable|string',
        ]);

        // ── Cross-field guard (§5.2) ──────────────────────────────
        if (empty($validated['name_en']) && empty($validated['name_ar'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'name_en' => __('validation.required', ['attribute' => 'customer name']),
            ]);
        }

        // Guard: supplier_id must belong to same company
        if (! empty($validated['supplier_id'])) {
            $companyId = $validated['company_id']
                ?? auth()->user()->company_id;
            $supplier = Supplier::find($validated['supplier_id']);
            if (! $supplier || $supplier->company_id !== (int) $companyId) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'supplier_id' => __('validation.exists', ['attribute' => 'supplier']),
                ]);
            }
        }

        return $validated;
    }

    /**
     * When a customer is linked to a supplier via supplier_id,
     * mark that supplier as is_customer = true.
     * When is_supplier = false, clear the supplier link.
     */
    private function syncPartnerLink(Customer $customer, array $validated): void
    {
        $isSupplier = $validated['is_supplier'] ?? false;
        $supplierId = $validated['supplier_id'] ?? null;

        if ($isSupplier && $supplierId) {
            // Mark the supplier as also a customer
            Supplier::where('id', $supplierId)
                ->update(['is_customer' => true]);
        } elseif (! $isSupplier && $customer->supplier_id) {
            // Clear the old supplier's is_customer flag
            Supplier::where('id', $customer->supplier_id)
                ->update(['is_customer' => false]);
        }
    }

    /**
     * Before updating a customer, clear the old partner link
     * if supplier_id is changing or is_supplier is being turned off.
     */
    private function clearOldPartnerLink(Customer $customer, array $validated): void
    {
        $oldSupplierId  = $customer->supplier_id;
        $newSupplierId  = $validated['supplier_id'] ?? null;
        $newIsSupplier  = $validated['is_supplier'] ?? false;

        // If the supplier link is being removed or changed, clear the old one
        if ($oldSupplierId && ($oldSupplierId !== $newSupplierId || ! $newIsSupplier)) {
            Supplier::where('id', $oldSupplierId)
                ->update(['is_customer' => false]);
        }
    }

    /**
     * Active suppliers for the current company — used in create/edit dropdowns.
     */
    private function availableSuppliers($authUser): \Illuminate\Support\Collection
    {
        $query = Supplier::where('is_active', true)
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar', 'code', 'is_customer']);

        if (! $authUser->is_super_admin) {
            $query = Supplier::where('company_id', $authUser->company_id)
                ->where('is_active', true)
                ->orderBy('name_en')
                ->get(['id', 'name_en', 'name_ar', 'code', 'is_customer']);
        }

        return $query;
    }

    /**
     * Format customer for Index page (list view).
     */
    private function formatCustomer(Customer $customer): array
    {
        return [
            'id'                  => $customer->id,
            'company_id'          => $customer->company_id,
            'name_en'             => $customer->name_en,
            'name_ar'             => $customer->name_ar,
            'code'                => $customer->code,
            'contact_person'      => $customer->contact_person,
            'phone'               => $customer->phone,
            'email'               => $customer->email,
            'city'                => $customer->city,
            'country'             => $customer->country,
            'payment_terms_days'  => $customer->payment_terms_days,
            'is_active'           => $customer->is_active,
            'is_supplier'         => $customer->is_supplier,
            'supplier_id'         => $customer->supplier_id,
            'odoo_partner_id'     => $customer->odoo_partner_id,
            'ar_invoices_count'   => $customer->ar_invoices_count   ?? 0,
            'sales_records_count' => $customer->sales_records_count ?? 0,
            'created_at'          => $customer->created_at?->format('d M Y'),
        ];
    }

    /**
     * Format customer for CreateEdit page (full fields).
     */
    private function formatCustomerFull(Customer $customer): array
    {
        return [
            'id'                  => $customer->id,
            'company_id'          => $customer->company_id,
            'name_en'             => $customer->name_en,
            'name_ar'             => $customer->name_ar,
            'code'                => $customer->code,
            'contact_person'      => $customer->contact_person,
            'phone'               => $customer->phone,
            'email'               => $customer->email,
            'address_en'          => $customer->address_en,
            'address_ar'          => $customer->address_ar,
            'city'                => $customer->city,
            'country'             => $customer->country,
            'tax_number'          => $customer->tax_number,
            'payment_terms_days'  => $customer->payment_terms_days,
            'is_active'           => $customer->is_active,
            'is_supplier'         => $customer->is_supplier,
            'supplier_id'         => $customer->supplier_id,
            'odoo_partner_id'     => $customer->odoo_partner_id,
            'notes'               => $customer->notes,
            'created_at'          => $customer->created_at?->format('d M Y'),
            'updated_at'          => $customer->updated_at?->format('d M Y'),
        ];
    }
}