<?php

use App\Models\User;
use App\Shared\Realtime\AdminDataChanged;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Admin dashboards: any role-bearing account (client accounts are roleless).
Broadcast::channel(AdminDataChanged::CHANNEL, function (User $user) {
    return $user->roles()->exists();
});
