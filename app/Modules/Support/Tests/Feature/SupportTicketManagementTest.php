<?php

namespace App\Modules\Support\Tests\Feature;

use App\Models\User;
use App\Modules\Support\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SupportTicketManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Support User',
            'email' => Str::lower(Str::random(8)).'@skillserve.test',
            'status' => 'active',
        ], $attributes));
    }

    private function actingSupport(array $permissions = []): array
    {
        $all = array_values(array_unique(array_merge(['view support', 'manage support'], $permissions)));
        foreach ($all as $permission) {
            Permission::findOrCreate($permission);
        }

        $role = Role::findOrCreate('support-agent');
        $role->syncPermissions($all);
        $actor = $this->createUser(['email' => 'support.agent@skillserve.test']);
        $actor->assignRole($role);

        return [$actor, $actor->createToken('test')->plainTextToken];
    }

    private function createTicket(array $attributes = []): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'ticket_number' => 'SUP-'.Str::upper(Str::random(10)),
            'requester_id' => $this->createUser(['email' => 'requester.'.Str::random(5).'@skillserve.test'])->id,
            'subject' => 'Unable to update my profile',
            'description' => 'The profile form returns an error.',
            'category' => 'account',
            'priority' => 'normal',
            'status' => 'open',
        ], $attributes));
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/support/tickets')
            ->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_users_without_support_permission_are_rejected(): void
    {
        $user = $this->createUser();

        $this->withToken($user->createToken('test')->plainTextToken)
            ->getJson('/api/support/tickets')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_index_supports_search_filters_and_pagination(): void
    {
        [, $token] = $this->actingSupport(['view support']);
        $this->createTicket(['subject' => 'Urgent payment issue', 'priority' => 'urgent', 'status' => 'in_progress']);
        $this->createTicket(['subject' => 'General question']);

        $this->withToken($token)
            ->getJson('/api/support/tickets?search=payment&priority=urgent&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.subject', 'Urgent payment issue')
            ->assertJsonStructure([
                'data' => [['ticket_number', 'subject', 'priority', 'status', 'requester', 'assigned_to']],
                'meta' => ['pagination'],
            ]);
    }

    public function test_assignment_only_accepts_active_administrators(): void
    {
        [, $token] = $this->actingSupport(['assign support tickets']);
        $ticket = $this->createTicket();
        $customer = $this->createUser(['email' => 'not.admin@skillserve.test']);

        $this->withToken($token)
            ->patchJson("/api/support/tickets/{$ticket->id}/assign", ['assigned_to' => $customer->id])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $admin = $this->createUser(['email' => 'assignee@skillserve.test']);
        $admin->assignRole('support-agent');

        $this->withToken($token)
            ->patchJson("/api/support/tickets/{$ticket->id}/assign", ['assigned_to' => $admin->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_to.id', $admin->id);
    }

    public function test_response_is_persisted_and_resolves_open_status_to_in_progress(): void
    {
        [, $token] = $this->actingSupport(['respond to support tickets']);
        $ticket = $this->createTicket();

        $this->withToken($token)
            ->postJson("/api/support/tickets/{$ticket->id}/responses", ['body' => 'We are reviewing this now.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.messages.0.body', 'We are reviewing this now.');

        $this->assertDatabaseHas('support_ticket_messages', ['support_ticket_id' => $ticket->id, 'body' => 'We are reviewing this now.']);
        $this->assertDatabaseHas('activity_log', ['description' => 'support_ticket_response_added', 'subject_id' => $ticket->id]);
    }

    public function test_resolve_requires_a_note_and_prevents_later_responses(): void
    {
        [, $token] = $this->actingSupport(['resolve support tickets', 'respond to support tickets']);
        $ticket = $this->createTicket();

        $this->withToken($token)
            ->patchJson("/api/support/tickets/{$ticket->id}/resolve", ['resolution_note' => 'The issue was corrected.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.resolution_note', 'The issue was corrected.');

        $this->assertDatabaseHas('activity_log', ['description' => 'support_ticket_resolved', 'subject_id' => $ticket->id]);

        $this->withToken($token)
            ->postJson("/api/support/tickets/{$ticket->id}/responses", ['body' => 'This should be rejected.'])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
