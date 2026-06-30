<?php

namespace App\Services\Nav;

use App\Models\CompanyNavCredential;
use NavOnlineInvoice\Config;
use NavOnlineInvoice\Reporter;

class NavReporterFactory
{
    public function make(CompanyNavCredential $credential): Reporter
    {
        $apiUrl = config('nav.api_urls.'.$credential->environment->value);

        $userData = [
            'login' => $credential->nav_login,
            'password' => $credential->nav_password,
            'taxNumber' => $credential->nav_tax_number,
            'signKey' => $credential->nav_signing_key,
            'exchangeKey' => $credential->nav_exchange_key,
        ];

        $softwareCfg = config('nav.software');

        $softwareData = [
            'softwareId' => $softwareCfg['id'],
            'softwareName' => $softwareCfg['name'],
            'softwareOperation' => $softwareCfg['operation'],
            'softwareMainVersion' => $softwareCfg['main_version'],
            'softwareDevName' => $softwareCfg['dev_name'],
            'softwareDevContact' => $softwareCfg['dev_contact'],
            'softwareDevCountryCode' => $softwareCfg['dev_country_code'],
            'softwareDevTaxNumber' => $softwareCfg['dev_tax_number'],
        ];

        $config = new Config($apiUrl, $userData, $softwareData);

        return new Reporter($config);
    }
}
