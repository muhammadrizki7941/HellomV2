<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Jobs\SendPlatformMail;
use App\Models\AuditLog;
use App\Models\SupportAgent;
use App\Models\SupportArticle;
use App\Models\SupportCannedResponse;
use App\Models\SupportCategory;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Notifications\Notifier;
use App\Services\Support\GroqSupportAssistant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportController extends BaseApiController
{
    public function categories(): JsonResponse
    {
        return $this->ok(SupportCategory::query()->where('is_active', true)->orderBy('sort_order')->get());
    }

    public function userTickets(Request $request): JsonResponse
    {
        $tickets = SupportTicket::query()->where('requester_user_id', $request->user()->id)
            ->with(['assignedAgent:id,name', 'messages' => fn ($q) => $q->where('is_internal', false)->latest()->limit(1)])
            ->orderByDesc('last_activity_at')->paginate(20);
        $tickets->setCollection($tickets->getCollection()->map(fn (SupportTicket $ticket) => [
            'id' => $ticket->id,
            'ticket_number' => $ticket->ticket_number,
            'subject' => $ticket->subject,
            'category' => $ticket->category,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'source' => $ticket->source,
            'assigned_agent' => $ticket->assignedAgent?->only(['id', 'name']),
            'last_activity_at' => $ticket->last_activity_at,
            'last_message' => $ticket->messages->first()?->only(['body', 'actor_type', 'created_at']),
        ]));

        return $this->ok($tickets);
    }

    public function userTicket(Request $request, string $ticketNumber): JsonResponse
    {
        $ticket = SupportTicket::query()->where('ticket_number', $ticketNumber)
            ->where('requester_user_id', $request->user()->id)
            ->with(['requester:id,name,email', 'assignedAgent:id,name', 'messages' => fn ($q) => $q->where('is_internal', false)->with(['sender:id,name', 'attachments'])->orderBy('id')])
            ->firstOrFail();

        return $this->ok($this->serializeTicket($ticket));
    }

    public function createTicket(Request $request, Notifier $notifier): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'min:3', 'max:180'],
            'category' => ['required', 'string', 'max:60', Rule::exists('support_categories', 'slug')->where('is_active', true)],
            'message' => ['required', 'string', 'min:2', 'max:10000'],
            'files' => ['sometimes', 'array', 'max:' . config('support.max_attachments_per_message')],
            'files.*' => ['file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120'],
        ]);
        $user = $request->user();
        $category = SupportCategory::query()->where('slug', $validated['category'])->firstOrFail();

        [$ticket, $message] = DB::transaction(function () use ($request, $user, $validated, $category) {
            $ticket = $this->newTicket($user, $validated['subject'], $category, 'manual');
            $message = $ticket->messages()->create([
                'sender_user_id' => $user->id,
                'actor_type' => 'user',
                'body' => trim($validated['message']),
            ]);
            $this->storeAttachments($request, $ticket, $message, $user);
            return [$ticket, $message];
        });

        $this->notifyAgents($ticket, $notifier, 'support_ticket_created', 'Tiket bantuan baru', "{$user->name} mengirim tiket {$ticket->ticket_number}.", "Tiket {$ticket->ticket_number} membutuhkan agent.", '/support/agent');
        $this->sendTicketEmail($this->activeAgentEmails(), 'Tiket bantuan baru ' . $ticket->ticket_number, 'Tiket bantuan baru', "{$user->name} mengirim tiket {$ticket->ticket_number}.", '/support/agent');
        $this->notifyRequester($ticket, 'support_ticket_created', 'Tiket bantuan diterima', 'Tiket ' . $ticket->ticket_number . ' berhasil dibuat. Tim Hellom akan menindaklanjutinya.', '/dashboard/support?ticket=' . $ticket->ticket_number);
        $this->sendTicketEmail([(string) $user->email], 'Tiket bantuan diterima ' . $ticket->ticket_number, 'Tiket bantuan Anda sudah diterima', 'Tim Hellom akan menindaklanjuti tiket ' . $ticket->ticket_number . '.', '/dashboard/support?ticket=' . $ticket->ticket_number);

        return $this->ok($this->serializeTicket($ticket->fresh()->load(['requester:id,name,email', 'messages.attachments'])), 'Tiket berhasil dibuat.', 201);
    }

    public function userMessage(Request $request, string $ticketNumber, Notifier $notifier): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:10000'],
            'files' => ['sometimes', 'array', 'max:' . config('support.max_attachments_per_message')],
            'files.*' => ['file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120'],
        ]);
        $ticket = SupportTicket::query()->where('ticket_number', $ticketNumber)->where('requester_user_id', $request->user()->id)->firstOrFail();
        if ($ticket->status === 'closed') {
            return $this->fail('Tiket sudah ditutup dan tidak dapat dibalas.', ['code' => 'TICKET_CLOSED'], 422);
        }
        if ($ticket->status === 'resolved' && (!$ticket->reopen_until || $ticket->reopen_until->isPast())) {
            return $this->fail('Masa untuk membuka kembali tiket sudah berakhir. Silakan buat tiket baru.', ['code' => 'REOPEN_EXPIRED'], 422);
        }

        $message = DB::transaction(function () use ($request, $ticket, $validated) {
            $message = $ticket->messages()->create([
                'sender_user_id' => $request->user()->id,
                'actor_type' => 'user',
                'body' => trim($validated['body']),
            ]);
            $this->storeAttachments($request, $ticket, $message, $request->user());
            $ticket->forceFill([
                'status' => in_array($ticket->status, ['resolved', 'waiting_user', 'ai_replied'], true) ? 'waiting_agent' : $ticket->status,
                'resolved_at' => null,
                'closed_at' => null,
                'last_activity_at' => now(),
            ])->save();
            return $message;
        });

        $this->notifyAgents($ticket, $notifier, 'support_ticket_reply', 'Balasan pengguna pada tiket', "Tiket {$ticket->ticket_number} mendapat pesan baru.", "Pengguna membalas tiket {$ticket->ticket_number}.", '/support/agent');
        if ($ticket->assigned_agent_user_id) {
            $agent = User::query()->find($ticket->assigned_agent_user_id);
            if ($agent) {
                $this->sendTicketEmail([(string) $agent->email], 'Balasan tiket ' . $ticket->ticket_number, 'Ada balasan pada tiket', "Pengguna membalas tiket {$ticket->ticket_number}.", '/support/agent');
            }
        } else {
            $this->sendTicketEmail($this->activeAgentEmails(), 'Balasan tiket ' . $ticket->ticket_number, 'Ada balasan pada tiket', "Pengguna membalas tiket {$ticket->ticket_number}.", '/support/agent');
        }

        return $this->ok($this->serializeMessage($message->load('attachments')), 'Pesan terkirim.');
    }

    public function assistantMessage(Request $request, GroqSupportAssistant $assistant, Notifier $notifier): JsonResponse
    {
        $validated = $request->validate([
            'ticket_number' => ['nullable', 'string', 'max:24'],
            'message' => ['required', 'string', 'min:2', 'max:3000'],
        ]);
        $user = $request->user();
        $category = SupportCategory::query()->where('slug', 'lainnya')->firstOrFail();
        $ticket = !empty($validated['ticket_number'])
            ? SupportTicket::query()->where('ticket_number', $validated['ticket_number'])->where('requester_user_id', $user->id)->where('source', 'ai')->firstOrFail()
            : null;

        if ($ticket && in_array($ticket->status, ['waiting_agent', 'in_progress', 'waiting_user', 'resolved', 'closed'], true)) {
            return $this->fail('Percakapan ini sudah diteruskan ke tim bantuan. Balas melalui detail tiket untuk melanjutkan.', ['code' => 'AI_ESCALATED'], 409);
        }
        if (!$ticket) {
            $ticket = DB::transaction(fn () => $this->newTicket($user, Str::limit(trim($validated['message']), 120, ''), $category, 'ai'));
        }

        $userMessage = $ticket->messages()->create([
            'sender_user_id' => $user->id,
            'actor_type' => 'user',
            'body' => trim($validated['message']),
        ]);
        $ticket->forceFill(['last_activity_at' => now()])->save();

        if ($this->isSensitive($validated['message'])) {
            $reply = 'Topik ini perlu ditangani langsung oleh tim Hellom. Percakapan Anda sudah diteruskan sebagai tiket bantuan.';
            $this->escalate($ticket, 'Topik sensitif diarahkan ke agent.');
            $this->notifyAgents($ticket, $notifier, 'support_ticket_escalated', 'Tiket AI perlu ditangani', "Topik sensitif pada tiket {$ticket->ticket_number}.", "Tiket {$ticket->ticket_number} dialihkan ke agent.", '/support/agent');
            $this->sendTicketEmail($this->activeAgentEmails(), 'Tiket perlu ditangani ' . $ticket->ticket_number, 'Tiket baru menunggu agent', "Tiket {$ticket->ticket_number} dialihkan ke agent.", '/support/agent');
            $assistantMessage = $ticket->messages()->create(['actor_type' => 'ai', 'body' => $reply, 'metadata' => ['needs_human' => true, 'reason' => 'sensitive_topic']]);

            return $this->ok(['ticket' => $ticket->fresh(), 'user_message' => $userMessage, 'assistant_message' => $assistantMessage, 'escalated' => true], 'Percakapan diteruskan ke agent.');
        }

        $key = 'support-ai:' . $user->id . ':' . now()->toDateString();
        Cache::add($key, 0, now()->endOfDay());
        if ((int) Cache::get($key, 0) >= (int) config('support.ai_daily_limit')) {
            $this->escalate($ticket, 'Batas harian AI tercapai.');
            $reply = 'Kuota bantuan AI hari ini sudah digunakan. Tim agent akan membantu melalui tiket ini.';
            $assistantMessage = $ticket->messages()->create(['actor_type' => 'ai', 'body' => $reply, 'metadata' => ['needs_human' => true, 'reason' => 'quota_exhausted']]);
            $this->notifyAgents($ticket, $notifier, 'support_ticket_escalated', 'Tiket menunggu agent', "Kuota AI habis pada tiket {$ticket->ticket_number}.", "Tiket {$ticket->ticket_number} menunggu agent.", '/support/agent');
            $this->sendTicketEmail($this->activeAgentEmails(), 'Tiket menunggu agent ' . $ticket->ticket_number, 'Tiket bantuan baru', "Tiket {$ticket->ticket_number} menunggu agent.", '/support/agent');

            return $this->ok(['ticket' => $ticket->fresh(), 'user_message' => $userMessage, 'assistant_message' => $assistantMessage, 'escalated' => true], 'Tiket diteruskan ke agent.');
        }
        Cache::increment($key);

        $history = $ticket->messages()->whereIn('actor_type', ['user', 'ai'])->where('id', '<', $userMessage->id)
            ->orderByDesc('id')->limit(6)->get()->reverse()
            ->map(fn (SupportMessage $message) => ['role' => $message->actor_type === 'ai' ? 'assistant' : 'user', 'content' => $message->body])->all();

        $aiException = null;
        try {
            $answer = $assistant->answer($validated['message'], $history);
        } catch (\Throwable $exception) {
            $aiException = $exception;
            $answer = null;
        }

        if ($answer !== null) {
            $ticket->forceFill(['ai_failure_streak' => 0])->save();
            if ($answer['needs_human']) {
                $this->escalate($ticket, 'Konteks knowledge base tidak cukup untuk menjawab.');
                $reply = 'Saya belum menemukan panduan yang cukup akurat. Tim agent Hellom akan membantu Anda melalui tiket ini.';
                $this->notifyAgents($ticket, $notifier, 'support_ticket_escalated', 'Tiket AI menunggu agent', "AI meminta bantuan agent untuk tiket {$ticket->ticket_number}.", "Tiket {$ticket->ticket_number} menunggu agent.", '/support/agent');
                $this->sendTicketEmail($this->activeAgentEmails(), 'Tiket AI menunggu agent ' . $ticket->ticket_number, 'Tiket bantuan perlu ditangani', "AI meminta bantuan untuk tiket {$ticket->ticket_number}.", '/support/agent');
            } else {
                $reply = $answer['answer'];
                $ticket->forceFill(['status' => 'ai_replied'])->save();
            }
            $assistantMessage = $ticket->messages()->create([
                'actor_type' => 'ai',
                'body' => $reply,
                'metadata' => ['confidence' => $answer['confidence'], 'needs_human' => $answer['needs_human'], 'article_ids' => $answer['article_ids']],
            ]);
        } else {
            Log::warning('Support AI request failed', ['ticket' => $ticket->ticket_number, 'exception' => $aiException ? $aiException::class : null]);
            $failures = (int) $ticket->ai_failure_streak + 1;
            $ticket->forceFill(['ai_failure_streak' => $failures])->save();
            $escalated = $failures >= 2;
            if ($escalated) {
                $this->escalate($ticket, 'AI provider gagal dua kali berturut-turut.');
            }
            $reply = $escalated
                ? 'Asisten AI sedang mengalami kendala. Tim agent akan membantu Anda melalui tiket ini.'
                : 'Asisten AI sedang mengalami kendala. Silakan coba sekali lagi atau pilih Hubungi CS agar agent membantu.';
            $assistantMessage = $ticket->messages()->create([
                'actor_type' => 'ai',
                'body' => $reply,
                'metadata' => ['needs_human' => $escalated, 'reason' => 'provider_error'],
            ]);
            if ($escalated) {
                $this->notifyAgents($ticket, $notifier, 'support_ticket_escalated', 'Tiket AI menunggu agent', "AI gagal dua kali pada tiket {$ticket->ticket_number}.", "Tiket {$ticket->ticket_number} menunggu agent.", '/support/agent');
                $this->sendTicketEmail($this->activeAgentEmails(), 'Tiket AI gagal ' . $ticket->ticket_number, 'Tiket bantuan perlu ditangani', "AI gagal memproses tiket {$ticket->ticket_number}.", '/support/agent');
            }
        }

        return $this->ok([
            'ticket' => $ticket->fresh(),
            'user_message' => $userMessage,
            'assistant_message' => $assistantMessage,
            'escalated' => in_array($ticket->fresh()->status, ['waiting_agent', 'in_progress'], true),
        ], 'Pesan diproses.');
    }

    public function escalateTicket(Request $request, string $ticketNumber, Notifier $notifier): JsonResponse
    {
        $ticket = SupportTicket::query()->where('ticket_number', $ticketNumber)->where('requester_user_id', $request->user()->id)->firstOrFail();
        if (in_array($ticket->status, ['closed', 'resolved'], true) && (!$ticket->reopen_until || $ticket->reopen_until->isPast())) {
            return $this->fail('Tiket sudah ditutup.', ['code' => 'TICKET_CLOSED'], 422);
        }
        $this->escalate($ticket, 'Pengguna meminta Hubungi CS.');
        $this->notifyAgents($ticket, $notifier, 'support_ticket_escalated', 'Pengguna meminta CS', "Pengguna meminta bantuan agent pada tiket {$ticket->ticket_number}.", "Tiket {$ticket->ticket_number} menunggu agent.", '/support/agent');
        $this->sendTicketEmail($this->activeAgentEmails(), 'Pengguna meminta CS ' . $ticket->ticket_number, 'Pengguna meminta bantuan agent', "Tiket {$ticket->ticket_number} menunggu agent.", '/support/agent');

        return $this->ok($ticket->fresh(), 'Tiket diteruskan ke tim bantuan.');
    }

    public function closeTicket(Request $request, string $ticketNumber): JsonResponse
    {
        $ticket = SupportTicket::query()->where('ticket_number', $ticketNumber)->where('requester_user_id', $request->user()->id)->firstOrFail();
        $ticket->forceFill(['status' => 'closed', 'closed_at' => now(), 'last_activity_at' => now()])->save();
        return $this->ok($ticket, 'Tiket ditutup.');
    }

    public function reopenTicket(Request $request, string $ticketNumber, Notifier $notifier): JsonResponse
    {
        $ticket = SupportTicket::query()->where('ticket_number', $ticketNumber)->where('requester_user_id', $request->user()->id)->firstOrFail();
        if (!$ticket->reopen_until || $ticket->reopen_until->isPast()) {
            return $this->fail('Masa untuk membuka kembali tiket sudah berakhir.', ['code' => 'REOPEN_EXPIRED'], 422);
        }
        $ticket->forceFill(['status' => 'waiting_agent', 'closed_at' => null, 'resolved_at' => null, 'last_activity_at' => now()])->save();
        $this->notifyAgents($ticket, $notifier, 'support_ticket_reopened', 'Tiket dibuka kembali', "Tiket {$ticket->ticket_number} dibuka kembali oleh pengguna.", "Tiket {$ticket->ticket_number} menunggu agent.", '/support/agent');
        $this->sendTicketEmail($this->activeAgentEmails(), 'Tiket dibuka kembali ' . $ticket->ticket_number, 'Tiket dibuka kembali', "Tiket {$ticket->ticket_number} dibuka kembali oleh pengguna.", '/support/agent');
        return $this->ok($ticket, 'Tiket dibuka kembali.');
    }

    public function feedback(Request $request, string $ticketNumber): JsonResponse
    {
        $validated = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'feedback' => ['nullable', 'string', 'max:1000']]);
        $ticket = SupportTicket::query()->where('ticket_number', $ticketNumber)->where('requester_user_id', $request->user()->id)->where('status', 'resolved')->firstOrFail();
        $ticket->forceFill(['rating' => $validated['rating'], 'feedback' => $validated['feedback'] ?? null])->save();
        return $this->ok($ticket, 'Terima kasih atas masukan Anda.');
    }

    public function agentQueue(Request $request): JsonResponse
    {
        $query = SupportTicket::query()->with(['requester:id,name,email', 'assignedAgent:id,name']);
        if ($request->filled('status') && in_array($request->string('status')->toString(), SupportTicket::STATUSES, true)) {
            $query->where('status', $request->string('status')->toString());
        }
        if ($request->filled('priority') && in_array($request->string('priority')->toString(), SupportTicket::PRIORITIES, true)) {
            $query->where('priority', $request->string('priority')->toString());
        }
        if ($request->filled('q')) {
            $q = trim((string) $request->query('q'));
            $query->where(fn (Builder $builder) => $builder->where('ticket_number', 'like', '%' . addcslashes($q, '%_') . '%')
                ->orWhere('subject', 'like', '%' . addcslashes($q, '%_') . '%'));
        }
        $tickets = $query->orderByRaw("CASE WHEN status = 'waiting_agent' THEN 0 WHEN status = 'new' THEN 1 ELSE 2 END")
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->orderBy('last_activity_at')->paginate(30);

        return $this->ok($tickets);
    }

    public function agentTicket(string $ticketNumber): JsonResponse
    {
        $ticket = SupportTicket::query()->where('ticket_number', $ticketNumber)
            ->with(['requester:id,name,email', 'organization:id,name', 'assignedAgent:id,name', 'messages' => fn ($q) => $q->with(['sender:id,name', 'attachments'])->orderBy('id')])
            ->firstOrFail();
        return $this->ok($this->serializeTicket($ticket, true));
    }

    public function takeTicket(Request $request, string $ticketNumber): JsonResponse
    {
        $ticket = SupportTicket::query()->where('ticket_number', $ticketNumber)->firstOrFail();
        $ticket->forceFill([
            'assigned_agent_user_id' => $request->user()->id,
            'status' => in_array($ticket->status, ['new', 'waiting_agent'], true) ? 'in_progress' : $ticket->status,
            'last_activity_at' => now(),
        ])->save();
        $this->audit($request, 'support.ticket.assigned', $ticket, ['assigned_agent_user_id' => $request->user()->id]);
        $this->notifyRequester($ticket, 'support_ticket_assigned', 'Tiket sedang ditangani', 'Tim Hellom mulai menangani tiket ' . $ticket->ticket_number . '.', '/dashboard/support?ticket=' . $ticket->ticket_number);
        $this->sendTicketEmail([(string) $ticket->requester->email], 'Tiket sedang ditangani ' . $ticket->ticket_number, 'Tim Hellom mulai menangani tiket Anda', 'Tim Hellom sedang menangani tiket ' . $ticket->ticket_number . '.', '/dashboard/support?ticket=' . $ticket->ticket_number);
        return $this->ok($ticket->fresh()->load('assignedAgent:id,name'), 'Tiket ditugaskan kepada Anda.');
    }

    public function assignTicket(Request $request, string $ticketNumber, Notifier $notifier): JsonResponse
    {
        $validated = $request->validate(['agent_user_id' => ['required', 'integer', Rule::exists('support_agents', 'user_id')->where('is_active', true)]]);
        $ticket = SupportTicket::query()->where('ticket_number', $ticketNumber)->firstOrFail();
        $ticket->forceFill(['assigned_agent_user_id' => $validated['agent_user_id'], 'status' => 'in_progress', 'last_activity_at' => now()])->save();
        $this->audit($request, 'support.ticket.assigned', $ticket, ['assigned_agent_user_id' => $validated['agent_user_id']]);
        $notifier->user((int) $validated['agent_user_id'], 'support_ticket_assigned', 'Tiket ditugaskan kepada Anda', 'Tiket ' . $ticket->ticket_number . ' ditugaskan kepada Anda.', '/support/agent', ['ticket_number' => $ticket->ticket_number]);
        $this->sendTicketEmail([(string) User::query()->find($validated['agent_user_id'])?->email], 'Tiket ditugaskan ' . $ticket->ticket_number, 'Tiket bantuan ditugaskan kepada Anda', 'Tiket ' . $ticket->ticket_number . ' ditugaskan kepada Anda.', '/support/agent');
        $this->notifyRequester($ticket, 'support_ticket_assigned', 'Tiket sedang ditangani', 'Tiket ' . $ticket->ticket_number . ' telah ditugaskan kepada tim Hellom.', '/dashboard/support?ticket=' . $ticket->ticket_number);
        $this->sendTicketEmail([(string) $ticket->requester->email], 'Agent ditugaskan ' . $ticket->ticket_number, 'Tim Hellom sedang menangani tiket Anda', 'Tiket ' . $ticket->ticket_number . ' telah ditugaskan kepada tim Hellom.', '/dashboard/support?ticket=' . $ticket->ticket_number);
        return $this->ok($ticket->fresh()->load('assignedAgent:id,name'), 'Agent ditetapkan.');
    }

    public function agentMessage(Request $request, string $ticketNumber, Notifier $notifier): JsonResponse
    {
        $validated = $request->validate(['body' => ['required', 'string', 'min:1', 'max:10000'], 'internal' => ['sometimes', 'boolean']]);
        $ticket = SupportTicket::query()->where('ticket_number', $ticketNumber)->firstOrFail();
        $internal = (bool) ($validated['internal'] ?? false);
        $message = DB::transaction(function () use ($request, $ticket, $validated, $internal) {
            $message = $ticket->messages()->create([
                'sender_user_id' => $request->user()->id,
                'actor_type' => 'agent',
                'body' => trim($validated['body']),
                'is_internal' => $internal,
            ]);
            if (!$internal) {
                $ticket->forceFill([
                    'status' => 'waiting_user',
                    'first_response_at' => $ticket->first_response_at ?: now(),
                    'last_activity_at' => now(),
                ])->save();
            }
            return $message;
        });
        $this->audit($request, $internal ? 'support.ticket.internal_note' : 'support.ticket.replied', $ticket, ['message_id' => $message->id]);
        if (!$internal) {
            $this->notifyRequester($ticket, 'support_ticket_reply', 'Ada balasan dari tim Hellom', 'Tim Hellom membalas tiket ' . $ticket->ticket_number . '.', '/dashboard/support?ticket=' . $ticket->ticket_number);
            $this->sendTicketEmail([(string) $ticket->requester->email], 'Balasan tiket ' . $ticket->ticket_number, 'Ada balasan dari tim Hellom', 'Tim Hellom telah membalas tiket ' . $ticket->ticket_number . '.', '/dashboard/support?ticket=' . $ticket->ticket_number);
        }
        return $this->ok($message->load('sender:id,name'), 'Pesan berhasil dikirim.');
    }

    public function updateTicket(Request $request, string $ticketNumber): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(SupportTicket::STATUSES)],
            'priority' => ['sometimes', Rule::in(SupportTicket::PRIORITIES)],
            'category' => ['sometimes', 'string', Rule::exists('support_categories', 'slug')],
        ]);
        $ticket = SupportTicket::query()->where('ticket_number', $ticketNumber)->firstOrFail();
        $before = $ticket->only(array_keys($validated));
        if (($validated['status'] ?? null) === 'resolved') {
            $ticket->forceFill([
                'resolved_at' => now(),
                'reopen_until' => now()->addDays((int) config('support.reopen_days')),
                'closed_at' => null,
            ]);
        } elseif (($validated['status'] ?? null) === 'closed') {
            $ticket->forceFill(['resolved_at' => null, 'closed_at' => now(), 'reopen_until' => null]);
        } elseif (isset($validated['status'])) {
            $ticket->forceFill(['resolved_at' => null, 'closed_at' => null, 'reopen_until' => null]);
        }
        $ticket->fill($validated)->forceFill(['last_activity_at' => now()])->save();
        $this->audit($request, 'support.ticket.updated', $ticket, ['before' => $before, 'after' => $validated]);
        if (($validated['status'] ?? null) === 'resolved') {
            $this->notifyRequester($ticket, 'support_ticket_resolved', 'Tiket bantuan selesai', 'Tiket ' . $ticket->ticket_number . ' ditandai selesai. Anda dapat membuka kembali dalam 7 hari.', '/dashboard/support?ticket=' . $ticket->ticket_number);
            $this->sendTicketEmail([(string) $ticket->requester->email], 'Tiket selesai ' . $ticket->ticket_number, 'Tiket bantuan ditandai selesai', 'Tiket ' . $ticket->ticket_number . ' ditandai selesai. Anda dapat membukanya kembali dalam 7 hari.', '/dashboard/support?ticket=' . $ticket->ticket_number);
        }
        return $this->ok($ticket->fresh(), 'Tiket diperbarui.');
    }

    public function attachment(Request $request, int $id): StreamedResponse
    {
        $attachment = \App\Models\SupportAttachment::query()->with('ticket')->findOrFail($id);
        if ($attachment->ticket->requester_user_id !== $request->user()->id && !$this->isAgent($request->user())) {
            abort(404);
        }
        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name, ['Content-Type' => $attachment->mime_type]);
    }

    public function adminOverview(): JsonResponse
    {
        return $this->ok([
            'agents' => SupportAgent::query()->with('user:id,name,email,role')->orderByDesc('is_active')->get(),
            'users' => User::query()->whereNotIn('role', ['suspended'])->orderBy('name')->limit(500)->get(['id', 'name', 'email', 'role']),
            'categories' => SupportCategory::query()->orderBy('sort_order')->get(),
            'canned_responses' => SupportCannedResponse::query()->orderBy('title')->get(),
            'knowledge' => [
                'published' => SupportArticle::query()->where('status', 'published')->where('locale', 'id')->count(),
                'drafts' => SupportArticle::query()->where('status', 'draft')->where('locale', 'id')->count(),
                'review_required' => SupportArticle::query()->where('status', 'published')->where('locale', 'id')
                    ->where('content_markdown', 'like', '%[PERIKSA%')->count(),
            ],
            'groq_ready' => (string) config('services.groq.key', '') !== '',
        ]);
    }

    public function saveAgent(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        if (User::query()->whereKey($data['user_id'])->where('role', 'suspended')->exists()) {
            return $this->fail('Akun yang ditangguhkan tidak dapat dijadikan agent.', ['code' => 'INVALID_AGENT'], 422);
        }
        $agent = SupportAgent::query()->updateOrCreate(['user_id' => $data['user_id']], ['created_by_user_id' => $request->user()->id, 'is_active' => true]);
        $this->audit($request, 'support.agent.activated', null, ['user_id' => $agent->user_id]);
        return $this->ok($agent->load('user:id,name,email'), 'Agent diaktifkan.', 201);
    }

    public function deactivateAgent(Request $request, int $userId): JsonResponse
    {
        $agent = SupportAgent::query()->where('user_id', $userId)->firstOrFail();
        $agent->forceFill(['is_active' => false])->save();
        SupportTicket::query()->where('assigned_agent_user_id', $userId)->whereIn('status', ['new', 'waiting_agent', 'in_progress', 'waiting_user'])->update(['assigned_agent_user_id' => null]);
        $this->audit($request, 'support.agent.deactivated', null, ['user_id' => $userId]);
        return $this->ok(true, 'Agent dinonaktifkan.');
    }

    public function knowledgeArticles(): JsonResponse
    {
        return $this->ok(SupportArticle::query()->orderByDesc('updated_at')->paginate(30));
    }

    public function saveKnowledgeArticle(Request $request, ?int $id = null): JsonResponse
    {
        $article = $id ? SupportArticle::query()->findOrFail($id) : new SupportArticle();
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'slug' => ['nullable', 'string', 'max:200', Rule::unique('support_articles', 'slug')->ignore($article->id)],
            'category' => ['required', 'string', 'max:60'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],
            'route_path' => ['nullable', 'string', 'max:255', 'regex:/^\/(dashboard|pos)(\/[A-Za-z0-9._?=&-]*)?$/'],
            'content_markdown' => ['required', 'string', 'min:20', 'max:100000'],
            'status' => ['required', Rule::in(['draft', 'published'])],
        ]);
        if ($validated['status'] === 'published' && preg_match('/\[PERIKSA(?:\s*:|\])/iu', $validated['content_markdown'])) {
            return $this->fail('Hapus atau lengkapi semua tanda [PERIKSA] sebelum menerbitkan artikel.', ['code' => 'KNOWLEDGE_REVIEW_REQUIRED'], 422);
        }
        $validated['slug'] = Str::slug($validated['slug'] ?: $validated['title']);
        $validated['locale'] = 'id';
        $validated['content_hash'] = hash('sha256', $validated['content_markdown']);
        $validated['updated_by_user_id'] = $request->user()->id;
        if (!$article->exists) {
            $validated['created_by_user_id'] = $request->user()->id;
            $validated['version'] = 1;
        } elseif ($article->content_hash !== $validated['content_hash']) {
            $validated['version'] = $article->version + 1;
        }
        $validated['published_at'] = $validated['status'] === 'published' ? ($article->published_at ?: now()) : null;
        $article->fill($validated)->save();
        $this->audit($request, $id ? 'support.knowledge.updated' : 'support.knowledge.created', null, ['article_id' => $article->id, 'status' => $article->status, 'version' => $article->version]);
        return $this->ok($article->fresh(), 'Artikel knowledge base disimpan.', $id ? 200 : 201);
    }

    public function deleteKnowledgeArticle(Request $request, int $id): JsonResponse
    {
        $article = SupportArticle::query()->findOrFail($id);
        $article->delete();
        $this->audit($request, 'support.knowledge.deleted', null, ['article_id' => $id]);
        return $this->ok(true, 'Artikel dihapus.');
    }

    public function importKnowledge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'articles' => ['sometimes', 'array', 'min:1', 'max:50'],
            'articles.*.title' => ['required_with:articles', 'string', 'max:180'],
            'articles.*.category' => ['required_with:articles', 'string', 'max:60'],
            'articles.*.content' => ['required_with:articles', 'string', 'min:20', 'max:100000'],
            'articles.*.route_path' => ['nullable', 'string', 'max:255', 'regex:/^\/(dashboard|pos)(\/[A-Za-z0-9._?=&-]*)?$/'],
            'title' => ['required_without:articles', 'string', 'max:180'],
            'category' => ['required_without:articles', 'string', 'max:60'],
            'content' => ['required_without:articles', 'string', 'min:20', 'max:100000'],
            'route_path' => ['nullable', 'string', 'max:255', 'regex:/^\/(dashboard|pos)(\/[A-Za-z0-9._?=&-]*)?$/'],
        ]);
        $articles = $validated['articles'] ?? [[
            'title' => $validated['title'],
            'category' => $validated['category'],
            'content' => $validated['content'],
            'route_path' => $validated['route_path'] ?? null,
        ]];
        $created = DB::transaction(function () use ($articles, $request) {
            return collect($articles)->map(function (array $data) use ($request) {
                $article = SupportArticle::query()->create([
                    'title' => $data['title'],
                    'slug' => $this->uniqueArticleSlug($data['title']),
                    'category' => $data['category'],
                    'locale' => 'id',
                    'route_path' => $data['route_path'] ?? null,
                    'content_markdown' => $data['content'],
                    'content_hash' => hash('sha256', $data['content']),
                    'status' => 'draft',
                    'created_by_user_id' => $request->user()->id,
                    'updated_by_user_id' => $request->user()->id,
                ]);
                $this->audit($request, 'support.knowledge.imported', null, ['article_id' => $article->id]);
                return $article;
            })->all();
        });
        $message = count($created) === 1
            ? 'Teks berhasil diimpor sebagai draft. Periksa dan publikasikan setelah ditinjau.'
            : count($created) . ' artikel berhasil diimpor sebagai draft. Periksa setiap artikel dan publikasikan setelah ditinjau.';

        return $this->ok($created, $message, 201);
    }

    public function saveCategory(Request $request, ?int $id = null): JsonResponse
    {
        $category = $id ? SupportCategory::query()->findOrFail($id) : new SupportCategory();
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/', Rule::unique('support_categories', 'slug')->ignore($category->id)],
            'name' => ['required', 'string', 'max:100'],
            'default_priority' => ['required', Rule::in(SupportTicket::PRIORITIES)],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ]);
        $category->fill($data)->save();
        $this->audit($request, $id ? 'support.category.updated' : 'support.category.created', null, ['category_id' => $category->id]);
        return $this->ok($category, 'Kategori disimpan.', $id ? 200 : 201);
    }

    public function saveCannedResponse(Request $request, ?int $id = null): JsonResponse
    {
        $response = $id ? SupportCannedResponse::query()->findOrFail($id) : new SupportCannedResponse();
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:60'],
            'body' => ['required', 'string', 'max:5000'],
            'is_active' => ['required', 'boolean'],
        ]);
        $response->fill($data + ['created_by_user_id' => $request->user()->id])->save();
        return $this->ok($response, 'Balasan cepat disimpan.', $id ? 200 : 201);
    }

    public function stats(): JsonResponse
    {
        return $this->ok([
            'open' => SupportTicket::query()->whereNotIn('status', ['resolved', 'closed'])->count(),
            'waiting_agent' => SupportTicket::query()->where('status', 'waiting_agent')->count(),
            'overdue' => SupportTicket::query()->whereNotIn('status', ['resolved', 'closed'])->where('sla_due_at', '<', now())->count(),
            'resolved_30d' => SupportTicket::query()->where('status', 'resolved')->where('resolved_at', '>=', now()->subDays(30))->count(),
            'average_rating' => round((float) SupportTicket::query()->whereNotNull('rating')->avg('rating'), 2),
            'by_category' => SupportTicket::query()->selectRaw('category, count(*) as total')->groupBy('category')->pluck('total', 'category'),
        ]);
    }

    public function cannedResponses(): JsonResponse
    {
        return $this->ok(SupportCannedResponse::query()->where('is_active', true)->orderBy('title')->get(['id', 'title', 'category', 'body']));
    }

    private function newTicket(User $user, string $subject, SupportCategory $category, string $source): SupportTicket
    {
        $organizationId = null;
        if ($user->current_organization_id && $user->organizations()->whereKey($user->current_organization_id)->exists()) {
            $organizationId = (int) $user->current_organization_id;
        }

        return SupportTicket::query()->create([
            'ticket_number' => $this->ticketNumber(),
            'organization_id' => $organizationId,
            'requester_user_id' => $user->id,
            'subject' => Str::limit(trim($subject), 180, ''),
            'category' => $category->slug,
            'priority' => $category->default_priority,
            'status' => 'new',
            'source' => $source,
            'last_activity_at' => now(),
            'sla_due_at' => now()->addHours((int) config('support.sla_hours', 24)),
        ]);
    }

    private function ticketNumber(): string
    {
        do {
            $number = 'HEL-' . now()->format('ym') . '-' . Str::upper(Str::random(6));
        } while (SupportTicket::query()->where('ticket_number', $number)->exists());
        return $number;
    }

    private function storeAttachments(Request $request, SupportTicket $ticket, SupportMessage $message, User $user): void
    {
        foreach ($request->file('files', []) as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                throw new \RuntimeException('Lampiran gagal diunggah.');
            }
            $mime = (string) $file->getMimeType();
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                throw new \RuntimeException('Lampiran hanya boleh berupa gambar JPG, PNG, atau WebP.');
            }
            $path = $file->store('support/' . $ticket->ticket_number, 'local');
            if (!$path) {
                throw new \RuntimeException('Lampiran tidak dapat disimpan.');
            }
            $ticket->attachments()->create([
                'message_id' => $message->id,
                'uploaded_by_user_id' => $user->id,
                'disk' => 'local',
                'path' => $path,
                'original_name' => Str::limit(basename($file->getClientOriginalName()), 180, ''),
                'mime_type' => $mime,
                'size_bytes' => (int) $file->getSize(),
                'sha256' => hash_file('sha256', $file->getRealPath()),
            ]);
        }
    }

    private function isSensitive(string $message): bool
    {
        return (bool) preg_match('/pembayaran|bayar|tagihan|langganan|billing|transaksi|refund|pengembalian dana|bug|error|gagal|tidak bisa|tidak dapat|data hilang|data terhapus|kehilangan data|akun|login|masuk|password|kata sandi|otp|saldo|transfer|invoice/iu', $message);
    }

    private function escalate(SupportTicket $ticket, string $reason): void
    {
        $ticket->forceFill([
            'status' => 'waiting_agent',
            'category' => $this->escalationCategory($ticket->subject . ' ' . $reason),
            'last_activity_at' => now(),
        ])->save();
        $ticket->messages()->create(['actor_type' => 'system', 'body' => $reason, 'is_internal' => true]);
    }

    private function escalationCategory(string $text): string
    {
        if ($this->isSensitive($text)) {
            if (preg_match('/pembayaran|bayar|tagihan|langganan|billing|transaksi|refund|saldo|transfer|invoice/iu', $text)) {
                return 'langganan-pembayaran';
            }
            if (preg_match('/bug|error|gagal|tidak bisa|tidak dapat|data hilang|terhapus/iu', $text)) {
                return 'bug';
            }
            return 'teknis';
        }
        return 'lainnya';
    }

    private function notifyAgents(SupportTicket $ticket, Notifier $notifier, string $type, string $title, string $body, string $emailIntro, string $url): void
    {
        $userIds = SupportAgent::query()->where('is_active', true)->pluck('user_id')->merge(
            User::query()->where('role', 'super_admin')->pluck('id'),
        )->unique()->values();
        foreach ($userIds as $userId) {
            $notifier->user((int) $userId, $type, $title, $body, $url, ['ticket_number' => $ticket->ticket_number]);
        }
    }

    private function notifyRequester(SupportTicket $ticket, string $type, string $title, string $body, string $url): void
    {
        app(Notifier::class)->user((int) $ticket->requester_user_id, $type, $title, $body, $url, ['ticket_number' => $ticket->ticket_number]);
    }

    /** @param list<string> $recipients */
    private function sendTicketEmail(array $recipients, string $subject, string $headline, string $intro, string $path): void
    {
        $recipients = array_values(array_unique(array_filter($recipients, fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL))));
        if ($recipients === []) {
            return;
        }
        SendPlatformMail::dispatch($recipients, '[Hellom] ' . $subject, [
            'headline' => $headline,
            'intro' => $intro,
            'cta_url' => \App\Support\FrontendUrl::to($path),
            'cta_label' => 'Buka Pusat Bantuan',
            'closing' => 'Email ini dikirim karena ada pembaruan Pusat Bantuan Hellom.',
        ]);
    }

    /** @return list<string> */
    private function activeAgentEmails(): array
    {
        $userIds = SupportAgent::query()->where('is_active', true)->pluck('user_id')->merge(
            User::query()->where('role', 'super_admin')->pluck('id'),
        )->unique()->values();
        return User::query()->whereIn('id', $userIds)->pluck('email')->map(fn ($email) => (string) $email)->all();
    }

    private function audit(Request $request, string $action, ?SupportTicket $ticket, array $metadata): void
    {
        AuditLog::record(
            action: $action,
            userId: $request->user()?->id,
            organizationId: $ticket?->organization_id,
            entityType: 'support_ticket',
            entityId: $ticket?->id,
            metadata: $metadata,
            ipAddress: $request->ip(),
            userAgent: Str::limit((string) $request->userAgent(), 500, ''),
        );
    }

    private function isAgent(User $user): bool
    {
        return $user->role === 'super_admin'
            || SupportAgent::query()->where('user_id', $user->id)->where('is_active', true)->exists();
    }

    private function serializeTicket(SupportTicket $ticket, bool $includeInternal = false): array
    {
        $data = $ticket->toArray();
        $data['messages'] = $ticket->messages
            ->when(!$includeInternal, fn ($messages) => $messages->where('is_internal', false))
            ->values()
            ->map(function (SupportMessage $message) {
                $item = $message->toArray();
                return $this->serializeMessage($message);
            });
        return $data;
    }

    private function serializeMessage(SupportMessage $message): array
    {
        return [
            'id' => $message->id,
            'actor_type' => $message->actor_type,
            'sender' => $message->sender?->only(['id', 'name']),
            'body' => $message->body,
            'is_internal' => $message->is_internal,
            'metadata' => $message->metadata,
            'created_at' => $message->created_at,
            'attachments' => $message->attachments->map(fn ($attachment) => [
                'id' => $attachment->id,
                'name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size_bytes' => $attachment->size_bytes,
                'url' => '/support/attachments/' . $attachment->id,
            ])->values(),
        ];
    }

    private function uniqueArticleSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'panduan';
        $slug = $base;
        $counter = 2;
        while (SupportArticle::query()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $counter++;
        }
        return $slug;
    }
}
