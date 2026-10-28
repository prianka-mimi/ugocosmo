<?php

namespace App\Manager;

use App\Models\JobApplication;
use App\Models\JobListing;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

class JobApplicationManager
{
    /**
     * Submit a new job application.
     */
    public function store(JobListing $job, array $data): JobApplication
    {
        $profileId = $this->profileId();

        // 1. Prevent duplicate application
        $exists = JobApplication::where('job_id', $job->id)
            ->where('applicant_id', $profileId)
            ->exists();
        if ($exists) {
            throw new \Exception('You have already applied for this job.', 400);
        }

        // 2. Resolve CV/Resume path
        $cvPath = null;
        if (isset($data['cv_file']) && $data['cv_file'] instanceof UploadedFile) {
            // Upload new CV file
            $cvPath = $data['cv_file']->store('resumes', 'public');
        } else {
            // Check profile CV
            $user = Auth::guard('jwt')->user();
            if ($user && ! empty($user->cv_resume)) {
                $cvPath = $user->cv_resume;
            } else {
                throw new \Exception('Resume/CV is required. Upload one or set one in your profile settings.', 400);
            }
        }

        // 3. Process qualifying questions and auto-decline logic
        $isQualified = true;
        $processedAnswers = [];
        $qualifyingQuestions = $job->qualifyingQuestions;

        if ($qualifyingQuestions->isNotEmpty()) {
            $submittedAnswers = $data['answers'] ?? [];
            $answersMap = collect($submittedAnswers)->keyBy('question_id')->toArray();

            foreach ($qualifyingQuestions as $q) {
                $candAnswer = null;
                if (isset($answersMap[$q->id])) {
                    $candAnswer = trim((string) $answersMap[$q->id]['answer']);
                }

                $expected = trim((string) $q->expected_answer);
                $isCorrect = (strtolower($candAnswer) === strtolower($expected));

                // If the question is required, and candidate did not answer or answer is incorrect: auto-decline
                if ($q->is_required && ! $isCorrect) {
                    $isQualified = false;
                }

                $processedAnswers[] = [
                    'question_id' => $q->id,
                    'question' => $q->question,
                    'expected_answer' => $q->expected_answer,
                    'answer' => $candAnswer,
                    'is_required' => (bool) $q->is_required,
                    'is_correct' => $isCorrect,
                ];
            }
        }

        $status = $isQualified ? 'applied' : 'declined';

        return JobApplication::create([
            'job_id' => $job->id,
            'applicant_id' => $profileId,
            'status' => $status,
            'answers' => $processedAnswers,
            'is_qualified' => $isQualified,
            'is_shortlisted' => false,
            'cover_letter' => $data['cover_letter'] ?? null,
            'cv_resume' => $cvPath,
        ]);
    }

    /**
     * List all applications for a specific job (Employer view).
     */
    public function index(JobListing $job): array
    {
        $this->ensureJobOwned($job);

        return $job->applications()
            ->with(['applicant', 'job'])
            ->latest()
            ->get()
            ->all();
    }

    /**
     * List own job applications (Candidate view).
     */
    public function mine(array $params = []): array
    {
        $profileId = $this->profileId();

        $query = JobApplication::where('applicant_id', $profileId)
            ->with(['job', 'applicant'])
            ->latest();

        if (! empty($params['status'])) {
            $query->where('status', strtolower(trim($params['status'])));
        }

        if (! empty($params['search'])) {
            $search = $params['search'];
            $query->whereHas('job', function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%");
            });
        }

        return $query->get()->all();
    }

    /**
     * View single job application details.
     */
    public function show(JobApplication $application): JobApplication
    {
        $profileId = $this->profileId();
        $job = $application->job;

        $isApplicant = ((int) $application->applicant_id === (int) $profileId);
        $isJobPoster = ($job && (int) $job->poster_id === (int) $profileId);

        if (! $isApplicant && ! $isJobPoster) {
            abort(403, 'Unauthorized access to this application.');
        }

        return $application->load(['job', 'applicant']);
    }

    /**
     * Update application status (Employer view).
     */
    public function updateStatus(JobApplication $application, string $status): JobApplication
    {
        $job = $application->job;
        $this->ensureJobOwned($job);

        $statusNormalized = strtolower(trim($status));

        $application->status = $statusNormalized;
        if ($statusNormalized === 'shortlisted') {
            $application->is_shortlisted = true;
        } elseif ($statusNormalized === 'declined' || $statusNormalized === 'rejected') {
            $application->is_shortlisted = false;
        }

        $application->save();

        return $application->load(['job', 'applicant']);
    }

    /**
     * Get the authenticated profile ID.
     */
    protected function profileId(): int
    {
        $user = Auth::guard('jwt')->user();
        $profile = $user?->profile;

        if (! $profile) {
            throw new \Exception('Profile not found. Complete your profile first.', 400);
        }

        return (int) $profile->id;
    }

    /**
     * Ensure the authenticated user owns the job listing.
     */
    protected function ensureJobOwned(JobListing $job): void
    {
        if ((int) $job->poster_id !== (int) $this->profileId()) {
            abort(403, 'You do not own this job listing.');
        }
    }
}
