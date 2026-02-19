<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\CustomerSource;
use App\Models\Query;
use App\Models\QueryItem;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UmrahMvpApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_token_for_active_user(): void
    {
        $user = User::factory()->create([
            'email' => 'tester@obokash.com',
            'password' => 'secret1234',
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'identifier' => $user->email,
            'password' => 'secret1234',
        ]);

        $response
            ->assertOk()
            ->assertJsonStructure([
                'message',
                'token',
                'user' => ['id', 'full_name', 'email', 'roles', 'permissions'],
            ]);
    }

    public function test_customer_create_and_edit_request_flow_works(): void
    {
        $user = $this->createUserWithPermissions(['customer.create', 'customer.edit', 'customer.view']);

        $category = CustomerCategory::query()->create([
            'category_name' => 'B2C',
            'is_active' => true,
        ]);

        $source = CustomerSource::query()->create([
            'source_name' => 'Mobile Call',
            'is_active' => true,
        ]);

        $createResponse = $this
            ->actingAs($user, 'sanctum')
            ->postJson('/api/customers', [
                'mobile_number' => '01712345678',
                'customer_name' => 'Customer A',
                'gender' => 'male',
                'whatsapp_number' => '01712345678',
                'visit_record' => 'no_record',
                'category_ids' => [$category->id],
                'source_id' => $source->id,
            ]);

        $createResponse->assertCreated();

        $customerId = (int) $createResponse->json('data.id');

        $this->assertDatabaseHas('customers', [
            'id' => $customerId,
            'mobile_number' => '+8801712345678',
            'customer_name' => 'Customer A',
        ]);

        $this
            ->actingAs($user, 'sanctum')
            ->patchJson('/api/customers/'.$customerId, [
                'customer_name' => 'Customer A Updated',
            ])
            ->assertOk();

        $this->assertDatabaseHas('customer_edit_requests', [
            'customer_id' => $customerId,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => 'customer',
            'auditable_id' => $customerId,
            'action' => 'customer.created',
        ]);
    }

    public function test_query_create_and_close_status_flow_works(): void
    {
        $user = $this->createUserWithPermissions(['query.create', 'query.view', 'query.change_status']);

        $customer = Customer::query()->create([
            'mobile_number' => '+8801711111111',
            'customer_name' => 'Query Customer',
            'gender' => 'male',
            'whatsapp_number' => '+8801711111111',
            'visit_record' => 'no_record',
            'is_active' => true,
        ]);

        $service = Service::query()->create([
            'service_name' => 'Umrah Package',
            'is_active' => true,
        ]);

        $createResponse = $this
            ->actingAs($user, 'sanctum')
            ->postJson('/api/queries', [
                'customer_id' => $customer->id,
                'query_details_text' => 'Need package details',
                'assigned_type' => 'self',
                'service_ids' => [$service->id],
                'force_create' => true,
            ]);

        $createResponse->assertCreated();

        $queryId = (int) $createResponse->json('data.id');

        $this
            ->actingAs($user, 'sanctum')
            ->patchJson('/api/queries/'.$queryId.'/status', [
                'query_status' => 'closed',
            ])
            ->assertOk();

        $this->assertDatabaseHas('queries', [
            'id' => $queryId,
            'query_status' => 'closed',
        ]);

        $this->assertDatabaseHas('query_items', [
            'query_id' => $queryId,
            'item_status' => 'closed',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => 'query',
            'auditable_id' => $queryId,
            'action' => 'query.status.changed',
        ]);
    }

    public function test_team_queue_assign_to_me_updates_assignment(): void
    {
        $user = $this->createUserWithPermissions(['query.view', 'query.assign']);

        $team = Team::query()->create([
            'team_name' => 'Operations',
            'is_active' => true,
        ]);

        $customer = Customer::query()->create([
            'mobile_number' => '+8801722222222',
            'customer_name' => 'Queue Customer',
            'gender' => 'female',
            'whatsapp_number' => '+8801722222222',
            'visit_record' => 'no_record',
            'is_active' => true,
        ]);

        $query = Query::query()->create([
            'customer_id' => $customer->id,
            'created_by_user_id' => $user->id,
            'query_details_text' => 'Queue item test',
            'query_status' => 'active',
            'assigned_type' => 'team',
            'team_id' => $team->id,
        ]);

        $service = Service::query()->create([
            'service_name' => 'Visa',
            'is_active' => true,
        ]);

        $item = QueryItem::query()->create([
            'query_id' => $query->id,
            'service_id' => $service->id,
            'assigned_user_id' => null,
            'team_id' => $team->id,
            'item_status' => 'active',
        ]);

        $this
            ->actingAs($user, 'sanctum')
            ->postJson('/api/query-items/'.$item->id.'/assign-to-me')
            ->assertOk();

        $this->assertDatabaseHas('query_items', [
            'id' => $item->id,
            'assigned_user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => 'query_item',
            'auditable_id' => $item->id,
            'action' => 'query.assignment.changed',
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createUserWithPermissions(array $permissions): User
    {
        $role = Role::findOrCreate('Test Role', 'web');

        foreach ($permissions as $permissionName) {
            $permission = Permission::findOrCreate($permissionName, 'web');
            $role->givePermissionTo($permission);
        }

        $user = User::factory()->create([
            'password' => 'password123',
            'is_active' => true,
        ]);

        $user->assignRole($role);

        return $user;
    }
}
