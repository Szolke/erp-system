<?php

namespace App\Http\Resources\Concerns;

/**
 * created_by/updated_by blame-adat egységes serializációja a törzsadat
 * JsonResource-okban. A becsomagolt modellnek a HasBlameable traitet kell
 * használnia (app/Models/Concerns/HasBlameable.php) — az alak-építés ott él,
 * ez a trait csak adapter.
 *
 * A két kulcs whenLoaded()-del KAPUZOTT: csak akkor kerül a válaszba, ha a
 * hívó eager-loadolta a creator/updater relációt. Ez szándékos: ugyanezt a
 * Resource-t használják a LISTA (index) végpontok is, amelyek nem töltik a
 * relációkat — ott tehát a két kulcs egyszerűen kimarad, nincs N+1 lekérdezés
 * és nem zsúfolódik a lista. A blame-adat kizárólag a DETAIL végpontokon
 * jelenik meg, ahol a controller loadMissing()-gel előre tölt.
 *
 * Használat a toArray()-ben, a saját mezők után kiterítve:
 *
 *     return [
 *         'id' => $this->id,
 *         …
 *         ...$this->blame(),
 *     ];
 *
 * @mixin \Illuminate\Http\Resources\Json\JsonResource
 */
trait WithBlameable
{
    /**
     * @return array{created_by: mixed, updated_by: mixed}
     */
    protected function blame(): array
    {
        $model = $this->resource;

        return [
            'created_by' => $this->whenLoaded(
                'creator',
                fn () => $model->blameEntry('created_by', 'creator', 'created_at')
            ),
            'updated_by' => $this->whenLoaded(
                'updater',
                fn () => $model->blameEntry('updated_by', 'updater', 'updated_at')
            ),
        ];
    }
}
