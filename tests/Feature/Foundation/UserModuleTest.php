<?php

namespace Tests\Feature\Foundation;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\User\Models\User;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Tests\TestCase;

class UserModuleTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    // ─── Authentication ────────────────────────────────────────────────────

    public function test_unauthenticated_user_redirected_to_login(): void
    {
        $this->get('/users')->assertRedirect('/login');
    }

    // ─── Authorisation ─────────────────────────────────────────────────────

    public function test_staff_without_view_permission_gets_403_on_user_list(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $staff   = $this->createUser($prop, 'staff');

        $this->actingAs($staff)->get('/users')->assertForbidden();
    }

    public function test_property_admin_can_list_users(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin)->get('/users')->assertOk();
    }

    // ─── Property Isolation ────────────────────────────────────────────────

    public function test_admin_cannot_view_user_from_other_property(): void
    {
        $company  = $this->createCompany();
        $propA    = $this->createProperty($company);
        $propB    = $this->createProperty($company, ['code' => 'PB10']);
        $adminA   = $this->createPropertyAdmin($propA);
        $userB    = $this->createUser($propB, 'staff');

        $this->actingAs($adminA)
            ->get("/users/{$userB->id}")
            ->assertForbidden();
    }

    public function test_super_admin_can_view_user_from_any_property(): void
    {
        $super    = $this->createSuperAdmin();
        $company  = $this->createCompany();
        $propA    = $this->createProperty($company);
        $userA    = $this->createUser($propA, 'staff');

        $this->actingAs($super)
            ->get("/users/{$userA->id}")
            ->assertOk();
    }

    // ─── CRUD ──────────────────────────────────────────────────────────────

    public function test_admin_can_create_user(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin)->post('/users', [
            'name'                  => 'New Staff',
            'email'                 => 'newstaff@test.com',
            'password'              => 'password1234',
            'password_confirmation' => 'password1234',
        ])->assertRedirect();

        $user = User::where('email', 'newstaff@test.com')->first();
        $this->assertNotNull($user);
        
        $this->assertDatabaseHas('property_user', [
            'user_id'     => $user->id,
            'property_id' => $prop->id,
        ]);
    }

    public function test_email_must_be_unique(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);
        $this->createUser($prop, 'staff', ['email' => 'taken@test.com']);

        $this->actingAs($admin)->post('/users', [
            'name'                  => 'Duplicate',
            'email'                 => 'taken@test.com',
            'password'              => 'password1234',
            'password_confirmation' => 'password1234',
        ])->assertSessionHasErrors('email');
    }

    public function test_admin_can_update_user(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);
        $user    = $this->createUser($prop, 'staff');

        $this->actingAs($admin)
            ->put("/users/{$user->id}", ['name' => 'Updated Name'])
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated Name']);
    }

    public function test_user_cannot_update_user_from_other_property(): void
    {
        $company = $this->createCompany();
        $propA   = $this->createProperty($company);
        $propB   = $this->createProperty($company, ['code' => 'PB20']);
        $adminA  = $this->createPropertyAdmin($propA);
        $userB   = $this->createUser($propB, 'staff');

        $this->actingAs($adminA)
            ->put("/users/{$userB->id}", ['name' => 'Hacked'])
            ->assertForbidden();
    }

    public function test_admin_can_soft_delete_user(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);
        $user    = $this->createUser($prop, 'staff');

        $this->actingAs($admin)
            ->delete("/users/{$user->id}")
            ->assertRedirect('/users');

        $this->assertSoftDeleted('users', ['id' => $user->id]);
    }

    public function test_user_cannot_delete_themselves(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin)
            ->delete("/users/{$admin->id}")
            ->assertForbidden();
    }

    // ─── Profile ───────────────────────────────────────────────────────────

    public function test_user_can_view_own_profile(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $user    = $this->createUser($prop, 'staff');

        $this->actingAs($user)->get('/profile')->assertOk();
    }

    public function test_user_can_update_own_profile(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $user    = $this->createUser($prop, 'staff');

        $this->actingAs($user)
            ->put('/profile', ['name' => 'My New Name'])
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'My New Name']);
    }

    public function test_user_can_change_password_with_correct_current_password(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $user    = $this->createUser($prop, 'staff', ['password' => 'oldpassword']);

        $this->actingAs($user)->put('/profile/password', [
            'current_password'      => 'oldpassword',
            'password'              => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertRedirect();
    }

    public function test_wrong_current_password_fails_password_change(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $user    = $this->createUser($prop, 'staff', ['password' => 'correctpassword']);

        $this->actingAs($user)->put('/profile/password', [
            'current_password'      => 'wrongpassword',
            'password'              => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertSessionHasErrors('current_password');
    }

    // ─── Audit trail ───────────────────────────────────────────────────────

    public function test_creating_user_generates_audit_log(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin)->post('/users', [
            'name'                  => 'Audited User',
            'email'                 => 'audited@test.com',
            'password'              => 'password1234',
            'password_confirmation' => 'password1234',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => User::class,
            'event'          => 'created',
        ]);
    }

    public function test_audit_log_does_not_contain_password(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);

        $this->actingAs($admin)->post('/users', [
            'name'                  => 'Secret User',
            'email'                 => 'secret@test.com',
            'password'              => 'mysecretpassword',
            'password_confirmation' => 'mysecretpassword',
        ]);

        $log = AuditLog::where('auditable_type', User::class)
            ->where('event', 'created')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($log);
        $newValues = $log->new_values;
        $this->assertArrayNotHasKey('password', $newValues ?? []);
    }

    // ─── Show / Edit pages ────────────────────────────────────────────────────

    public function test_admin_can_view_user_show(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);
        $user    = $this->createUser($prop, 'staff');

        $this->actingAs($admin)->get("/users/{$user->id}")->assertOk();
    }

    public function test_staff_cannot_access_user_create_form(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $staff   = $this->createUser($prop, 'staff');

        $this->actingAs($staff)->get('/users/create')->assertForbidden();
    }

    public function test_admin_can_access_user_edit_form(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);
        $user    = $this->createUser($prop, 'staff');

        $this->actingAs($admin)->get("/users/{$user->id}/edit")->assertOk();
    }

    public function test_updating_user_generates_audit_log(): void
    {
        $company = $this->createCompany();
        $prop    = $this->createProperty($company);
        $admin   = $this->createPropertyAdmin($prop);
        $user    = $this->createUser($prop, 'staff');

        $this->actingAs($admin)->put("/users/{$user->id}", ['name' => 'Audited Update']);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => User::class,
            'auditable_id'   => $user->id,
            'event'          => 'updated',
        ]);
    }
}
