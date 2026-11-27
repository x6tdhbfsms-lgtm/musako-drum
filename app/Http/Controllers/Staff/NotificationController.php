<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\AdminNotificationFeed;
use Illuminate\Contracts\View\View;

class NotificationController extends Controller
{
    public function __invoke(AdminNotificationFeed $notificationFeed): View
    {
        $requests = $notificationFeed->items();
        $pauseDeadlines = $notificationFeed->upcomingPauseDeadlines();

        return view('staff.notifications.index', [
            'notifications' => $requests
                ->concat($pauseDeadlines)
                ->sortByDesc(fn (array $item): int => $item['sort_at']->getTimestamp())
                ->values(),
            'pendingRequestCount' => $requests->count(),
            'upcomingPauseDeadlineCount' => $pauseDeadlines->count(),
        ]);
    }
}
