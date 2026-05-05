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
    protected function sanitizeNullableFields(Request $request): void
    {
        $data = $request->all();
        array_walk_recursive($data, function (&$value) {
            if ($value === '') $value = null;
        });
        $request->merge($data);
    }
}