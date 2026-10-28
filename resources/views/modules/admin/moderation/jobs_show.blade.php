@extends('layouts.dashboard')

@section('page-title', 'Job Details - #' . $job->id)

@section('content')
<div class="card" style="margin-bottom: 1.5rem; font-family: 'Inter', sans-serif;">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f3f4f6; padding-bottom: 0.75rem; margin-bottom: 1.5rem;">
        <h3 style="font-size: 1.15rem; font-weight: 600; color: #111827; margin: 0;"><i class="fa-solid fa-business-time" style="color: #0d9488; margin-right: 0.5rem;"></i> Job Listing Moderation Details</h3>
        <div class="card-header-actions">
            <a href="{{ route('admin.moderation.jobs') }}" class="btn-cancel" style="text-decoration: none; padding: 0.5rem 1rem; border-radius: 0.375rem; background: #f3f4f6; color: #374151; font-weight: 500; font-size: 0.875rem;"><i class="fa-solid fa-arrow-left"></i> Back to List</a>
        </div>
    </div>

    {{-- Poster Info Header --}}
    <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; border-bottom: 1px solid #f3f4f6; padding-bottom: 1.5rem;">
        @if($job->poster?->avatar)
            <img src="{{ asset('storage/' . $job->poster->avatar) }}" alt="" style="width: 56px; height: 56px; border-radius: 50%; object-fit: cover; border: 2px solid #e5e7eb;"/>
        @else
            <div style="width: 56px; height: 56px; border-radius: 50%; background: #f3f4f6; display: flex; align-items: center; justify-content: center; border: 2px solid #e5e7eb;">
                <i class="fa-solid fa-user" style="font-size: 1.25rem; color: #9ca3af;"></i>
            </div>
        @endif
        <div>
            <div style="font-weight: 600; font-size: 1.05rem; display: flex; align-items: center; gap: 0.5rem;">
                <span>{{ $job->poster?->full_name ?? 'Unknown Poster' }}</span>
                @if($job->poster?->user)
                    <a href="{{ route('admin.users.show', $job->poster->user) }}" style="font-size: 0.75rem; text-decoration: none; color: #3b82f6; font-weight: normal;"><i class="fa-solid fa-external-link"></i> View Poster Profile</a>
                @endif
            </div>
            <div style="font-size: 0.8125rem; color: #6b7280;">
                @if($job->poster?->username) @ {{ $job->poster->username }} &bull; @endif 
                {{ $job->poster?->email ?? ($job->poster?->user?->email ?? 'No Email') }}
            </div>
            <div style="display: flex; gap: 0.5rem; margin-top: 0.35rem; align-items: center;">
                @if($job->is_active)
                    <span class="badge" style="background: #f0fdf4; color: #166534; font-size: 0.75rem; padding: 0.15rem 0.5rem;">Active</span>
                @else
                    <span class="badge" style="background: #fef2f2; color: #b91c1c; font-size: 0.75rem; padding: 0.15rem 0.5rem;">Deactivated / Closed</span>
                @endif
                <span style="font-size: 0.75rem; color: #9ca3af;">Posted: {{ $job->created_at->format('d M Y, h:i A') }}</span>
            </div>
        </div>
    </div>

    {{-- Job Core Info --}}
    <div style="display: flex; flex-direction: column; gap: 1rem; margin-bottom: 1.5rem;">
        <div>
            <h2 style="font-size: 1.25rem; font-weight: 700; color: #1f2937; margin: 0 0 0.25rem 0;">{{ $job->title }}</h2>
            <div style="font-size: 0.9rem; color: #4b5563; font-weight: 600;">
                Company: <span style="color: #111827;">{{ $job->company }}</span> &bull; 
                Location: <span style="color: #111827;">{{ $job->location }} @if($job->is_remote) (Remote) @endif</span>
            </div>
            <div style="display: flex; gap: 0.5rem; align-items: center; margin-top: 0.5rem;">
                <span class="badge" style="background: #eff6ff; color: #1e40af; font-size: 0.75rem; padding: 0.15rem 0.5rem; text-transform: capitalize;">{{ str_replace('_', ' ', $job->job_type) }}</span>
            </div>
        </div>

        <div style="font-size: 1.15rem; font-weight: 700; color: #0d9488; margin-top: 0.25rem;">
            Salary: 
            @if($job->salary_min && $job->salary_max)
                £{{ number_format($job->salary_min, 0) }} - £{{ number_format($job->salary_max, 0) }}
            @elseif($job->salary_min)
                Min £{{ number_format($job->salary_min, 0) }}
            @else
                Salary Undisclosed
            @endif
        </div>

        <div style="background: #f9fafb; padding: 1rem; border-radius: 0.5rem; border: 1px solid #f3f4f6;">
            <div style="font-weight: 600; font-size: 0.75rem; color: #9ca3af; text-transform: uppercase; margin-bottom: 0.5rem; letter-spacing: 0.05em;">Job Description</div>
            <div style="font-size: 0.875rem; line-height: 1.6; color: #374151; white-space: pre-wrap;">{{ $job->description ?? 'No description provided.' }}</div>
        </div>
    </div>

    {{-- Moderation Controls --}}
    <div style="border-top: 1px solid #f3f4f6; padding-top: 1.5rem; display: flex; gap: 0.75rem;">
        @if($job->is_active)
            <form method="POST" action="{{ route('admin.moderation.jobs.deactivate', $job) }}">
                @csrf
                <button type="submit" class="btn-reject" style="padding: 0.6rem 1.25rem; font-size: 0.875rem; font-weight: 500;" onclick="return confirm('Deactivate this job listing?')">
                    <i class="fa-solid fa-ban"></i> Deactivate Job
                </button>
            </form>
        @else
            <form method="POST" action="{{ route('admin.moderation.jobs.activate', $job) }}">
                @csrf
                <button type="submit" class="btn-approve" style="padding: 0.6rem 1.25rem; font-size: 0.875rem; font-weight: 500;">
                    <i class="fa-solid fa-check"></i> Activate Job
                </button>
            </form>
        @endif
    </div>
