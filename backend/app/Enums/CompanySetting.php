<?php

namespace App\Enums;

/**
 * Central registry of all allowed per-company settings.
 * Types, defaults, and sensitivity live here — not in the DB.
 * Add new keys here; the DB schema never changes for new settings.
 */
enum CompanySetting: string
{
    // ── NAV Online Számla (5. fázis) ──────────────────────────────────────
    case NAV_ENABLED     = 'nav_enabled';      // boolean  – default: false
    case NAV_ENVIRONMENT = 'nav_environment';  // string   – 'test' | 'production'

    // ── SimplePay (6. fázis) ──────────────────────────────────────────────
    case SIMPLEPAY_ENABLED = 'simplepay_enabled';  // boolean – default: false

    // ── Általános számlázási beállítások ──────────────────────────────────
    case DEFAULT_CURRENCY = 'default_currency';  // string  – 'HUF' | 'EUR' | 'USD'
    case INVOICE_LANGUAGE = 'invoice_language';  // string  – 'hu' | 'en' | 'de'
    case INVOICE_DUE_DAYS = 'invoice_due_days';  // integer – fizetési határidő napokban

    // ── UI / megjelenés ───────────────────────────────────────────────────
    case SIDEBAR_ACCENT_COLOR = 'sidebar_accent_color';  // string – hex paletta-szín

    /** Value type: 'boolean' | 'string' | 'integer' | 'encrypted' */
    public function type(): string
    {
        return match ($this) {
            self::NAV_ENABLED,
            self::SIMPLEPAY_ENABLED       => 'boolean',
            self::INVOICE_DUE_DAYS        => 'integer',
            self::NAV_ENVIRONMENT,
            self::DEFAULT_CURRENCY,
            self::INVOICE_LANGUAGE,
            self::SIDEBAR_ACCENT_COLOR    => 'string',
        };
    }

    /** Default value returned when no DB record exists for this key. */
    public function default(): mixed
    {
        return match ($this) {
            self::NAV_ENABLED             => false,
            self::SIMPLEPAY_ENABLED       => false,
            self::NAV_ENVIRONMENT         => 'test',
            self::DEFAULT_CURRENCY        => 'HUF',
            self::INVOICE_LANGUAGE        => 'hu',
            self::INVOICE_DUE_DAYS        => 8,
            self::SIDEBAR_ACCENT_COLOR    => '#1e293b',
        };
    }

    /**
     * Whether the value should be masked in audit logs.
     * Encrypted keys are automatically sensitive.
     */
    public function sensitive(): bool
    {
        return $this->type() === 'encrypted';
    }

    /** Human-readable label for the UI (translation key). */
    public function label(): string
    {
        return match ($this) {
            self::NAV_ENABLED             => 'settings.nav_enabled',
            self::NAV_ENVIRONMENT         => 'settings.nav_environment',
            self::SIMPLEPAY_ENABLED       => 'settings.simplepay_enabled',
            self::DEFAULT_CURRENCY        => 'settings.default_currency',
            self::INVOICE_LANGUAGE        => 'settings.invoice_language',
            self::INVOICE_DUE_DAYS        => 'settings.invoice_due_days',
            self::SIDEBAR_ACCENT_COLOR    => 'settings.sidebar_accent_color',
        };
    }

    public const SIDEBAR_ACCENT_PALETTE = [
        '#1e293b', // slate  (alapértelmezett)
        '#1e3a5f', // mélykék
        '#134e4a', // teal
        '#14532d', // erdőzöld
        '#3b0764', // lila
        '#4c0519', // bordó
        '#78350f', // sötét arany
    ];

    /** Validate a raw (string) value for this setting. */
    public function validate(mixed $value): bool
    {
        return match ($this) {
            self::NAV_ENVIRONMENT        => in_array($value, ['test', 'production'], true),
            self::DEFAULT_CURRENCY       => in_array($value, ['HUF', 'EUR', 'USD'], true),
            self::INVOICE_LANGUAGE       => in_array($value, ['hu', 'en', 'de'], true),
            self::INVOICE_DUE_DAYS       => is_numeric($value) && (int) $value >= 0 && (int) $value <= 365,
            self::SIDEBAR_ACCENT_COLOR   => in_array($value, self::SIDEBAR_ACCENT_PALETTE, true),
            default                      => true,
        };
    }
}
