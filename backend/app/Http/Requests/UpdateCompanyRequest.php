<?php

namespace App\Http\Requests;

use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('company.manage');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('group_prefix')) {
            $value = $this->group_prefix;
            // Üres string = prefix törlési szándék → null-lá alakítjuk
            $this->merge(['group_prefix' => filled($value) ? strtoupper((string) $value) : null]);
        }
    }

    public function rules(): array
    {
        $companyId = app(CurrentCompany::class)->id();

        return [
            // 'sometimes': a kliens résszelet is küldhet (pl. csak group_prefix-et) anélkül,
            // hogy a teljes cégobjektumot vissza kellene küldenie — ha a mező jelen van, a
            // 'required' továbbra is érvényesül, csak hiányzó mezőnél nem várjuk el.
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'tax_number' => ['sometimes', 'required', 'regex:/^\d{8}-\d-\d{2}$/'],
            'eu_tax_number' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['sometimes', 'required', 'string', 'max:255'],
            'postal_code' => ['sometimes', 'required', 'string', 'max:10'],
            'city' => ['sometimes', 'required', 'string', 'max:255'],
            'address_line' => ['sometimes', 'required', 'string', 'max:255'],
            'country_code' => ['sometimes', 'required', 'string', 'size:2'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'invoice_header_text' => ['nullable', 'string'],
            'invoice_footer_text' => ['nullable', 'string'],
            'base_currency' => ['sometimes', 'required', 'string', 'size:3'],
            'is_active' => ['boolean'],
            // nav_environment NEM módosítható PUT /api/company úton — csak
            // PATCH /api/company/nav/active-environment-en át (védőhálóval).

            // 'sometimes': ha a kliens nem küldi, kimarad a validated tömbből, és a prefix-guard nem lép életbe.
            'group_prefix' => ['sometimes', 'nullable', 'string', 'max:4', 'alpha',
                Rule::unique('companies', 'group_prefix')->ignore($companyId)],
        ];
    }
}
