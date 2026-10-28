@extends('layouts.dashboard')

@section('page-title', 'Job Application Details - #' . $application->id)

@section('content')
<div class="card" style="margin-bottom: 1.5rem; font-family: 'Inter', sans-serif;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f3f4f6; padding-bottom: 0.75rem; margin-bottom: 1.5rem;">
        <h3 style="font-size: 1.15rem; font-weight: 600; color: #111827; margin: 0;"><i class="fa-solid fa-file-contract" style="color: #0d9488; margin-right: 0.5rem;"></i> Job Application Moderation</h3>
        <div class="card-header-actions">
            <a href="{{ route('admin.moderation.job-applications') }}" class="btn-cancel" style="text-decoration: none; padding: 0.5rem 1rem; border-radius: 0.375rem; background: #f3f4f6; color: #374151; font-weight: 500; font-size: 0.875rem;"><i class="fa-solid fa-arrow-left"></i> Back to List</a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success" style="margin-bottom: 1.5rem;">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger" style="background: #fef2f2; border: 1px solid #fca5a5; color: #b91c1c; padding: 0.75rem 1rem; border-radius: 0.5rem; margin-bottom: 1.5rem; font-size: 0.8125rem;">{{ session('error') }}</div>
    @endif

    {{-- Applicant vs Job details --}}
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid #f3f4f6; padding-bottom: 1.5rem;">
        {{-- Applicant Details --}}
        <div style="background: #f9fafb; padding: 1.25rem; border-radius: 0.5rem; border: 1px solid #e5e7eb;">
            <div style="font-weight: 600; font-size: 0.75rem; color: #6b7280; text-transform: uppercase; margin-bottom: 0.75rem; letter-spacing: 0.05em;"><i class="fa-solid fa-user"></i> Applicant Details</div>
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                @if($application->applicant?->avatar_url)
                    <img src="{{ asset('storage/' . $application->applicant->avatar_url) }}" alt="" style="width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 1px solid #e5e7eb;"/>
                @else
                    <div style="width: 44px; height: 44px; border-radius: 50%; background: #e5e7eb; display: flex; align-items: center; justify-content: center;">
                        <i class="fa-solid fa-user" style="color: #9ca3af; font-size: 1rem;"></i>
                    </div>
                @endif
                <div>
                    <div style="font-weight: 600; font-size: 0.9rem; color: #111827;">{{ $application->applicant?->full_name ?? 'Unknown Applicant' }}</div>
                    <div style="font-size: 0.8rem; color: #6b7280;">
                        @if($application->applicant?->username) @ {{ $application->applicant->username }} &bull; @endif
                        {{ $application->applicant?->email ?? ($application->applicant?->user?->email ?? 'No email') }}
                    </div>
                    @if($application->applicant?->user)
                        <a href="{{ route('admin.users.show', $application->applicant->user) }}" style="font-size: 0.75rem; text-decoration: none; color: #3b82f6; display: inline-block; margin-top: 0.25rem;"><i class="fa-solid fa-external-link"></i> View Applicant User</a>
                    @endif
                </div>
            </div>
        </div>

        {{-- Job Poster Details --}}
        <div style="background: #f9fafb; padding: 1.25rem; border-radius: 0.5rem; border: 1px solid #e5e7eb;">
            <div style="font-weight: 600; font-size: 0.75rem; color: #6b7280; text-transform: uppercase; margin-bottom: 0.75rem; letter-spacing: 0.05em;"><i class="fa-solid fa-building"></i> Job Poster</div>
            <div style="display: flex; align-items: center; gap: 0.75rem;">
                @if($application->job?->poster?->avatar_url)
                    <img src="{{ asset('storage/' . $application->job->poster->avatar_url) }}" alt="" style="width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 1px solid #e5e7eb;"/>
                @else
                    <div style="width: 44px; height: 44px; border-radius: 50%; background: #e5e7eb; display: flex; align-items: center; justify-content: center;">
                        <i class="fa-solid fa-user-tie" style="color: #9ca3af; font-size: 1rem;"></i>
                    </div>
                @endif
                <div>
                    <div style="font-weight: 600; font-size: 0.9rem; color: #111827;">{{ $application->job?->poster?->full_name ?? 'Unknown Poster' }}</div>
                    <div style="font-size: 0.8rem; color: #6b7280;">
                        @if($application->job?->poster?->username) @ {{ $application->job->poster->username }} &bull; @endif
                        {{ $application->job?->poster?->email ?? ($application->job?->poster?->user?->email ?? 'No email') }}
                    </div>
                    @if($application->job?->poster?->user)
                        <a href="{{ route('admin.users.show', $application->job->poster->user) }}" style="font-size: 0.75rem; text-decoration: none; color: #3b82f6; display: inline-block; margin-top: 0.25rem;"><i class="fa-solid fa-external-link"></i> View Poster User</a>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Job Application Core Information --}}
    <div style="display: flex; flex-direction: column; gap: 1rem; margin-bottom: 1.5rem;">
        {{-- Job details --}}
        <div style="background: #f9fafb; padding: 1.25rem; border-radius: 0.5rem; border: 1px solid #e5e7eb;">
            <div style="font-weight: 600; font-size: 0.75rem; color: #6b7280; text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.05em;">Applied Job Listing</div>
            @if($application->job)
                <div style="font-size: 1.05rem; font-weight: 700; color: #111827;">{{ $application->job->title }}</div>
                <div style="font-size: 0.8125rem; color: #4b5563; margin-top: 0.25rem;">
                    Company: <strong>{{ $application->job->company }}</strong> &bull; Type: <strong>{{ ucfirst($application->job->job_type) }}</strong> &bull; Location: <strong>{{ $application->job->location }}</strong>
                </div>
                <a href="{{ route('admin.moderation.jobs.show', $application->job) }}" style="font-size: 0.78rem; text-decoration: none; color: #3b82f6; display: inline-block; margin-top: 0.5rem;"><i class="fa-solid fa-briefcase"></i> View Job Moderation Page</a>
            @else
                <div style="color: #dc2626; font-size: 0.875rem; font-weight: 600;">Job listing was deleted or is not available.</div>
            @endif
        </div>

        {{-- Application status information --}}
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
            <div style="border: 1px solid #e5e7eb; padding: 1rem; border-radius: 0.5rem;">
                <div style="font-size: 0.75rem; color: #6b7280; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">Applied At</div>
                <div style="font-size: 0.9rem; font-weight: 700; color: #111827;">
                    {{ $application->created_at->format('d M Y, h:i A') }}
                </div>
            </div>

            <div style="border: 1px solid #e5e7eb; padding: 1rem; border-radius: 0.5rem;">
                <div style="font-size: 0.75rem; color: #6b7280; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">Current Status</div>
                <div style="margin-top: 0.25rem;">
                    @if($application->status === 'accepted')
                        <span class="badge" style="background: #f0fdf4; color: #166534; font-size: 0.8rem; padding: 0.25rem 0.6rem;">Accepted</span>
                    @elseif($application->status === 'rejected')
                        <span class="badge" style="background: #fef2f2; color: #b91c1c; font-size: 0.8rem; padding: 0.25rem 0.6rem;">Rejected</span>
                    @else
                        <span class="badge" style="background: #fef3c7; color: #d97706; font-size: 0.8rem; padding: 0.25rem 0.6rem;">{{ ucfirst($application->status) }}</span>
                    @endif
                </div>
            </div>

            <div style="border: 1px solid #e5e7eb; padding: 1rem; border-radius: 0.5rem;">
                <div style="font-size: 0.75rem; color: #6b7280; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">Shortlisted</div>
                <div style="font-size: 0.9rem; font-weight: 700; color: #111827;">
                    {{ $application->is_shortlisted ? 'Yes' : 'No' }}
                </div>
            </div>

            <div style="border: 1px solid #e5e7eb; padding: 1rem; border-radius: 0.5rem;">
                <div style="font-size: 0.75rem; color: #6b7280; font-weight: 600; text-transform: uppercase; margin-bottom: 0.25rem;">Qualified</div>
                <div style="font-size: 0.9rem; font-weight: 700; color: #111827;">
                    {{ $application->is_qualified ? 'Yes' : 'No' }}
                </div>
            </div>
        </div>

        {{-- Cover Letter --}}
        @if($application->cover_letter)
            <div style="background: #f9fafb; padding: 1.25rem; border-radius: 0.5rem; border: 1px solid #e5e7eb;">
                <div style="font-weight: 600; font-size: 0.75rem; color: #6b7280; text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.05em;">Cover Letter</div>
                <div style="font-size: 0.875rem; line-height: 1.6; color: #374151; white-space: pre-wrap;">{{ $application->cover_letter }}</div>
            </div>
        @endif

        {{-- CV / Resume link --}}
        @if($application->cv_resume)
            <div style="background: #f9fafb; padding: 1rem; border-radius: 0.5rem; border: 1px solid #e5e7eb;">
                <div style="font-weight: 600; font-size: 0.75rem; color: #6b7280; text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.05em;">Attached CV / Resume</div>
                <a href="{{ asset('storage/' . $application->cv_resume) }}" target="_blank" style="display: inline-flex; align-items: center; gap: 0.5rem; text-decoration: none; padding: 0.5rem 1rem; border-radius: 0.375rem; background: #3b82f6; color: #fff; font-size: 0.8125rem; font-weight: 600;">
                    <i class="fa-solid fa-file-pdf"></i> View / Download CV
                </a>
            </div>
        @endif

        {{-- Answers to Job Questions --}}
        @if($application->answers && is_array($application->answers) && count($application->answers) > 0)
            <div style="background: #f9fafb; padding: 1.25rem; border-radius: 0.5rem; border: 1px solid #e5e7eb;">
                <div style="font-weight: 600; font-size: 0.75rem; color: #6b7280; text-transform: uppercase; margin-bottom: 0.75rem; letter-spacing: 0.05em;">Application Answers</div>
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    @foreach($application->answers as $answerItem)
                        <div style="background: #fff; padding: 1rem; border-radius: 0.375rem; border: 1px solid #e5e7eb;">
                            <div style="font-weight: 600; font-size: 0.875rem; color: #111827; margin-bottom: 0.5rem;">
                                <strong>Question:</strong> {{ $answerItem['question'] ?? 'N/A' }}
                            </div>
                            <div style="font-size: 0.8125rem; color: #4b5563; display: flex; flex-direction: column; gap: 0.35rem;">
                                <div>
                                    <strong>Expected Answer:</strong> 
                                    <span class="badge" style="background: #f0fdf4; color: #166534; font-size: 0.75rem; padding: 0.1rem 0.4rem; text-transform: uppercase;">
                                        {{ $answerItem['expected_answer'] ?? 'N/A' }}
                                    </span>
                                </div>
                                <div>
                                    <strong>User Answer:</strong> 
                                    <span class="badge" style="background: {{ ($answerItem['is_correct'] ?? false) ? '#f0fdf4' : '#fef2f2' }}; color: {{ ($answerItem['is_correct'] ?? false) ? '#166534' : '#b91c1c' }}; font-size: 0.75rem; padding: 0.1rem 0.4rem; text-transform: uppercase;">
                                        {{ $answerItem['answer'] ?? 'N/A' }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- Moderation Controls --}}
    <div style="border-top: 1px solid #f3f4f6; padding-top: 1.5rem; display: flex; gap: 0.75rem;">
        @if($application->status !== 'rejected')
            <form method="POST" action="{{ route('admin.moderation.job-applications.deactivate', $application) }}">
                @csrf
                <button type="submit" class="btn-reject" style="padding: 0.6rem 1.25rem; font-size: 0.875rem; font-weight: 500;" onclick="return confirm('Reject this application?')">
                    <i class="fa-solid fa-ban"></i> Reject Application
                </button>
            </form>
        @else
            <form method="POST" action="{{ route('admin.moderation.job-applications.activate', $application) }}">
                @csrf
                <button type="submit" class="btn-approve" style="padding: 0.6rem 1.25rem; font-size: 0.875rem; font-weight: 500;">
                    <i class="fa-solid fa-check"></i> Accept Application
                </button>
            </form>
        @endif
    </div>
</div>
@endsection