</div>

{{-- Job Applications List --}}
<div class="card" style="margin-bottom: 1.5rem; font-family: 'Inter', sans-serif;">
    <div class="card-header" style="border-bottom: 1px solid #f3f4f6; padding-bottom: 0.75rem; margin-bottom: 1.25rem;">
        <h3 style="font-size: 1rem; font-weight: 600; color: #1b1b18; margin: 0;"><i class="fa-solid fa-file-signature" style="color: #0d9488; margin-right: 0.5rem;"></i> Job Applications ({{ $job->applications->count() }})</h3>
    </div>
    
    @if($job->applications->isEmpty())
        <p style="text-align: center; color: #999; padding: 2rem; font-size: 0.875rem; margin: 0;">No applications received for this job yet.</p>
    @else
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left;">
                    <th style="padding: 0.5rem; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; font-weight: 600; border-bottom: 2px solid #e5e7eb;">Applicant</th>
                    <th style="padding: 0.5rem; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; font-weight: 600; border-bottom: 2px solid #e5e7eb;">Status</th>
                    <th style="padding: 0.5rem; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; font-weight: 600; border-bottom: 2px solid #e5e7eb;">Applied At</th>
                    <th style="padding: 0.5rem; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; font-weight: 600; text-align: right; border-bottom: 2px solid #e5e7eb; width: 160px;">Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach($job->applications as $app)
                    <tr style="border-bottom: 1px solid #f3f4f6;">
                        <td style="padding: 0.75rem 0.5rem; font-size: 0.875rem;">
                            <div style="font-weight: 600; color: #111827;">{{ $app->applicant?->full_name ?? 'Unknown' }}</div>
                            <div style="font-size: 0.75rem; color: #6b7280;">{{ $app->applicant?->email ?? ($app->applicant?->user?->email ?? 'No email') }}</div>
                        </td>
                        <td style="padding: 0.75rem 0.5rem;">
                            @if($app->status === 'accepted')
                                <span class="badge" style="background: #f0fdf4; color: #166534;">Accepted</span>
                            @elseif($app->status === 'rejected')
                                <span class="badge" style="background: #fef2f2; color: #b91c1c;">Rejected</span>
                            @else
                                <span class="badge" style="background: #fef3c7; color: #d97706;">{{ ucfirst($app->status) }}</span>
                            @endif
                        </td>
                        <td style="padding: 0.75rem 0.5rem; font-size: 0.8125rem; color: #4b5563;">
                            {{ $app->created_at->format('Y-m-d H:i') }}
                        </td>
                        <td style="padding: 0.75rem 0.5rem; text-align: right; white-space: nowrap;">
                            <a href="{{ route('admin.moderation.job-applications.show', $app) }}" class="btn-edit" style="text-decoration: none; font-size: 0.75rem; padding: 0.25rem 0.5rem;"><i class="fa-solid fa-eye"></i> View Application</a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection
