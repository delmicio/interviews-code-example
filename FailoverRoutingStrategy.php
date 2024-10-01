<?php

namespace App\Support\Services\Strategies;

use App\Models\LiveClassStudent as LiveClassReservation;
use App\Support\Services\LiveClassService;
use Facades\App\Support\Services\RoutingCriteriaService;
use App\Traits\RoutingLogContextTrait;
use Log;

class FailoverRoutingStrategy implements RoutingStrategyInterface
{
    use RoutingLogContextTrait;

    private LiveClassService $liveClassService;

    public function __construct(LiveClassService $liveClassService)
    {
        $this->liveClassService = $liveClassService;
    }

    public function route(LiveClassReservation $liveClassReservation): bool
    {
        $routingCriteria = RoutingCriteriaService::createForFailover($liveClassReservation);
        $logContext = $this->logContext($liveClassReservation, $routingCriteria);
        Log::debug('Routing criteria built.', $logContext);

        $foundLiveClass = $this->liveClassService->findExistingFailoverLiveClass($routingCriteria);
        if (!$foundLiveClass) {
            Log::info('Could not find an available live class with failover.', $logContext);
            return false;
        }

        $moveResult = $this->liveClassService->attachReservationToExistingLiveClass($liveClassReservation, $foundLiveClass, true);
        $logContext = $this->logContext($liveClassReservation, ['live_class' => $foundLiveClass, 'move_result' => $moveResult]);
        Log::info('Live class with failover found. Moving student.', $logContext);

        return $moveResult;
    }
}
