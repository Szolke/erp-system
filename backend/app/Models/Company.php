<?php

namespace App\Models;

use App\Enums\NavEnvironment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'tax_number', 'eu_tax_number', 'registration_number',
    'postal_code', 'city', 'address_line', 'country_code',
    'email', 'phone', 'logo_path', 'invoice_header_text', 'invoice_footer_text',
    'base_currency', 'is_active', 'nav_environment',
])]
class Company extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'nav_environment' => NavEnvironment::class,
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(CompanyBankAccount::class);
    }

    public function navCredentials(): HasMany
    {
        return $this->hasMany(CompanyNavCredential::class);
    }

    public function simplePayCredentials(): HasMany
    {
        return $this->hasMany(CompanySimplePayCredential::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function partners(): HasMany
    {
        return $this->hasMany(Partner::class);
    }

    public function documentSeries(): HasMany
    {
        return $this->hasMany(DocumentSeries::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function enabledModules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'company_module')
            ->using(CompanyModule::class)
            ->withPivot('enabled', 'enabled_at', 'enabled_by')
            ->withTimestamps();
    }
}
