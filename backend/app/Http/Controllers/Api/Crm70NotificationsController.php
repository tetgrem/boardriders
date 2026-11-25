<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Crm70\Crm70Notifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class Crm70NotificationsController extends Controller
{
    public function index(
        Request $request,
        Crm70Notifications $service
    ): JsonResponse {
        $data = $request->validate([
            'category' => [
                'sometimes',
                'in:all,orders,payments,returns,delivery,fiscal,sla',
            ],
            'unread' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
        ]);

        return $this->response(
            $service->page($data)
        );
    }

    public function count(
        Crm70Notifications $service
    ): JsonResponse {
        return $this->response(
            $service->unreadCount()
        );
    }

    public function read(
        Request $request,
        Crm70Notifications $service
    ): JsonResponse {
        $data = $this->notificationInput($request);

        return $this->response(
            $service->markRead(
                $data['notification_key']
            )
        );
    }

    public function unread(
        Request $request,
        Crm70Notifications $service
    ): JsonResponse {
        $data = $this->notificationInput($request);

        return $this->response(
            $service->markUnread(
                $data['notification_key']
            )
        );
    }

    public function preference(
        Request $request,
        Crm70Notifications $service
    ): JsonResponse {
        $data = $request->validate([
            'category' => [
                'required',
                'in:orders,payments,returns,delivery,fiscal,sla',
            ],
            'enabled' => ['required', 'boolean'],
        ]);

        return $this->response(
            $service->setPreference(
                $data['category'],
                (bool) $data['enabled']
            )
        );
    }

    private function notificationInput(
        Request $request
    ): array {
        return $request->validate([
            'notification_key' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z_]+:[0-9]+(?::[0-9]+)?$/D',
            ],
        ]);
    }

    private function response(array $data): JsonResponse
    {
        return response()
            ->json($data)
            ->header('Cache-Control', 'no-store');
    }
}
