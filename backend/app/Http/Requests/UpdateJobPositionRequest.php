<?php

namespace App\Http\Requests;

use App\Models\JobPosition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

class UpdateJobPositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('job_position.manage');
    }

    public function rules(): array
    {
        // A route-paraméter neve 'job_position' (kebab-case erőforrásnév →
        // snake_case wildcard, a SalesGroupController mintáját követve).
        $routeModel  = $this->route('job_position');
        $jobPosition = $routeModel instanceof JobPosition
            ? $routeModel
            : JobPosition::withoutGlobalScope('visibility')->find($routeModel);

        // company_id immutábilis update-ben (nem validált mező itt) — a
        // névegyediség köre a sor JELENLEGI (globális vagy céges) hovatartozása.
        $targetCompanyId = $jobPosition?->company_id;
        $jobPositionId   = $jobPosition?->id;

        return [
            'active'     => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'name'       => [
                'required', 'string', 'max:100',
                function ($attribute, $value, $fail) use ($targetCompanyId, $jobPositionId) {
                    $exists = DB::table('job_positions')
                        ->where('name', $value)
                        ->where('id', '!=', $jobPositionId)
                        ->when(
                            $targetCompanyId === null,
                            fn ($q) => $q->whereNull('company_id'),
                            fn ($q) => $q->where('company_id', $targetCompanyId)
                        )
                        ->exists();

                    if ($exists) {
                        $fail('Ilyen nevű munkakör már létezik ebben a láthatósági körben.');
                    }
                },
            ],
        ];
    }
}
