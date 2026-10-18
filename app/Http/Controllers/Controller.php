<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Convert empty strings to null for nullable fields.
     * Inertia always sends '' for empty inputs; Laravel's nullable
     * rule requires actual null to pass. Call before validate().
     */
    /**
     * QA-030 — a plain calendar date ("2026-10-01") for the pages. A raw Carbon date would be sent as a
     * UTC timestamp, and because the app runs on Cairo time midnight becomes "2026-09-30T21:00:00Z"
     * (the previous day, and not readable by a date box).
     */
    protected function ymd($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return substr((string) $value, 0, 10);
    }

    protected function sanitizeNullableFields(Request $request): void
    {
        $data = $request->all();
        array_walk_recursive($data, function (&$value) {
            if ($value === '') $value = null;
        });
        $request->merge($data);
    }

    /**
     * Resolve the active company_id for the authenticated user.
     *
     * §6.5 — Session-based Super Admin pattern.
     *
     * Regular users   → always from auth user record (company_id column).
     * Super Admin     → from session('selected_company_id'), set when SA
     *                   clicks "Enter" on a company from Companies/Index.
     *
     * Aborts with 422 if Super Admin has not entered a company yet,
     * so the error is clear and actionable rather than silently writing
     * wrong data (company_id = 0 / NULL / first company in DB).
     *
     * Usage in any controller method:
     *   $companyId = $this->resolveCompanyId($request);
     */
    protected function resolveCompanyId(Request $request): int
    {
        $user = $request->user();

        if ($user->is_super_admin) {
            $id = (int) $request->session()->get('selected_company_id', 0);
            abort_unless(
                $id > 0,
                422,
                'No company selected. Please go to Companies and click Enter on a company first.'
            );
            return $id;
        }

        return (int) $user->company_id;
    }
}