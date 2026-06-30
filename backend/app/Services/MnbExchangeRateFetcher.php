<?php

namespace App\Services;

use SoapClient;
use SoapFault;

/**
 * Fetches the latest published HUF middle exchange rates from the Magyar
 * Nemzeti Bank SOAP service (https://www.mnb.hu/arfolyamok.asmx?wsdl,
 * GetCurrentExchangeRates). Confirmed response shape (2026-06):
 *
 *   <MNBCurrentExchangeRates>
 *     <Day date="2026-06-30">
 *       <Rate unit="1" curr="EUR">399,12</Rate>
 *       <Rate unit="100" curr="JPY">258,40</Rate>
 *       ...
 *     </Day>
 *   </MNBCurrentExchangeRates>
 *
 * Note the Hungarian-locale decimal comma in the rate value — must be
 * normalized to a dot before casting to float.
 */
class MnbExchangeRateFetcher
{
    private const WSDL = 'https://www.mnb.hu/arfolyamok.asmx?wsdl';

    /**
     * @return array<int, array{currency_code: string, rate_date: string, rate: float, unit: int}>
     *
     * @throws SoapFault
     */
    public function fetchCurrent(): array
    {
        $client = new SoapClient(self::WSDL, ['exceptions' => true]);
        $response = $client->GetCurrentExchangeRates();

        $xml = simplexml_load_string($response->GetCurrentExchangeRatesResult);

        $day = $xml->Day;
        $date = (string) $day['date'];

        $rates = [];

        foreach ($day->Rate as $rateNode) {
            $rawValue = trim((string) $rateNode);
            if ($rawValue === '' || $rawValue === '-') {
                continue; // MNB marks temporarily unpublished currencies with "-"
            }

            $rates[] = [
                'currency_code' => (string) $rateNode['curr'],
                'rate_date' => $date,
                'rate' => (float) str_replace(',', '.', $rawValue),
                'unit' => (int) $rateNode['unit'],
            ];
        }

        return $rates;
    }
}
