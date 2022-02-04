<?php

namespace App\Listeners;

use App\Repositories\Notification\NotificationRepository;

class AssignHouseholdNotificationListener
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct(NotificationRepository $notificationRepository)
    {
        $this->notificationRepository = $notificationRepository;
    }

    /**
     * Handle the event.
     *
     * @param  object  $event
     * @return void
     */
    public function handle($event)
    {
        $household = $event->household;
        $response  = $this->notificationRepository->assignHouseholdNotification($household);
        return $response;
    }
}
