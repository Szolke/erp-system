<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    protected function perPage(Request $request, int $default = 20): int
    {
        $allowed = [20, 50, 100, 200, 500, 1000];
        $value   = $request->integer('per_page', $default);

        return in_array($value, $allowed, true) ? $value : $default;
    }
}
