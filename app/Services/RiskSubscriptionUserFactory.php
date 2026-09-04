<?php

namespace App\Services;

use App\Models\User;

class RiskSubscriptionUserFactory
{
    private const ORIGINAL_USER_FIELDS = [
        'u',
        'd',
        'transfer_enable',
        'expired_at',
        'token',
    ];

    public function make(User $originalUser, User $targetUser): User
    {
        $subscriptionUser = clone $targetUser;
        foreach (self::ORIGINAL_USER_FIELDS as $field) {
            $subscriptionUser->setAttribute($field, $originalUser->getAttribute($field));
        }

        return $subscriptionUser;
    }
}
