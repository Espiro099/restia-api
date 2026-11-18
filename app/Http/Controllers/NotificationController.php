<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AuthorizeBranch;
use App\Models\Notification;
use App\Notifications\Notifications;
use App\Support\Api;
use App\Support\Floor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use MongoDB\BSON\ObjectId;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Avisos del usuario en la sucursal (campana). Cada quien ve y marca solo los suyos. */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        [$page, $perPage] = Api::page($request);
        $in = Validator::make($request->query(), ['unread' => ['sometimes', 'in:true,false']])->validate();
        $query = $this->mine($request);
        $unread = (clone $query)->whereNull('read_at')->count();
        if (($in['unread'] ?? 'false') === 'true') {
            $query->whereNull('read_at');
        }
        $total = (clone $query)->count();
        $items = $query->orderBy('updated_at', 'desc')->orderBy('_id', 'desc')->skip(($page - 1) * $perPage)->take($perPage)->get();

        return Api::data($items->map(fn ($n) => Notifications::present($n))->all(), meta: Api::pagination($page, $perPage, $total) + ['unreadCount' => $unread]);
    }

    /** Sin If-Match: marcar leído dos veces deja lo mismo. */
    public function read(Request $request, string $branchId, string $notificationId): JsonResponse
    {
        $n = $this->mine($request)->where('uuid', Floor::uuid($notificationId))->first() ?? throw new NotFoundHttpException;
        if ($n->read_at === null) {
            // Leer no cambia la hora del aviso ni su lugar en la lista.
            $n->timestamps = false;
            $n->update(['read_at' => now()]);
        }

        return Api::data(Notifications::present($n->refresh()));
    }

    public function readAll(Request $request): JsonResponse
    {
        $updated = Notifications::readAll(AuthorizeBranch::branch($request), (string) $request->user()->getKey());

        return Api::data(['updated' => $updated, 'unreadCount' => 0]);
    }

    private function mine(Request $request)
    {
        return Notification::query()->forBranch(AuthorizeBranch::branch($request))->where('user_id', new ObjectId($request->user()->getKey()));
    }
}
