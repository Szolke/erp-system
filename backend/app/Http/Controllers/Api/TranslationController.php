<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Translation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TranslationController extends Controller
{
    private const LOCALES = ['hu', 'en', 'de'];

    /**
     * Public endpoint — returns flat key→value map for the given locale.
     * Used by the frontend to load translations on startup.
     * Format: { "nav.documents": "Bizonylatok", ... }
     */
    public function forLocale(string $locale)
    {
        if (! in_array($locale, self::LOCALES, true)) {
            return response()->json(['error' => 'Unsupported locale'], 422);
        }

        $rows = Translation::where('locale', $locale)->get(['namespace', 'key', 'value']);

        $map = [];
        foreach ($rows as $row) {
            $map["{$row->namespace}.{$row->key}"] = $row->value;
        }

        return response()->json($map);
    }

    /**
     * Admin endpoint — returns all keys with values for every locale.
     * Used by the translation manager page.
     * Format: [{ namespace, key, hu, en, de }, ...]
     */
    public function index(Request $request)
    {
        $this->authorize('company.manage');

        $search = $request->string('search')->trim()->value();
        $ns     = $request->string('namespace')->trim()->value();

        $query = DB::table('translations')
            ->select('namespace', 'key', 'locale', 'value')
            ->orderBy('namespace')
            ->orderBy('key')
            ->orderBy('locale');

        if ($ns !== '') {
            $query->where('namespace', $ns);
        }
        if ($search !== '') {
            $query->where(fn ($w) => $w
                ->where('key', 'ilike', "%{$search}%")
                ->orWhere('value', 'ilike', "%{$search}%")
            );
        }

        $rows = $query->get();

        // Group by namespace+key, pivot locales into columns
        $grouped = [];
        foreach ($rows as $row) {
            $k = "{$row->namespace}|{$row->key}";
            if (! isset($grouped[$k])) {
                $grouped[$k] = ['namespace' => $row->namespace, 'key' => $row->key, 'hu' => '', 'en' => '', 'de' => ''];
            }
            if (in_array($row->locale, self::LOCALES, true)) {
                $grouped[$k][$row->locale] = $row->value;
            }
        }

        $namespaces = DB::table('translations')->distinct()->orderBy('namespace')->pluck('namespace');

        return response()->json([
            'data'       => array_values($grouped),
            'namespaces' => $namespaces,
        ]);
    }

    /**
     * Upsert translation values for one key across one or more locales.
     * Body: { hu?: string, en?: string, de?: string }
     */
    public function upsert(Request $request, string $namespace, string $key)
    {
        $this->authorize('company.manage');

        $data = $request->validate([
            'hu' => ['nullable', 'string', 'max:2000'],
            'en' => ['nullable', 'string', 'max:2000'],
            'de' => ['nullable', 'string', 'max:2000'],
        ]);

        $now = now();
        foreach (self::LOCALES as $locale) {
            if (array_key_exists($locale, $data) && $data[$locale] !== null) {
                DB::table('translations')->upsert(
                    ['namespace' => $namespace, 'key' => $key, 'locale' => $locale, 'value' => $data[$locale], 'created_at' => $now, 'updated_at' => $now],
                    ['namespace', 'key', 'locale'],
                    ['value', 'updated_at'],
                );
            }
        }

        return response()->json(['message' => 'OK']);
    }

    /** Update the authenticated user's locale preference. */
    public function updateLocale(Request $request)
    {
        $data = $request->validate([
            'locale' => ['required', 'string', 'in:' . implode(',', self::LOCALES)],
        ]);

        $request->user()->update(['locale' => $data['locale']]);

        return response()->json(['locale' => $data['locale']]);
    }
}
