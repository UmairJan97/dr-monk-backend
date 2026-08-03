<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('clinic.{clinicId}.user.{userId}', function (User $user, int $clinicId, int $userId) {
    return (int) $user->id === $userId
        && (int) $user->clinic_id === $clinicId
        && $user->is_active;
});
