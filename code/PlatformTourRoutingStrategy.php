<?php

namespace App\Support\Services\Strategies;

use App\Models\LiveClassStudent as LiveClassReservation;
use App\Support\Services\LiveClassService;
use App\Support\Services\RoutingCriteriaService;
use App\Support\Services\TeacherService;
use App\Traits\RoutingLogContextTrait;
use Log;

class PlatformTourRoutingStrategy implements RoutingStrategyInterface
{
    use RoutingLogContextTrait;

    private LiveClassService $liveClassService;
    private TeacherService $teacherService;

    public function __construct(LiveClassService $liveClassService, TeacherService $teacherService)
    {
        $this->liveClassService = $liveClassService;
        $this->teacherService = $teacherService;
    }

    public function route(LiveClassReservation $liveClassReservation): bool
    {
        $routingCriteria = RoutingCriteriaService::create($liveClassReservation);
        $logContext = $this->logContext($liveClassReservation, $routingCriteria);
        Log::debug('Routing criteria built.', $logContext);

        $teacherFound = $this->teacherService->findAvailableTeacher($routingCriteria);
        if ($teacherFound) {
            Log::info('Teacher found.', $logContext);
            $teacherAssigned = $liveClassReservation->live_class->assignTeacherShift($teacherFound);
            $logContext = $this->logContext($liveClassReservation, ['teacher' => $teacherFound, 'assigned' => $teacherAssigned]);
            Log::debug('Teacher assigned: ' . ($teacherAssigned ? 'true' : 'false'), $logContext);

            return $teacherAssigned;
        }
        Log::debug('Could not find an available teacher.', $logContext);

        $foundLiveClass = $this->liveClassService->findExistingRegularLiveClass($routingCriteria);
        if (!$foundLiveClass) {
            Log::debug('Could not find an available live class.', $logContext);
            return false;
        }

        $moveResult = $this->liveClassService->attachReservationToExistingLiveClass($liveClassReservation, $foundLiveClass, false);
        $logContext = $this->logContext($liveClassReservation, ['live_class' => $foundLiveClass, 'move_result' => $moveResult]);
        Log::info('Live class found. Moving student.', $logContext);

        return $moveResult;
    }
}