<?php

namespace Tests\Feature\Hellom;

use App\Models\SupportAgent;
use App\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportCenterTest extends TestCase
{
    use RefreshDatabase;
    use HellomTestHelpers;

    public function test_users_can_only_view_their_own_support_tickets(): void
    {
        [$owner, $ownerToken] = $this->createHellomUser();
        [, $otherToken] = $this->createHellomUser();
        $ticket = $this->createTicket($owner->id);

        $this->getJson('/api/v1/hellom/support/tickets/' . $ticket->ticket_number, $this->hellomHeaders($ownerToken))
            ->assertOk()
            ->assertJsonPath('data.ticket_number', $ticket->ticket_number);

        $this->getJson('/api/v1/hellom/support/tickets/' . $ticket->ticket_number, $this->hellomHeaders($otherToken))
            ->assertNotFound();
    }

    public function test_only_agents_can_read_internal_notes_and_agent_queue(): void
    {
        [$owner, $ownerToken] = $this->createHellomUser();
        [$agent, $agentToken] = $this->createHellomUser();
        $ticket = $this->createTicket($owner->id);
        $ticket->messages()->create([
            'sender_user_id' => $agent->id,
            'actor_type' => 'agent',
            'body' => 'Catatan rahasia agent.',
            'is_internal' => true,
        ]);

        $this->getJson('/api/v1/hellom/support/agent/tickets', $this->hellomHeaders($ownerToken))
            ->assertForbidden();

        $this->getJson('/api/v1/hellom/support/tickets/' . $ticket->ticket_number, $this->hellomHeaders($ownerToken))
            ->assertOk()
            ->assertJsonMissing(['body' => 'Catatan rahasia agent.']);

        SupportAgent::query()->create(['user_id' => $agent->id, 'is_active' => true]);
        $this->getJson('/api/v1/hellom/support/agent/tickets/' . $ticket->ticket_number, $this->hellomHeaders($agentToken))
            ->assertOk()
            ->assertJsonFragment(['body' => 'Catatan rahasia agent.']);
    }

    private function createTicket(int $requesterId): SupportTicket
    {
        $ticket = SupportTicket::query()->create([
            'ticket_number' => 'HEL-TEST-' . uniqid(),
            'requester_user_id' => $requesterId,
            'subject' => 'Masalah POS',
            'category' => 'teknis',
            'priority' => 'normal',
            'status' => 'waiting_agent',
            'source' => 'manual',
            'last_activity_at' => now(),
        ]);
        $ticket->messages()->create([
            'sender_user_id' => $requesterId,
            'actor_type' => 'user',
            'body' => 'Butuh bantuan.',
        ]);

        return $ticket;
    }
}
