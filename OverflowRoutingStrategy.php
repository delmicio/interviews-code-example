<?php

namespace App\Support\Services\Strategies;

use App\Models\LiveClassStudent as LiveClassReservation;
use App\Support\Services\LiveClassService;
use App\Traits\RoutingLogContextTrait;
use Facades\App\Support\Services\RoutingCriteriaService;
use Log;

class OverflowRoutingStrategy implements RoutingStrategyInterface
{
    use RoutingLogContextTrait;

    private LiveClassService $liveClassService;

    public function __construct(LiveClassService $liveClassService)
    {
        $this->liveClassService = $liveClassService;
    }

    public function route(LiveClassReservation $liveClassReservation): bool
    {
        $routingCriteria = RoutingCriteriaService::createForOverflow($liveClassReservation);
        $logContext = $this->logContext($liveClassReservation, $routingCriteria);
        Log::debug('Routing criteria built.', $logContext);

        $foundLiveClass = $this->liveClassService->findExistingOverflowLiveClass($routingCriteria);
        if (!$foundLiveClass) {
            Log::info('Could not find an available live class with overflow.', $logContext);
            return false;
        }

        $moveResult = $this->liveClassService->attachReservationToExistingLiveClass($liveClassReservation, $foundLiveClass, true);
        $logContext = $this->logContext($liveClassReservation, ['live_class' => $foundLiveClass, 'move_result' => $moveResult]);
        Log::info('Live class with overflow found. Moving student.', $logContext);

        return $moveResult;
    }
}
