<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Az oszlop-DEFINÍCIÓ a frontend kódjában él, ezért itt csak a séma (kulcsok,
 * típusok, méretkorlátok) validált — hogy egy adott oszlopkulcs ténylegesen
 * létezik-e egy adott listánál, azt nem ellenőrizzük: a frontend betöltéskor
 * eldobja az ismeretlen kulcsokat, a hiányzókat pedig a kód szerinti
 * default-tal pótolja.
 *
 * Ismeretlen felső szintű (vagy `columns`/`sort` alatti) kulcsok NEM dobnak
 * hibát: mivel csak a lenti rules()-ban felsorolt kulcsokra van szabály,
 * a FormRequest::validated() ezeket automatikusan kihagyja az eredményből
 * (a Validator csak a szabállyal rendelkező kulcsokat építi vissza) — ez adja
 * a "mentés előtt szűrd ki" viselkedést extra kód nélkül.
 */
class UpdateListPreferenceRequest extends FormRequest
{
    private const KEY_PATTERN = '/^[a-z0-9_.]+$/';

    public function authorize(): bool
    {
        // Minden bejelentkezett felhasználó kezelheti a SAJÁT lista-preferenciáit —
        // nincs külön jogosultsági kulcs ehhez a feladathoz.
        return true;
    }

    public function rules(): array
    {
        return [
            'columns' => ['sometimes', 'array'],
            'columns.visible' => ['sometimes', 'array', 'max:100'],
            'columns.visible.*' => ['string', 'max:64', 'regex:'.self::KEY_PATTERN],
            'columns.order' => ['sometimes', 'array', 'max:100'],
            'columns.order.*' => ['string', 'max:64', 'regex:'.self::KEY_PATTERN],

            'page_size' => ['sometimes', 'integer', 'between:5,500'],

            'sort' => ['sometimes', 'array'],
            'sort.by' => ['sometimes', 'string', 'max:64', 'regex:'.self::KEY_PATTERN],
            'sort.dir' => ['sometimes', Rule::in(['asc', 'desc'])],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $size = strlen(json_encode($this->all()));

            if ($size > 8192) {
                $validator->errors()->add('preferences', 'A preferencia JSON mérete legfeljebb 8192 bájt lehet.');
            }
        });
    }
}
