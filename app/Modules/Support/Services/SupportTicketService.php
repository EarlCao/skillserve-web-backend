<?php

namespace App\Modules\Support\Services;

use App\Models\User;
use App\Modules\Support\Actions\AddSupportTicketResponseAction;
use App\Modules\Support\Actions\AssignSupportTicketAction;
use App\Modules\Support\Actions\ResolveSupportTicketAction;
use App\Modules\Support\Events\SupportTicketAssigned;
use App\Modules\Support\Events\SupportTicketResolved;
use App\Modules\Support\Events\SupportTicketResponseAdded;
use App\Modules\Support\Models\SupportTicket;
use App\Modules\Support\Models\SupportTicketAttachment;
use App\Shared\Helpers\PageSize;
use App\Shared\Helpers\PrivateImages;
use App\Shared\Services\BaseService;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;

class SupportTicketService extends BaseService
{
    private const SORTABLE = ['created_at', 'updated_at', 'priority', 'status'];

    public function __construct(
        private readonly AssignSupportTicketAction $assignAction,
        private readonly AddSupportTicketResponseAction $responseAction,
        private readonly ResolveSupportTicketAction $resolveAction,
    ) {}

    public function index(array $filters): LengthAwarePaginator
    {
        $query = SupportTicket::query()->with($this->relations());

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $term = '%'.mb_strtolower($search).'%';
            $query->where(function ($builder) use ($term): void {
                $builder->whereRaw('LOWER(ticket_number) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(subject) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(description) LIKE ?', [$term])
                    ->orWhereHas('requester', fn ($user) => $user
                        ->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$term]))
                    ->orWhereHas('assignedTo', fn ($user) => $user
                        ->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(email) LIKE ?', [$term]));
            });
        }

        foreach (['status', 'priority', 'category', 'assigned_to'] as $field) {
            if (($filters[$field] ?? '') !== '') {
                $query->where($field, $filters[$field]);
            }
        }

        $sort = in_array($filters['sort'] ?? null, self::SORTABLE, true) ? $filters['sort'] : 'created_at';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $direction)->paginate($this->perPage($filters));
    }

    public function show(SupportTicket $ticket): SupportTicket
    {
        return $ticket->load($this->relations());
    }

    public function assignees(?string $search = null): array
    {
        $query = User::query()
            ->has('roles')
            ->where('status', 'active')
            ->select(['id', 'name', 'email'])
            ->orderBy('name');

        if ($search = trim((string) $search)) {
            $term = '%'.mb_strtolower($search).'%';
            $query->where(fn ($builder) => $builder
                ->whereRaw('LOWER(name) LIKE ?', [$term])
                ->orWhereRaw('LOWER(email) LIKE ?', [$term]));
        }

        return $query->limit(100)->get()->map(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ])->all();
    }

    public function assign(SupportTicket $ticket, ?int $assigneeId, User $actor): SupportTicket
    {
        $assignee = $assigneeId === null ? null : User::query()->findOrFail($assigneeId);

        return $this->transaction(function () use ($ticket, $assignee, $actor): SupportTicket {
            $ticket = $this->assignAction->handle($ticket, $assignee, $actor);
            event(new SupportTicketAssigned($ticket, $actor, $assignee));

            return $ticket;
        });
    }

    /**
     * A staff reply: text, [images], or both. The photos are stored before
     * the transaction and removed again if the reply is refused.
     *
     * @param  array<int, UploadedFile>  $images
     */
    public function addResponse(SupportTicket $ticket, User $actor, ?string $body, array $images = []): SupportTicket
    {
        $stored = PrivateImages::store($images, SupportTicketAttachment::DISK, (string) $actor->id);

        try {
            return $this->transaction(function () use ($ticket, $actor, $body, $stored): SupportTicket {
                $message = $this->responseAction->handle($ticket, $actor, (string) $body);

                foreach ($stored as $photo) {
                    $ticket->attachments()->create([
                        ...$photo,
                        'support_ticket_message_id' => $message->id,
                        'uploaded_by' => $actor->id,
                    ]);
                }

                $ticket = $ticket->fresh($this->relations());
                event(new SupportTicketResponseAdded($ticket, $message, $actor));

                return $ticket;
            });
        } catch (\Throwable $exception) {
            PrivateImages::delete(SupportTicketAttachment::DISK, $stored);

            throw $exception;
        }
    }

    public function resolve(SupportTicket $ticket, User $actor, string $resolutionNote): SupportTicket
    {
        return $this->transaction(function () use ($ticket, $actor, $resolutionNote): SupportTicket {
            $ticket = $this->resolveAction->handle($ticket, $actor, $resolutionNote);
            event(new SupportTicketResolved($ticket, $actor, trim($resolutionNote)));

            return $ticket;
        });
    }

    private function relations(): array
    {
        return [
            'requester:id,name,email,role_id',
            'assignedTo:id,name,email',
            'assignedBy:id,name,email',
            'resolvedBy:id,name,email',
            'messages.author:id,name,email',
            'attachments',
        ];
    }

    private function perPage(array $filters): int
    {
        return PageSize::from($filters);
    }
}
