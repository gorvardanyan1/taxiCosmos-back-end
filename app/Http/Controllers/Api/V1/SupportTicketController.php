<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AppClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSupportMessageRequest;
use App\Http\Requests\Api\V1\StoreSupportTicketRequest;
use App\Http\Resources\Api\V1\SupportTicketResource;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Support\SupportTicketService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The rider and driver apps' support tickets (P7-T5). Every ticket is looked up through the signed-in person and the app they
 * are using, so another person's ticket (or the same person's ticket from the other app) is simply "not found". Internal
 * notes are never part of any response.
 */
class SupportTicketController extends Controller
{
    private const PER_PAGE = 20;

    public function __construct(private readonly SupportTicketService $tickets) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->mine($request)->orderByDesc('last_message_at')->orderByDesc('id')->paginate(self::PER_PAGE);

        return response()->json([
            'data' => SupportTicketResource::collection($page->getCollection())->resolve(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        $found = $this->mine($request)->with(['messages' => fn ($query) => $query->where('is_internal_note', false)->orderBy('id')])->findOrFail($ticket);

        return response()->json(['data' => (new SupportTicketResource($found))->resolve()]);
    }

    public function store(StoreSupportTicketRequest $request): JsonResponse
    {
        ['ticket' => $ticket, 'created' => $created] = $this->tickets->open(
            $this->user($request), $this->app($request), $request->category(), $request->validated('subject'), $request->validated('message'),
            $request->validated('trip_id'), $request->idempotencyKey(),
        );

        return response()->json(['data' => (new SupportTicketResource($ticket))->resolve()], $created ? 201 : 200);
    }

    public function storeMessage(StoreSupportMessageRequest $request, int $ticket): JsonResponse
    {
        ['message' => $message, 'created' => $created] = $this->tickets->requesterMessage($this->user($request), $this->mine($request)->findOrFail($ticket), $request->validated('body'), $request->validated('idempotency_key'));
        $fresh = SupportTicket::query()->findOrFail($message->ticket_id);

        return response()->json([
            'data' => [
                'id' => $message->id,
                'from' => 'you',
                'body' => $message->body,
                'created_at' => $message->created_at->toIso8601String(),
                'ticket_status' => $fresh->status->value,
            ],
        ], $created ? 201 : 200);
    }

    /** @return Builder<SupportTicket> */
    private function mine(Request $request)
    {
        return SupportTicket::query()->where('requester_id', $this->user($request)->getKey())->where('requester_type', $this->app($request)->value);
    }

    private function app(Request $request): AppClient
    {
        $abilities = $this->user($request)->currentAccessToken()->abilities ?? [];

        foreach (AppClient::cases() as $app) {
            if (in_array($app->value, $abilities, true)) {
                return $app;
            }
        }

        abort(403);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
