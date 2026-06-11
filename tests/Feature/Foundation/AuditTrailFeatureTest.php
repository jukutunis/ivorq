<?php

namespace Tests\Feature\Foundation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\TestCase;

class AuditTrailFeatureTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    public function test_audit_log_created_on_foundation_model_create(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $newUser = User::create([
            'property_id' => $property->id,
            'name'        => 'Test Audit User',
            'email'       => 'audit.test@example.com',
            'password'    => bcrypt('password'),
            'is_active'   => true,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => User::class,
            'auditable_id'   => $newUser->id,
            'event'          => 'created',
            'user_id'        => $admin->id,
        ]);

        $auditLog = AuditLog::where('auditable_type', User::class)
            ->where('auditable_id', $newUser->id)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($auditLog->new_values);
        $this->assertEmpty($auditLog->old_values);
        $this->assertEquals('Test Audit User', $auditLog->new_values['name']);
        $this->assertNotNull($auditLog->created_at);
    }

    public function test_audit_log_created_on_foundation_model_update(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $targetUser = User::create([
            'property_id' => $property->id,
            'name'        => 'Original Name',
            'email'       => 'original@example.com',
            'password'    => bcrypt('password'),
            'is_active'   => true,
        ]);

        $targetUser->update(['name' => 'Updated Name']);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => User::class,
            'auditable_id'   => $targetUser->id,
            'event'          => 'updated',
            'user_id'        => $admin->id,
        ]);

        $auditLog = AuditLog::where('auditable_type', User::class)
            ->where('auditable_id', $targetUser->id)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($auditLog->old_values);
        $this->assertNotNull($auditLog->new_values);
        $this->assertEquals('Original Name', $auditLog->old_values['name']);
        $this->assertEquals('Updated Name', $auditLog->new_values['name']);
        $this->assertNotNull($auditLog->created_at);
    }

    public function test_audit_log_created_on_foundation_model_delete(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $targetUser = User::create([
            'property_id' => $property->id,
            'name'        => 'To Be Deleted',
            'email'       => 'delete@example.com',
            'password'    => bcrypt('password'),
            'is_active'   => true,
        ]);

        $targetUser->delete();

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => User::class,
            'auditable_id'   => $targetUser->id,
            'event'          => 'deleted',
            'user_id'        => $admin->id,
        ]);

        $auditLog = AuditLog::where('auditable_type', User::class)
            ->where('auditable_id', $targetUser->id)
            ->where('event', 'deleted')
            ->first();

        $this->assertNotNull($auditLog->old_values);
        $this->assertEquals('To Be Deleted', $auditLog->old_values['name']);
        $this->assertNotNull($auditLog->created_at);
    }

    public function test_audit_log_created_on_foundation_model_restore(): void
    {
        $company  = $this->createCompany();
        $property = $this->createProperty($company);
        $admin    = $this->createPropertyAdmin($property);

        $this->actingAs($admin);
        app(CurrentPropertyService::class)->setId($property->id);

        $targetUser = User::create([
            'property_id' => $property->id,
            'name'        => 'To Be Restored',
            'email'       => 'restore@example.com',
            'password'    => bcrypt('password'),
            'is_active'   => true,
        ]);

        $targetUser->delete();
        $targetUser->restore();

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => User::class,
            'auditable_id'   => $targetUser->id,
            'event'          => 'restored',
            'user_id'        => $admin->id,
        ]);
        
        $auditLog = AuditLog::where('auditable_type', User::class)
            ->where('auditable_id', $targetUser->id)
            ->where('event', 'restored')
            ->first();

        $this->assertNotNull($auditLog->created_at);
    }
}
