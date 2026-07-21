<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Modules\ModuleResolver;
use App\Services\Enyugta\ReceiptReportBuilder;
use App\Services\Enyugta\ReceiptReportBuildException;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

class BuildReceiptReports extends Command
{
    protected $signature = 'erp:build-receipt-reports {--date=} {--company=}';

    protected $description = 'Builds the daily NAV eNyugta receipt report for every company with the enyugta module enabled (default: yesterday, Europe/Budapest).';

    public function handle(ReceiptReportBuilder $builder, ModuleResolver $resolver): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'), 'Europe/Budapest')
            : now('Europe/Budapest')->subDay();

        $companies = $this->option('company')
            ? Company::withoutGlobalScope('company')->whereKey((int) $this->option('company'))->get()
            : Company::withoutGlobalScope('company')->get();

        $exitCode = self::SUCCESS;

        foreach ($companies as $company) {
            if (! in_array('enyugta', $resolver->enabledModuleKeys($company->id), true)) {
                $this->line(sprintf('[%s] eNyugta modul nincs bekapcsolva — kihagyva.', $company->name));

                continue;
            }

            try {
                $report = $builder->build($company, $date);

                $this->info(sprintf(
                    '[%s] %s — %s jelentés (%s), %d nyugta, %s Ft bruttó.',
                    $company->name,
                    $date->toDateString(),
                    $report->type->value,
                    $report->status->value,
                    $report->receipt_count,
                    number_format((float) $report->total_gross, 0, ',', ' '),
                ));
            } catch (ReceiptReportBuildException $e) {
                // D4 (hiányzó áfa-megfeleltetés) és a nem-HUF-nyugta eset egyaránt
                // idetartozik — tiszta hibaüzenet, NEM stack trace, de a többi cég
                // feldolgozása folytatódik (a nav:check-submission-status mintája).
                $this->error(sprintf('[%s] %s — %s', $company->name, $date->toDateString(), $e->getMessage()));
                $exitCode = self::FAILURE;
            } catch (Throwable $e) {
                $this->error(sprintf('[%s] %s — váratlan hiba: %s', $company->name, $date->toDateString(), $e->getMessage()));
                $exitCode = self::FAILURE;
            }
        }

        return $exitCode;
    }
}
