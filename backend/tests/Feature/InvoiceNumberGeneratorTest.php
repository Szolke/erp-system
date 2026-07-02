<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\DocumentSeries;
use App\Services\InvoiceNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(string $name = 'Teszt Kft.'): Company
    {
        static $seq = 0;
        $seq++;
        return Company::create([
            'name'                => $name,
            'tax_number'          => sprintf('%08d-1-42', $seq),
            'registration_number' => "01-09-{$seq}",
            'postal_code'         => '1000',
            'city'                => 'Budapest',
            'address_line'        => 'Teszt utca 1.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
        ]);
    }

    // --- Alap sorrend ---

    public function test_first_number_starts_at_one(): void
    {
        $company = $this->makeCompany();
        [, $number] = app(InvoiceNumberGenerator::class)->next($company->id, DocumentType::Invoice, 'SZ');

        $this->assertStringEndsWith('-000001', $number);
    }

    public function test_consecutive_numbers_are_sequential_without_gaps(): void
    {
        $company = $this->makeCompany();
        $gen     = app(InvoiceNumberGenerator::class);

        [, $n1] = $gen->next($company->id, DocumentType::Invoice, 'SZ');
        [, $n2] = $gen->next($company->id, DocumentType::Invoice, 'SZ');
        [, $n3] = $gen->next($company->id, DocumentType::Invoice, 'SZ');

        $this->assertStringEndsWith('-000001', $n1);
        $this->assertStringEndsWith('-000002', $n2);
        $this->assertStringEndsWith('-000003', $n3);
    }

    // --- Formátum ---

    public function test_number_format_is_prefix_yyyymm_padded_6_digits(): void
    {
        $company  = $this->makeCompany();
        [, $number] = app(InvoiceNumberGenerator::class)->next($company->id, DocumentType::Invoice, 'SZ');

        $yyyymm = now()->format('Ym');
        $this->assertSame("SZ-{$yyyymm}-000001", $number);
    }

    // --- Cég-szintű izoláció ---

    public function test_series_are_isolated_per_company(): void
    {
        $companyA = $this->makeCompany('A Kft.');
        $companyB = $this->makeCompany('B Kft.');
        $gen      = app(InvoiceNumberGenerator::class);

        // A cégnél 3 szám lefoglalva
        $gen->next($companyA->id, DocumentType::Invoice, 'SZ');
        $gen->next($companyA->id, DocumentType::Invoice, 'SZ');
        $gen->next($companyA->id, DocumentType::Invoice, 'SZ');

        // B cégnél az első szám 1-től indul
        [, $bFirst] = $gen->next($companyB->id, DocumentType::Invoice, 'SZ');

        $this->assertStringEndsWith('-000001', $bFirst);
    }

    // --- Bizonylat-típus szintű izoláció ---

    public function test_series_are_isolated_per_document_type(): void
    {
        $company = $this->makeCompany();
        $gen     = app(InvoiceNumberGenerator::class);

        $gen->next($company->id, DocumentType::Invoice, 'SZ');
        $gen->next($company->id, DocumentType::Invoice, 'SZ');

        // NY sorozat teljesen független SZ-től
        [, $receiptFirst] = $gen->next($company->id, DocumentType::Receipt, 'NY');

        $this->assertStringStartsWith('NY-', $receiptFirst);
        $this->assertStringEndsWith('-000001', $receiptFirst);
    }

    public function test_all_four_series_are_independent_from_each_other(): void
    {
        $company = $this->makeCompany();
        $gen     = app(InvoiceNumberGenerator::class);

        $cases = [
            [DocumentType::Invoice,       'SZ'],
            [DocumentType::Receipt,       'NY'],
            [DocumentType::InvoiceStorno, 'SZSZT'],
            [DocumentType::ReceiptStorno, 'NYSZT'],
        ];

        foreach ($cases as [$type, $prefix]) {
            $gen->next($company->id, $type, $prefix);
            $gen->next($company->id, $type, $prefix);
            [, $third] = $gen->next($company->id, $type, $prefix);

            $this->assertStringStartsWith("{$prefix}-", $third, "Prefix eltérés: {$prefix}");
            $this->assertStringEndsWith('-000003', $third, "Sorrend eltérés: {$prefix}");
        }
    }

    // --- Perzisztencia ---

    public function test_next_number_is_persisted_in_database(): void
    {
        $company = $this->makeCompany();
        $gen     = app(InvoiceNumberGenerator::class);

        $gen->next($company->id, DocumentType::Invoice, 'SZ');
        $gen->next($company->id, DocumentType::Invoice, 'SZ');

        $series = DocumentSeries::withoutGlobalScope('company')
            ->where('company_id', $company->id)
            ->where('document_type', DocumentType::Invoice)
            ->first();

        $this->assertSame(3, $series->next_number);
    }

    public function test_uses_existing_series_instead_of_creating_new(): void
    {
        $company = $this->makeCompany();

        // Előre létrehozzuk a sorozatot next_number = 7-tel
        DocumentSeries::withoutGlobalScope('company')->create([
            'company_id'    => $company->id,
            'document_type' => DocumentType::Invoice->value,
            'prefix'        => 'SZ',
            'reset_yearly'  => false,
            'next_number'   => 7,
        ]);

        [, $number] = app(InvoiceNumberGenerator::class)->next($company->id, DocumentType::Invoice, 'SZ');

        $this->assertStringEndsWith('-000007', $number);
    }

    // --- Éves nullázás ---

    public function test_yearly_reset_restarts_sequence_on_new_year(): void
    {
        $company = $this->makeCompany();

        // Létrehozunk egy sorozatot, ami tavalyi évnél jár
        DocumentSeries::withoutGlobalScope('company')->create([
            'company_id'      => $company->id,
            'document_type'   => DocumentType::Invoice->value,
            'prefix'          => 'SZ',
            'reset_yearly'    => true,
            'last_reset_year' => (int) now()->format('Y') - 1,
            'next_number'     => 99,
        ]);

        [, $number] = app(InvoiceNumberGenerator::class)->next($company->id, DocumentType::Invoice, 'SZ');

        // Az éves nullázás miatt 1-től indul újra
        $this->assertStringEndsWith('-000001', $number);
    }

    public function test_no_reset_when_reset_yearly_is_false(): void
    {
        $company = $this->makeCompany();

        DocumentSeries::withoutGlobalScope('company')->create([
            'company_id'      => $company->id,
            'document_type'   => DocumentType::Invoice->value,
            'prefix'          => 'SZ',
            'reset_yearly'    => false,
            'last_reset_year' => (int) now()->format('Y') - 1,
            'next_number'     => 50,
        ]);

        [, $number] = app(InvoiceNumberGenerator::class)->next($company->id, DocumentType::Invoice, 'SZ');

        $this->assertStringEndsWith('-000050', $number);
    }
}
