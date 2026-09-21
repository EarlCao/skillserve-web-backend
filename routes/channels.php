<?php

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientCommunication\Policies\BookingMessagePolicy;
use App\Shared\Realtime\AdminDataChanged;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    // The background notification token polls; it never opens a socket, and
    // it must not receive chat messages this channel also carries.
    $token = $user->currentAccessToken();
    if ($token && $token->can(config('client-auth.background_ability'))
        && ! $token->can(config('client-auth.access_ability'))) {
        return false;
    }

    return (int) $user->id === (int) $id;
});

// One booking's conversation, as a presence channel: the two participants
// see whether the other has the chat open, and exchange "typing" client
// events (whispers). Only someone who may read the thread may join.
Broadcast::channel('booking-chat.{booking}', function (User $user, Booking $booking) {
    if (! app(BookingMessagePolicy::class)->viewAny($user, $booking)) {
        return false;
    }

    return ['id' => $user->id, 'name' => $user->name];
});

// Admin dashboards: any role-bearing account (client accounts are roleless).
Broadcast::channel(AdminDataChanged::CHANNEL, function (User $user) {
    return $user->roles()->exists();
});
