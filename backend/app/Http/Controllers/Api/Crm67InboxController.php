<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Crm67\Crm67Inbox;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class Crm67InboxController extends Controller
{
    public function index(Request $request, Crm67Inbox $inbox): JsonResponse
    {
        $filters = $request->validate([
            'scope' => ['sometimes', 'in:mine,unassigned,all'],
            'status' => ['sometimes', 'in:all,open,in_progress,resolved'],
            'priority' => ['sometimes', 'in:all,low,normal,high,critical'],
            'before_id' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        try {
            return response()->json($inbox->page($filters))
                ->header('Cache-Control', 'no-store');
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            report($exception);
            return response()->json(['message' => 'CRM inbox integrity check failed'], 503);
        }
    }
}
