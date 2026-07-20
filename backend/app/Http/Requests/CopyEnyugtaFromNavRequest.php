<?php

namespace App\Http\Requests;

use App\Enums\NavEnvironment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CopyEnyugtaFromNavRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('enyugta.manage');
    }

    public function rules(): array
    {
        return [
            // Ha nincs megadva, a controller a cég jelenleg aktív
            // nav_environment-jét használja forrásként.
            'environment' => ['nullable', Rule::enum(NavEnvironment::class)],
        ];
    }
}
