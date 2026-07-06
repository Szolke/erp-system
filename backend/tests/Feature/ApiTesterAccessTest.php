<?php

namespace Tests\Feature;

use App\Enums\PermissionEffect;
use App\Models\Company;
use App\Models\Permission;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTesterAccessTest extends TestCase
{
    use RefreshDatabase;

    private string $specPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->specPath = storage_path('app/private/scribe/openapi.yaml');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->specPath)) {
            unlink($this->specPath);
            @rmdir(dirname($this->specPath));
        }
        parent::tearDown();
    }

    private function placeFixtureSpec(): void
    {
        $dir = dirname($this->specPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->specPath, <<<'YAML'
openapi: "3.0.3"
info:
  title: ERP API
  version: "1.0"
paths:
  /api/invoices:
    get:
      summary: List invoices
YAML);
    }

    private function makeCompany(): Company
    {
        return Company::create([
            'name'                => 'Teszt Kft.',
            'tax_number'          => '11111111-1-42',
            'registration_number' => '01-09-000001',
            'postal_code'         => '1000',
            'city'                => 'Budapest',
            'address_line'        => 'Teszt utca 1.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
        ]);
    }

    public function test_guest_cannot_access_openapi_spec(): void
    {
        $this->placeFixtureSpec();

        $this->getJson('/api/api-tester/openapi')
            ->assertStatus(401);
    }

    public function test_user_without_permission_gets_403(): void
    {
        $this->placeFixtureSpec();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost')
            ->withSession([])
            ->getJson('/api/api-tester/openapi')
            ->assertStatus(403);
    }

    public function test_superadmin_gets_openapi_spec(): void
    {
        $this->placeFixtureSpec();

        // Gate::before shortcuts for superadmin — no company context needed.
        $user = User::factory()->create(['is_superadmin' => true]);

        $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost')
            ->withSession([])
            ->getJson('/api/api-tester/openapi')
            ->assertStatus(200)
            ->assertJsonPath('openapi', '3.0.3')
            ->assertJsonStructure(['openapi', 'info', 'paths']);
    }

    public function test_user_with_permission_gets_openapi_spec(): void
    {
        $this->placeFixtureSpec();

        $company = $this->makeCompany();
        $user = User::factory()->create();
        $company->users()->attach($user->id, ['is_default' => true]);

        $permission = Permission::where('key', 'api_tester.use')->firstOrFail();
        $user->permissionOverrides()->create([
            'company_id'    => $company->id,
            'permission_id' => $permission->id,
            'effect'        => PermissionEffect::Allow,
        ]);

        $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/api-tester/openapi')
            ->assertStatus(200)
            ->assertJsonPath('openapi', '3.0.3')
            ->assertJsonStructure(['openapi', 'info', 'paths']);
    }

    public function test_returns_503_when_spec_file_missing(): void
    {
        $this->assertFileDoesNotExist($this->specPath);

        // Superadmin bypasses permission check; 503 is a file-not-found response.
        $user = User::factory()->create(['is_superadmin' => true]);

        $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost')
            ->withSession([])
            ->getJson('/api/api-tester/openapi')
            ->assertStatus(503)
            ->assertJsonPath('message', fn ($v) => str_contains($v, 'scribe:generate'));
    }
}
