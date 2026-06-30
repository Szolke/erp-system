<?php

namespace App\Console\Commands;

use App\Models\ExchangeRate;
use App\Services\MnbExchangeRateFetcher;
use Illuminate\Console\Command;
use Throwable;

class FetchMnbExchangeRates extends Command
{
    protected $signature = 'exchange-rates:fetch-mnb';

    protected $description = 'Lekéri és elmenti az MNB napi középárfolyamait az exchange_rates táblába.';

    public function handle(MnbExchangeRateFetcher $fetcher): int
    {
        try {
            $rates = $fetcher->fetchCurrent();
        } catch (Throwable $e) {
            $this->error('MNB lekérdezés sikertelen: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($rates as $rate) {
            ExchangeRate::query()->updateOrCreate(
                ['currency_code' => $rate['currency_code'], 'rate_date' => $rate['rate_date']],
                ['rate' => $rate['rate'], 'unit' => $rate['unit'], 'source' => 'mnb']
            );
        }

        $this->info(count($rates).' árfolyam mentve/frissítve.');

        return self::SUCCESS;
    }
}
