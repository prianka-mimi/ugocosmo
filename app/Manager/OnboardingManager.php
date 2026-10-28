<?php

namespace App\Manager;

use App\Models\Qualification;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\WorkHistory;
use Illuminate\Support\Facades\Auth;

class OnboardingManager
{
    public function completionStatus(): array
    {
        $user = Auth::guard('jwt')->user();

        $steps = [
            'step_1_basic_information' => $this->checkStep1($user),
            'step_2_bio_skills_interests' => $this->checkStep2($user),
            'step_3_cv_resume' => $this->checkStep3($user),
            'step_4_work_history' => $this->checkStep4($user),
            'step_5_qualification' => $this->checkStep5($user),
            'step_6_subscription_plan' => $this->checkStep6($user),
            'step_7_terms_guidelines' => $this->checkStep7($user),
        ];

        $completedSteps = collect($steps)->filter(fn (array $step) => $step['completed'])->keys()->values()->all();
        $totalSteps = count($steps);
        $completedCount = count($completedSteps);
        $progressPercent = $totalSteps > 0 ? (int) round(($completedCount / $totalSteps) * 100) : 0;
        $allCompleted = $completedCount === $totalSteps;

        return [
            'steps' => $steps,
            'completed_steps' => $completedSteps,
            'total_steps' => $totalSteps,
            'completed_count' => $completedCount,
            'progress_percent' => $progressPercent,
            'all_completed' => $allCompleted,
            'onboarding_completed' => (bool) $user->onboarding_completed,
        ];
    }

    public function acceptTerms(array $data): array
    {
        $user = Auth::guard('jwt')->user();

        if ($data['accept_terms'] && $data['accept_guidelines']) {
            $user->terms_accepted = true;
            $user->terms_accepted_at = now();
            $user->save();
        }

        return $this->completionStatus();
    }

    public function complete(): array
    {
        $status = $this->completionStatus();

        if (! $status['all_completed']) {
            throw new \Exception('Cannot complete onboarding. All steps must be completed first.', 422);
        }

        $user = Auth::guard('jwt')->user();
        $user->onboarding_completed = true;
        $user->onboarding_completed_at = now();
        $user->save();

        $status['onboarding_completed'] = true;

        return $status;
    }

    protected function checkStep1(User $user): array
    {
        $filled = [
            'profile_image' => ! empty($user->avatar),
            'occupation' => ! empty($user->occupation),
            'dob' => ! empty($user->dob),
            'marital_status' => ! empty($user->marital_status),
        ];

        return [
            'completed' => collect($filled)->every(fn (bool $value) => $value),
            'fields' => $filled,
        ];
    }

    protected function checkStep2(User $user): array
    {
        $filled = [
            'bio' => ! empty($user->bio),
            'skills' => is_array($user->skills) && count($user->skills) > 0,
            'interests' => is_array($user->interests) && count($user->interests) > 0,
        ];

        return [
            'completed' => collect($filled)->every(fn (bool $value) => $value),
            'fields' => $filled,
        ];
    }

    protected function checkStep3(User $user): array
    {
        $filled = [
            'cv_resume' => ! empty($user->cv_resume),
        ];

        return [
            'completed' => collect($filled)->every(fn (bool $value) => $value),
            'fields' => $filled,
        ];
    }

    protected function checkStep4(User $user): array
    {
        $count = WorkHistory::forUser((int) $user->id)->count();
        $hasEntries = $count > 0;

        return [
            'completed' => $hasEntries,
            'fields' => [
                'work_history_entries' => $hasEntries,
                'total_entries' => $count,
            ],
        ];
    }

    protected function checkStep5(User $user): array
    {
        $count = Qualification::forUser((int) $user->id)->count();
        $hasEntries = $count > 0;

        return [
            'completed' => $hasEntries,
            'fields' => [
                'qualification_entries' => $hasEntries,
                'total_entries' => $count,
            ],
        ];
    }

    protected function checkStep6(User $user): array
    {
        $profileId = (int) $user->profile?->id;
        $hasPlan = $profileId
            ? UserSubscription::forUser($profileId)->active()->exists()
            : false;

        return [
            'completed' => $hasPlan,
            'fields' => [
                'has_active_subscription' => $hasPlan,
            ],
        ];
    }

    protected function checkStep7(User $user): array
    {
        $accepted = (bool) $user->terms_accepted;

        return [
            'completed' => $accepted,
            'fields' => [
                'terms_accepted' => $accepted,
                'terms_accepted_at' => $user->terms_accepted_at?->toIso8601String(),
            ],
        ];
    }

    protected function userId(): int
    {
        return (int) Auth::guard('jwt')->id();
    }
}
