<?php

namespace App\Support\Services\Strategies;

use App\Models\LiveClassStudent as LiveClassReservation;
use App\Models\TeacherShift;
use App\Support\DataTransferObjects\RoutingCriteriaDto;
use App\Support\Services\LiveClassService;
use App\Support\Services\TeacherService;
use App\Traits\RoutingLogContextTrait;
use Facades\App\Support\Services\RoutingCriteriaService;
use Illuminate\Support\Facades\Cache;
use Log;

class RegularRoutingStrategy implements RoutingStrategyInterface
{
    use RoutingLogContextTrait;

    const REGULAR_ROUTING_LOCK_TTL_SECONDS = 3;

    private LiveClassService $liveClassService;
    private TeacherService $teacherService;

    private ?string $teacherShiftLockKey = null;

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

        $foundLiveClass = $this->liveClassService->findExistingRegularLiveClass($routingCriteria);
        if (!$foundLiveClass) {
            Log::info('Could not find an available live class. Starting teacher matching.', $logContext);

            $teacherFound = $this->getAvailableTeacherShift($routingCriteria, $logContext);
            if ($teacherFound) {
                Log::info('Teacher found.', $logContext);
                $teacherAssigned = $liveClassReservation->live_class->assignTeacherShift($teacherFound);
                $logContext = $this->logContext($liveClassReservation, ['teacher' => $teacherFound, 'assigned' => $teacherAssigned]);
                Log::info('Teacher assigned: ' . ($teacherAssigned ? 'true' : 'false'), $logContext);
                return $teacherAssigned;
            } else {
                Log::info('Force release if there are no shifts available.', $logContext);
                $this->releaseTeacherShiftLock($logContext);
            }
            Log::info('Could not find an available teacher.', $logContext);
            return false;
        }

        $moveResult = $this->liveClassService->attachReservationToExistingLiveClass(
            $liveClassReservation,
            $foundLiveClass,
            false
        );
        $logContext = $this->logContext($liveClassReservation, ['live_class' => $foundLiveClass, 'move_result' => $moveResult]);
        Log::info('Live class found. Moving student.', $logContext);

        return $moveResult;
    }

    private static function getLockKeyFromRoutingCriteria(RoutingCriteriaDto $routingCriteria): string
    {
        return implode('-', [
            $routingCriteria->start_time,
            $routingCriteria->live_class_teacher_language_type,
            $routingCriteria->student_native_language,
            $routingCriteria->live_class_topic_type,
            $routingCriteria->live_class_category_id,
            $routingCriteria->age_group_id,
            $routingCriteria->is_private ? 1 : 0,
            $routingCriteria->region
        ]);
    }

    private function getAvailableTeacherShift(RoutingCriteriaDto $routingCriteria, array $logContext): ?TeacherShift
    {
        $this->teacherShiftLockKey = RegularRoutingStrategy::getLockKeyFromRoutingCriteria($routingCriteria);
        Log::info("Attempting to get the lock to get an available teacher: $this->teacherShiftLockKey", $logContext);
        $lock = Cache::lock($this->teacherShiftLockKey, self::REGULAR_ROUTING_LOCK_TTL_SECONDS)->get();
        if ($lock) {
            Log::info('lock obtained. Searching available teacher.', $logContext);
            $teacherFound = $this->teacherService->findAvailableTeacher($routingCriteria);
        } else {
            Log::info('Unable to get the lock to get an available teacher', $logContext);
            return null;
        }

        return $teacherFound;
    }

    private function releaseTeacherShiftLock(array $logContext): void
    {
        if ($this->teacherShiftLockKey) {
            Cache::lock($this->teacherShiftLockKey)->release();
            Log::info('Lock released.', $logContext);
        }
    }
}