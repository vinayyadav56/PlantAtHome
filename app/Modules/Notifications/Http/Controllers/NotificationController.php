<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Application\NotificationService;
use App\Modules\Notifications\Infrastructure\Models\NotificationLog;
use App\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class NotificationController extends ApiController
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /** GET /api/v1/notifications/log (admin) */
    public function log(Request $request): JsonResponse
    {
        $query = NotificationLog::query()->latest('id');
        if ($event = $request->query('event')) {
            $query->where('event_name', $event);
        }

        return $this->paginated($query->paginate(min((int) $request->query('limit', 30), 100)), fn (NotificationLog $l) => [
            'uuid' => $l->uuid, 'event_name' => $l->event_name, 'channel' => $l->channel,
            'recipient' => $l->recipient, 'subject' => $l->subject, 'body' => $l->body, 'status' => $l->status,
        ]);
    }

    /** POST /api/v1/notifications/templates (admin) */
    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_name' => ['required', 'string', 'max:128'],
            'channel'    => ['required', Rule::in(['email', 'sms', 'whatsapp', 'push'])],
            'subject'    => ['nullable', 'string', 'max:255'],
            'body'       => ['required', 'string'],
            'is_active'  => ['nullable', 'boolean'],
        ]);
        $template = $this->notifications->createTemplate($data);

        return $this->created(['uuid' => $template->uuid, 'event_name' => $template->event_name, 'channel' => $template->channel]);
    }
}
