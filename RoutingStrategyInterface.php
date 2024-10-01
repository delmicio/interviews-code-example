<?php

namespace App\Support\Services\Strategies;

use App\Models\LiveClassStudent as LiveClassReservation;

interface RoutingStrategyInterface
{
    public function route(LiveClassReservation $liveClassReservation): bool;
}