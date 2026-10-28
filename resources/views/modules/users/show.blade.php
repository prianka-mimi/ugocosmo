@extends('layouts.dashboard')

@section('page-title', 'User Profile Details')

@section('content')
<div style="display: flex; flex-direction: column; gap: 1.5rem; max-width: 1100px; margin: 0 auto; font-family: 'Inter', sans-serif;">
    
    {{-- Header Section: Cover and Avatar --}}
    <div class="card" style="padding: 0; overflow: hidden; border: none; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1); position: relative;">
        {{-- Cover Image / Gradient --}}
        @if($user->cover_photo)
            <div style="height: 180px; width: 100%; background-image: url('{{ asset('storage/' . $user->cover_photo) }}'); background-size: cover; background-position: center;"></div>
        @else
            <div style="height: 180px; width: 100%; background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 50%, #9333ea 100%);"></div>
        @endif

        {{-- Profile Info Overlay --}}
        <div style="padding: 0 1.5rem 1.5rem 1.5rem; display: flex; flex-direction: column; position: relative;">
            
            {{-- Back & Edit Action buttons absolute --}}
            <div style="position: absolute; right: 1.5rem; top: -145px; display: flex; gap: 0.5rem; z-index: 10;">
                <a href="{{ route('users.index') }}" class="btn-cancel" style="text-decoration: none; background: rgba(255, 255, 255, 0.9); backdrop-filter: blur(4px); box-shadow: 0 2px 4px rgba(0,0,0,0.05); color: #374151; font-weight: 500;"><i class="fa-solid fa-arrow-left" style="margin-right: 0.25rem;"></i> Back</a>
                <a href="{{ route('users.edit', $user) }}" class="btn-edit" style="background: #4f46e5; color: #fff; border: none; box-shadow: 0 2px 4px rgba(0,0,0,0.1); font-weight: 500;"><i class="fa-solid fa-pen" style="margin-right: 0.25rem;"></i> Edit Profile</a>
            </div>

            <div style="display: flex; align-items: flex-end; gap: 1.25rem; margin-top: -60px; margin-bottom: 1rem; flex-wrap: wrap;">
                {{-- Avatar --}}
                @if($user->avatar)
                    <img src="{{ asset('storage/' . $user->avatar) }}" alt="Avatar" style="width: 110px; height: 110px; border-radius: 50%; object-fit: cover; border: 4px solid #fff; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); background: #fff;"/>
                @else
                    <div style="width: 110px; height: 110px; border-radius: 50%; background: #f3f4f6; display: flex; align-items: center; justify-content: center; border: 4px solid #fff; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);">
                        <i class="fa-solid fa-user" style="font-size: 2.75rem; color: #d1d5db;"></i>
                    </div>
                @endif

                {{-- Basic User Info --}}
                <div style="padding-bottom: 0.25rem; flex: 1; min-width: 250px;">
                    <h2 style="font-size: 1.5rem; font-weight: 700; color: #111827; margin: 0 0 0.25rem 0; letter-spacing: -0.025em; display: flex; align-items: center; gap: 0.5rem;">
                        {{ $user->name }}
                        @if($user->is_verified)
                            <i class="fa-solid fa-circle-check" style="color: #3b82f6; font-size: 1.15rem;" title="Verified Profile"></i>
                        @endif
                    </h2>
                    <p style="font-size: 0.875rem; color: #6b7280; margin: 0 0 0.5rem 0;">
                        {{ '@' . $user->username }} &bull; {{ $user->email }}
                    </p>
                    <div style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                        <span class="badge" style="background: #eef2ff; color: #4f46e5; text-transform: uppercase; font-weight: 600; font-size: 0.7rem; padding: 0.2rem 0.6rem;">{{ $user->role ?? 'User' }}</span>
                        <x-status-badge :status="$user->status" />
                        @if($user->profile?->is_seller)
                            <span class="badge" style="background: #fef3c7; color: #d97706; font-weight: 600; font-size: 0.7rem; padding: 0.2rem 0.6rem;">Seller ({{ $user->profile->subscription_tier ?? 'Basic' }})</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Layout Details Grid --}}
    <div style="display: grid; grid-template-columns: 1fr; gap: 1.5rem;">
        
        {{-- Row 1: About / Bio Card (Highly Styled) --}}
        <div class="card" style="box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); border: none;">
            <div class="card-header" style="border-bottom: 1px solid #f3f4f6; margin-bottom: 1rem; padding-bottom: 0.75rem;">
                <h3 style="display: flex; align-items: center; gap: 0.5rem; font-size: 1.1rem; font-weight: 600; color: #111827; margin: 0;"><i class="fa-solid fa-address-card" style="color: #4f46e5;"></i> About User</h3>
            </div>
            <div style="position: relative; background: #faf5ff; border-left: 4px solid #a855f7; padding: 1.25rem; border-radius: 0 0.5rem 0.5rem 0; margin-bottom: 1rem;">
                <i class="fa-solid fa-quote-left" style="position: absolute; right: 1rem; top: 0.75rem; font-size: 2rem; color: #ebd8fc; opacity: 0.6;"></i>
                <div style="font-weight: 600; font-size: 0.75rem; text-transform: uppercase; color: #7c3aed; margin-bottom: 0.35rem; letter-spacing: 0.05em;">Biography / Intro</div>
                <p style="font-size: 0.925rem; line-height: 1.6; color: #4b5563; margin: 0; font-style: italic; position: relative; z-index: 1;">
                    {{ $user->bio ?? ($user->profile?->bio ?? 'This user has not added a biography yet.') }}
                </p>
            </div>
            
            @if($user->profile?->website)
                <div style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; margin-top: 0.5rem;">
                    <strong style="color: #4b5563;">Website:</strong>
                    <a href="{{ $user->profile->website }}" target="_blank" style="color: #2563eb; text-decoration: none;"><i class="fa-solid fa-globe"></i> {{ $user->profile->website }}</a>
                </div>
            @endif
        </div>

        {{-- Row 2: Grid containing Personal Info and Address Info --}}
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem;">
            
            {{-- Personal Details Card --}}
            <div class="card" style="box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); border: none;">
                <div class="card-header" style="border-bottom: 1px solid #f3f4f6; margin-bottom: 1rem; padding-bottom: 0.75rem;">
                    <h3 style="display: flex; align-items: center; gap: 0.5rem; font-size: 1.1rem; font-weight: 600; color: #111827; margin: 0;"><i class="fa-solid fa-circle-user" style="color: #4f46e5;"></i> Personal Details</h3>
                </div>
                <div style="display: flex; flex-direction: column; gap: 0.75rem; font-size: 0.875rem;">
                    <div style="display: flex; justify-content: space-between; border-bottom: 1px solid #f9fafb; padding-bottom: 0.5rem;">
                        <span style="color: #6b7280; font-weight: 500;">Account Type:</span>
                        <span style="font-weight: 600; color: #374151; text-transform: capitalize;">{{ $user->account_type ?? 'Individual' }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-bottom: 1px solid #f9fafb; padding-bottom: 0.5rem;">
                        <span style="color: #6b7280; font-weight: 500;">Mobile Number:</span>
                        <span style="font-weight: 600; color: #374151;">{{ $user->mobile_number ?? '-' }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-bottom: 1px solid #f9fafb; padding-bottom: 0.5rem;">
                        <span style="color: #6b7280; font-weight: 500;">Date of Birth:</span>
                        <span style="font-weight: 600; color: #374151;">{{ $user->dob ? $user->dob->format('d M Y') : '-' }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-bottom: 1px solid #f9fafb; padding-bottom: 0.5rem;">
                        <span style="color: #6b7280; font-weight: 500;">Marital Status:</span>
                        <span style="font-weight: 600; color: #374151; text-transform: capitalize;">{{ $user->marital_status ?? '-' }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-bottom: 1px solid #f9fafb; padding-bottom: 0.5rem;">
                        <span style="color: #6b7280; font-weight: 500;">Verified:</span>
                        <span style="font-weight: 600; color: #374151;">
                            @if($user->is_verified)
                                <span style="color: #166534; background: #ecfdf5; padding: 0.15rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600;">Yes</span>
                            @else
                                <span style="color: #b91c1c; background: #fef2f2; padding: 0.15rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600;">No</span>
                            @endif
                        </span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #6b7280; font-weight: 500;">Member Since:</span>
                        <span style="font-weight: 600; color: #374151;">{{ $user->created_at ? $user->created_at->format('d M Y') : '-' }}</span>
                    </div>
                </div>
            </div>

            {{-- Location & Address Card --}}
            <div class="card" style="box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); border: none;">
                <div class="card-header" style="border-bottom: 1px solid #f3f4f6; margin-bottom: 1rem; padding-bottom: 0.75rem;">
                    <h3 style="display: flex; align-items: center; gap: 0.5rem; font-size: 1.1rem; font-weight: 600; color: #111827; margin: 0;"><i class="fa-solid fa-map-location-dot" style="color: #4f46e5;"></i> Location Info</h3>
                </div>
                <div style="display: flex; flex-direction: column; gap: 0.75rem; font-size: 0.875rem;">
                    <div style="display: flex; justify-content: space-between; border-bottom: 1px solid #f9fafb; padding-bottom: 0.5rem;">
                        <span style="color: #6b7280; font-weight: 500;">Country:</span>
                        <span style="font-weight: 600; color: #374151;">{{ $user->country ?? ($user->profile?->location ?? '-') }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-bottom: 1px solid #f9fafb; padding-bottom: 0.5rem;">
                        <span style="color: #6b7280; font-weight: 500;">City:</span>
                        <span style="font-weight: 600; color: #374151;">{{ $user->city ?? '-' }}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; border-bottom: 1px solid #f9fafb; padding-bottom: 0.5rem;">
                        <span style="color: #6b7280; font-weight: 500;">Postal Code:</span>
                        <span style="font-weight: 600; color: #374151;">{{ $user->postal_code ?? '-' }}</span>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                        <span style="color: #6b7280; font-weight: 500;">Street Address:</span>
                        <span style="font-weight: 600; color: #374151; font-size: 0.8125rem; background: #f9fafb; padding: 0.5rem; border-radius: 0.375rem; border: 1px solid #f3f4f6;">
                            {{ $user->address_line_1 ?? '-' }}
                            @if($user->address_line_2)
                                <br/>{{ $user->address_line_2 }}
                            @endif
                        </span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Row 3: Professional Info, Skills, & Resume --}}
        <div class="card" style="box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); border: none;">
            <div class="card-header" style="border-bottom: 1px solid #f3f4f6; margin-bottom: 1rem; padding-bottom: 0.75rem;">
                <h3 style="display: flex; align-items: center; gap: 0.5rem; font-size: 1.1rem; font-weight: 600; color: #111827; margin: 0;"><i class="fa-solid fa-briefcase" style="color: #4f46e5;"></i> Professional Details</h3>
            </div>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1.5rem;">
                
                {{-- Occupation and CV --}}
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <div style="font-size: 0.875rem;">
                        <strong style="color: #6b7280; font-weight: 500; display: block; margin-bottom: 0.25rem;">Occupation:</strong>
                        <span style="font-size: 0.95rem; font-weight: 600; color: #374151;">{{ $user->occupation ?? '-' }}</span>
                    </div>
                    
                    <div style="margin-top: 0.5rem; font-size: 0.875rem;">
                        <strong style="color: #6b7280; font-weight: 500; display: block; margin-bottom: 0.25rem;">CV / Resume Document:</strong>
                        @if($user->cv_resume)
                            <a href="{{ asset('storage/' . $user->cv_resume) }}" target="_blank" class="btn-primary" style="display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.75rem; padding: 0.4rem 0.75rem; text-decoration: none; border-radius: 0.375rem;">
                                <i class="fa-solid fa-file-pdf"></i> View / Download CV File
                            </a>
                        @else
                            <span style="color: #9ca3af; font-size: 0.8125rem;"><i class="fa-solid fa-circle-info"></i> No CV file uploaded yet</span>
                        @endif
                    </div>
                </div>

                {{-- Skills --}}
                <div>
                    <strong style="color: #6b7280; font-weight: 500; display: block; margin-bottom: 0.5rem; font-size: 0.875rem;">Skills:</strong>
                    <div style="display: flex; gap: 0.35rem; flex-wrap: wrap;">
                        @if($user->skills && is_array($user->skills) && count($user->skills) > 0)
                            @foreach($user->skills as $skill)
                                <span class="badge" style="background: #eef2ff; color: #4f46e5; font-weight: 500; font-size: 0.75rem; padding: 0.25rem 0.6rem; border-radius: 0.375rem;">{{ $skill }}</span>
                            @endforeach
                        @else
                            <span style="color: #9ca3af; font-size: 0.8125rem;"><i class="fa-solid fa-circle-info"></i> No skills listed</span>
                        @endif
                    </div>
                </div>

                {{-- Interests --}}
                <div>
                    <strong style="color: #6b7280; font-weight: 500; display: block; margin-bottom: 0.5rem; font-size: 0.875rem;">Interests:</strong>
                    <div style="display: flex; gap: 0.35rem; flex-wrap: wrap;">
                        @if($user->interests && is_array($user->interests) && count($user->interests) > 0)
                            @foreach($user->interests as $interest)
                                <span class="badge" style="background: #f0fdf4; color: #166534; font-weight: 500; font-size: 0.75rem; padding: 0.25rem 0.6rem; border-radius: 0.375rem;">{{ $interest }}</span>
                            @endforeach
                        @else
                            <span style="color: #9ca3af; font-size: 0.8125rem;"><i class="fa-solid fa-circle-info"></i> No interests listed</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Row 4: Uploaded Verification Document Images (if any) --}}
        @if($user->id_card_image || $user->card_image)
        <div class="card" style="box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); border: none;">
            <div class="card-header" style="border-bottom: 1px solid #f3f4f6; margin-bottom: 1rem; padding-bottom: 0.75rem;">
                <h3 style="display: flex; align-items: center; gap: 0.5rem; font-size: 1.1rem; font-weight: 600; color: #111827; margin: 0;"><i class="fa-solid fa-id-card" style="color: #4f46e5;"></i> Verification Documents</h3>
            </div>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 1.5rem;">
                @if($user->id_card_image)
                    <div style="border: 1px solid #e5e7eb; border-radius: 0.375rem; overflow: hidden; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                        <div style="font-weight: 600; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; padding: 0.5rem; background: #f9fafb; border-bottom: 1px solid #e5e7eb;">ID Card / Passport Image</div>
                        <img src="{{ asset('storage/' . $user->id_card_image) }}" alt="ID Card" style="width: 100%; height: 160px; object-fit: cover;"/>
                        <div style="padding: 0.5rem; font-size: 0.75rem; text-align: center; background: #f9fafb;">
                            <a href="{{ asset('storage/' . $user->id_card_image) }}" target="_blank" style="color: #2563eb; text-decoration: none; font-weight: 500;"><i class="fa-solid fa-eye"></i> View Full Image</a>
                        </div>
                    </div>
                @endif
                
                @if($user->card_image)
                    <div style="border: 1px solid #e5e7eb; border-radius: 0.375rem; overflow: hidden; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
                        <div style="font-weight: 600; font-size: 0.75rem; text-transform: uppercase; color: #6b7280; padding: 0.5rem; background: #f9fafb; border-bottom: 1px solid #e5e7eb;">Additional Verification Card</div>
                        <img src="{{ asset('storage/' . $user->card_image) }}" alt="Verification Card" style="width: 100%; height: 160px; object-fit: cover;"/>
                        <div style="padding: 0.5rem; font-size: 0.75rem; text-align: center; background: #f9fafb;">
                            <a href="{{ asset('storage/' . $user->card_image) }}" target="_blank" style="color: #2563eb; text-decoration: none; font-weight: 500;"><i class="fa-solid fa-eye"></i> View Full Image</a>
                        </div>
                    </div>
                @endif
            </div>
        </div>
        @endif

        {{-- Row 5: Change User Status (Moderation Actions) --}}
        @if($user->role !== 'admin')
        <div class="card" style="box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); border: none;">
            <div class="card-header" style="border-bottom: 1px solid #f3f4f6; margin-bottom: 1rem; padding-bottom: 0.75rem;">
                <h3 style="display: flex; align-items: center; gap: 0.5rem; font-size: 1.1rem; font-weight: 600; color: #111827; margin: 0;"><i class="fa-solid fa-sliders" style="color: #4f46e5;"></i> Account Actions</h3>
            </div>
            
            <div style="background: #f9fafb; padding: 1.25rem; border-radius: 0.5rem; border: 1px solid #f3f4f6;">
                <h4 style="margin: 0 0 0.5rem 0; font-size: 0.875rem; font-weight: 600; color: #374151;">Update Account Status</h4>
                <form method="POST" action="{{ route('users.status', $user) }}" style="display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;">
                    @csrf
                    @method('PATCH')
                    <select name="status" style="padding: 0.45rem 1rem 0.45rem 0.5rem; border: 1px solid #ddd; border-radius: 0.375rem; font-size: 0.875rem; background: #fff; min-width: 150px;">
                        <option value="active"    {{ $user->getRawOriginal('status') === 'active'    ? 'selected' : '' }}>Active</option>
                        <option value="pending"   {{ $user->getRawOriginal('status') === 'pending'   ? 'selected' : '' }}>Pending</option>
                        <option value="suspended" {{ $user->getRawOriginal('status') === 'suspended' ? 'selected' : '' }}>Suspended</option>
                        <option value="blocked"   {{ $user->getRawOriginal('status') === 'blocked'   ? 'selected' : '' }}>Blocked</option>
                        <option value="inactive"  {{ $user->getRawOriginal('status') === 'inactive'  ? 'selected' : '' }}>Inactive</option>
                    </select>
                    <button type="submit" class="btn-primary" style="padding: 0.5rem 1.25rem; font-size: 0.875rem; border: none; font-weight: 500;">Update Status</button>
                </form>
            </div>
        </div>
        @endif

    </div>

    {{-- User Dynamic Items Tab Card --}}
    @if($user->profile)
    <div class="card" style="box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); border: none; margin-bottom: 2rem;">
        {{-- Tabs Navigation Header --}}
        <div style="display: flex; gap: 0.5rem; border-bottom: 2px solid #e5e7eb; margin-bottom: 1.25rem; overflow-x: auto; padding-bottom: 2px;">
            <button onclick="switchTab('posts')" class="tab-btn" id="tab-btn-posts" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; border: none; background: none; cursor: pointer; transition: all 0.2s; white-space: nowrap;">
                Posts ({{ $user->profile->posts->count() }})
            </button>
            <button onclick="switchTab('products')" class="tab-btn" id="tab-btn-products" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; border: none; background: none; cursor: pointer; transition: all 0.2s; white-space: nowrap;">
                Products ({{ $user->profile->products->count() }})
            </button>
            <button onclick="switchTab('services')" class="tab-btn" id="tab-btn-services" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; border: none; background: none; cursor: pointer; transition: all 0.2s; white-space: nowrap;">
                Services ({{ $user->profile->services->count() }})
            </button>
            <button onclick="switchTab('jobs')" class="tab-btn" id="tab-btn-jobs" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; border: none; background: none; cursor: pointer; transition: all 0.2s; white-space: nowrap;">
                Jobs ({{ $user->profile->jobs->count() }})
            </button>
            <button onclick="switchTab('events')" class="tab-btn" id="tab-btn-events" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; border: none; background: none; cursor: pointer; transition: all 0.2s; white-space: nowrap;">
                Events ({{ $user->profile->events->count() }})
            </button>
            <button onclick="switchTab('bookings')" class="tab-btn" id="tab-btn-bookings" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; border: none; background: none; cursor: pointer; transition: all 0.2s; white-space: nowrap;">
                Booked Services ({{ $user->profile->bookings->count() }})
            </button>
            <button onclick="switchTab('job-applications')" class="tab-btn" id="tab-btn-job-applications" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; border: none; background: none; cursor: pointer; transition: all 0.2s; white-space: nowrap;">
                Job Applications ({{ $user->profile->jobApplications->count() }})
            </button>
            <button onclick="switchTab('orders')" class="tab-btn" id="tab-btn-orders" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; border: none; background: none; cursor: pointer; transition: all 0.2s; white-space: nowrap;">
                Product Purchases ({{ $user->profile->orders->count() }})
            </button>
            <button onclick="switchTab('tickets')" class="tab-btn" id="tab-btn-tickets" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.875rem; border: none; background: none; cursor: pointer; transition: all 0.2s; white-space: nowrap;">
                Event Tickets ({{ $user->profile->tickets->count() }})
            </button>
        </div>

        {{-- Tab Content: Posts --}}
        <div id="tab-posts" class="tab-content" style="display: none;">
            @if($user->profile->posts->isNotEmpty())
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    @foreach($user->profile->posts as $post)
                        <div style="border: 1px solid #f3f4f6; padding: 1rem; border-radius: 0.5rem; background: #f9fafb; display: flex; justify-content: space-between; align-items: center; gap: 1rem;">
                            <div>
                                <div style="font-size: 0.75rem; color: #888; margin-bottom: 0.25rem;">Posted on {{ $post->created_at->format('d M Y, h:i A') }}</div>
                                <div style="font-size: 0.875rem; color: #374151; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 600px;">
                                    {{ Str::limit(strip_tags($post->content ?? $post->body), 100) }}
                                </div>
                                <div style="margin-top: 0.35rem; display: flex; gap: 0.5rem; align-items: center;">
                                    @if($post->is_active)
                                        <span class="badge" style="background: #ecfdf5; color: #166534; font-size: 0.7rem; padding: 0.1rem 0.4rem;">Active</span>
                                    @else
                                        <span class="badge" style="background: #fef2f2; color: #b91c1c; font-size: 0.7rem; padding: 0.1rem 0.4rem;">Deactivated</span>
                                    @endif
                                    <span style="font-size: 0.7rem; color: #9ca3af;"><i class="fa-solid fa-thumbs-up"></i> {{ $post->like_count ?? 0 }} &bull; <i class="fa-solid fa-comment"></i> {{ $post->comment_count ?? 0 }}</span>
                                </div>
                            </div>
                            <div>
                                <a href="{{ route('admin.moderation.posts.show', $post) }}" class="btn-edit" style="font-size: 0.75rem; padding: 0.35rem 0.75rem; text-decoration: none;"><i class="fa-solid fa-shield-halved"></i> Moderate</a>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div style="text-align: center; padding: 2rem; color: #9ca3af; font-size: 0.875rem;">
                    <i class="fa-solid fa-message" style="font-size: 2rem; color: #d1d5db; margin-bottom: 0.5rem; display: block;"></i>
                    No posts published by this user.
                </div>
            @endif
        </div>

        {{-- Tab Content: Products --}}
        <div id="tab-products" class="tab-content" style="display: none;">
            @if($user->profile->products->isNotEmpty())
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1rem;">
                    @foreach($user->profile->products as $product)
                        <div style="border: 1px solid #f3f4f6; border-radius: 0.5rem; overflow: hidden; background: #fafafa; display: flex; flex-direction: column;">
                            @if($product->image_url)
                                <img src="{{ asset('storage/' . $product->image_url) }}" alt="" style="width: 100%; height: 120px; object-fit: cover;"/>
                            @else
                                <div style="height: 120px; background: #e5e7eb; display: flex; align-items: center; justify-content: center; color: #9ca3af;"><i class="fa-solid fa-box" style="font-size: 2rem;"></i></div>
                            @endif
                            <div style="padding: 0.75rem; display: flex; flex-direction: column; flex: 1;">
                                <div style="font-weight: 600; font-size: 0.875rem; color: #1f2937; margin-bottom: 0.25rem;">{{ $product->name ?? $product->title }}</div>
                                <div style="font-size: 0.75rem; color: #888; margin-bottom: 0.5rem; text-transform: capitalize;">{{ $product->category?->name ?? '-' }}</div>
                                <div style="margin-top: auto; display: flex; justify-content: space-between; align-items: center;">
                                    <span style="font-weight: 700; color: #111827; font-size: 0.9rem;">£{{ number_format($product->price, 2) }}</span>
                                    @if($product->is_active)
                                        <span class="badge" style="background: #ecfdf5; color: #166534; font-size: 0.65rem; padding: 0.1rem 0.35rem;">Active</span>
                                    @else
                                        <span class="badge" style="background: #fef2f2; color: #b91c1c; font-size: 0.65rem; padding: 0.1rem 0.35rem;">Inactive</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div style="text-align: center; padding: 2rem; color: #9ca3af; font-size: 0.875rem;">
                    <i class="fa-solid fa-box-open" style="font-size: 2rem; color: #d1d5db; margin-bottom: 0.5rem; display: block;"></i>
                    No products listed by this user.
                </div>
            @endif
        </div>

        {{-- Tab Content: Services --}}
        <div id="tab-services" class="tab-content" style="display: none;">
            @if($user->profile->services->isNotEmpty())
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1rem;">
                    @foreach($user->profile->services as $service)
                        <div style="border: 1px solid #f3f4f6; border-radius: 0.5rem; overflow: hidden; background: #fafafa; display: flex; flex-direction: column;">
                            @if($service->image_url)
                                <img src="{{ asset('storage/' . $service->image_url) }}" alt="" style="width: 100%; height: 120px; object-fit: cover;"/>
                            @else
                                <div style="height: 120px; background: #e5e7eb; display: flex; align-items: center; justify-content: center; color: #9ca3af;"><i class="fa-solid fa-server" style="font-size: 2rem;"></i></div>
                            @endif
                            <div style="padding: 0.75rem; display: flex; flex-direction: column; flex: 1;">
                                <div style="font-weight: 600; font-size: 0.875rem; color: #1f2937; margin-bottom: 0.25rem;">{{ $service->title }}</div>
                                <div style="font-size: 0.75rem; color: #888; margin-bottom: 0.5rem; text-transform: capitalize;">{{ $service->service_type }}</div>
                                <div style="margin-top: auto; display: flex; justify-content: space-between; align-items: center;">
                                    <span style="font-weight: 700; color: #111827; font-size: 0.9rem;">£{{ number_format($service->price, 2) }}</span>
                                    @if($service->is_active)
                                        <span class="badge" style="background: #ecfdf5; color: #166534; font-size: 0.65rem; padding: 0.1rem 0.35rem;">Active</span>
                                    @else
                                        <span class="badge" style="background: #fef2f2; color: #b91c1c; font-size: 0.65rem; padding: 0.1rem 0.35rem;">Inactive</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div style="text-align: center; padding: 2rem; color: #9ca3af; font-size: 0.875rem;">
                    <i class="fa-solid fa-handshake-angle" style="font-size: 2rem; color: #d1d5db; margin-bottom: 0.5rem; display: block;"></i>
                    No services offered by this user.
                </div>
            @endif
        </div>

        {{-- Tab Content: Jobs --}}
        <div id="tab-jobs" class="tab-content" style="display: none;">
            @if($user->profile->jobs->isNotEmpty())
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    @foreach($user->profile->jobs as $job)
                        <div style="border: 1px solid #f3f4f6; padding: 1rem; border-radius: 0.5rem; background: #f9fafb; display: flex; justify-content: space-between; align-items: center; gap: 1rem;">
                            <div>
                                <div style="font-weight: 600; font-size: 0.875rem; color: #1f2937; margin-bottom: 0.25rem;">{{ $job->title }}</div>
                                <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.5rem;">{{ $job->company }} &bull; {{ $job->location }} @if($job->is_remote) (Remote) @endif</div>
                                <div style="display: flex; gap: 0.5rem; align-items: center;">
                                    <span class="badge" style="background: #eff6ff; color: #1e40af; font-size: 0.65rem; padding: 0.1rem 0.35rem; text-transform: capitalize;">{{ str_replace('_', ' ', $job->job_type) }}</span>
                                    @if($job->is_active)
                                        <span class="badge" style="background: #ecfdf5; color: #166534; font-size: 0.65rem; padding: 0.1rem 0.35rem;">Active</span>
                                    @else
                                        <span class="badge" style="background: #fef2f2; color: #b91c1c; font-size: 0.65rem; padding: 0.1rem 0.35rem;">Inactive / Closed</span>
                                    @endif
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-weight: 700; color: #111827; font-size: 0.875rem; margin-bottom: 0.25rem;">
                                    @if($job->salary_min && $job->salary_max)
                                        £{{ number_format($job->salary_min, 0) }} - £{{ number_format($job->salary_max, 0) }}
                                    @elseif($job->salary_min)
                                        Min £{{ number_format($job->salary_min, 0) }}
                                    @else
                                        Salary Undisclosed
                                    @endif
                                </div>
                                <div style="font-size: 0.6875rem; color: #9ca3af;">Posted {{ $job->created_at->format('d M Y') }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div style="text-align: center; padding: 2rem; color: #9ca3af; font-size: 0.875rem;">
                    <i class="fa-solid fa-business-time" style="font-size: 2rem; color: #d1d5db; margin-bottom: 0.5rem; display: block;"></i>
                    No job listings posted by this user.
                </div>
            @endif
        </div>

        {{-- Tab Content: Events --}}
        <div id="tab-events" class="tab-content" style="display: none;">
            @if($user->profile->events->isNotEmpty())
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem;">
                    @foreach($user->profile->events as $event)
                        <div style="border: 1px solid #f3f4f6; border-radius: 0.5rem; overflow: hidden; background: #fafafa; display: flex; flex-direction: column;">
                            @if($event->image_url)
                                <img src="{{ asset('storage/' . $event->image_url) }}" alt="" style="width: 100%; height: 130px; object-fit: cover;"/>
                            @else
                                <div style="height: 130px; background: #e5e7eb; display: flex; align-items: center; justify-content: center; color: #9ca3af;"><i class="fa-solid fa-calendar-days" style="font-size: 2.25rem;"></i></div>
                            @endif
                            <div style="padding: 0.85rem; display: flex; flex-direction: column; flex: 1; gap: 0.25rem;">
                                <div style="font-weight: 600; font-size: 0.875rem; color: #1f2937; margin-bottom: 0.15rem;">{{ $event->title }}</div>
                                <div style="font-size: 0.75rem; color: #ef4444; font-weight: 500;"><i class="fa-regular fa-clock"></i> {{ $event->event_date ? $event->event_date->format('d M Y, h:i A') : 'TBD' }}</div>
                                <div style="font-size: 0.75rem; color: #6b7280;"><i class="fa-solid fa-location-dot"></i> {{ $event->city }}, {{ $event->country }}</div>
                                <div style="margin-top: 0.5rem; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #f3f4f6; padding-top: 0.5rem;">
                                    <span style="font-weight: 700; color: #111827; font-size: 0.875rem;">
                                        {{ $event->price == 0 ? 'Free' : '£' . number_format($event->price, 2) }}
                                    </span>
                                    @if($event->is_active)
                                        <span class="badge" style="background: #ecfdf5; color: #166534; font-size: 0.65rem; padding: 0.1rem 0.35rem;">Active</span>
                                    @else
                                        <span class="badge" style="background: #fef2f2; color: #b91c1c; font-size: 0.65rem; padding: 0.1rem 0.35rem;">Inactive</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div style="text-align: center; padding: 2rem; color: #9ca3af; font-size: 0.875rem;">
                    <i class="fa-solid fa-bullhorn" style="font-size: 2rem; color: #d1d5db; margin-bottom: 0.5rem; display: block;"></i>
                    No events organized by this user.
                </div>
            @endif
        </div>

        {{-- Tab Content: Bookings --}}
        <div id="tab-bookings" class="tab-content" style="display: none;">
            @if($user->profile->bookings->isNotEmpty())
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    @foreach($user->profile->bookings as $booking)
                        <div style="border: 1px solid #f3f4f6; padding: 1rem; border-radius: 0.5rem; background: #f9fafb; display: flex; justify-content: space-between; align-items: center; gap: 1rem;">
                            <div>
                                <div style="font-weight: 600; font-size: 0.875rem; color: #1f2937;">
                                    Booking for: {{ $booking->service->title ?? 'Unknown Service' }}
                                </div>
                                <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;">
                                    Date: {{ $booking->booking_date ? $booking->booking_date->format('d M Y') : 'N/A' }} &bull; Time: {{ $booking->booking_time ?? 'N/A' }}
                                </div>
                                <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.15rem;">
                                    Provider: {{ $booking->provider->full_name ?? 'Unknown' }}
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <div style="font-weight: 700; color: #111827; font-size: 0.875rem; margin-bottom: 0.25rem;">
                                    £{{ number_format($booking->total, 2) }}
                                </div>
                                <span class="badge" style="background: 
                                    @if($booking->status === 'completed') #ecfdf5; color: #166534;
                                    @elseif($booking->status === 'pending') #fef3c7; color: #d97706;
                                    @elseif($booking->status === 'cancelled') #fef2f2; color: #b91c1c;
                                    @else #eff6ff; color: #1e40af;
                                    @endif
                                    font-size: 0.7rem; padding: 0.15rem 0.4rem; text-transform: uppercase;">
                                    {{ $booking->status }}
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div style="text-align: center; padding: 2rem; color: #9ca3af; font-size: 0.875rem;">
                    <i class="fa-solid fa-calendar-check" style="font-size: 2rem; color: #d1d5db; margin-bottom: 0.5rem; display: block;"></i>
                    No service bookings found.
                </div>
            @endif
        </div>

        {{-- Tab Content: Job Applications --}}
        <div id="tab-job-applications" class="tab-content" style="display: none;">
            @if($user->profile->jobApplications->isNotEmpty())
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    @foreach($user->profile->jobApplications as $application)
                        <div style="border: 1px solid #f3f4f6; padding: 1rem; border-radius: 0.5rem; background: #f9fafb; display: flex; justify-content: space-between; align-items: center; gap: 1rem;">
                            <div>
                                <div style="font-weight: 600; font-size: 0.875rem; color: #1f2937;">
                                    Job Applied: {{ $application->job->title ?? 'Unknown Job' }}
                                </div>
                                <div style="font-size: 0.75rem; color: #6b7280; margin-top: 0.25rem;">
                                    Company: {{ $application->job->company ?? 'N/A' }} &bull; Location: {{ $application->job->location ?? 'N/A' }}
                                </div>
                                <div style="font-size: 0.75rem; color: #9ca3af; margin-top: 0.25rem;">
                                    Applied on {{ $application->created_at->format('d M Y') }}
                                </div>
                            </div>
                            <div style="text-align: right; display: flex; flex-direction: column; gap: 0.25rem; align-items: flex-end;">
                                <span class="badge" style="background: 
                                    @if($application->status === 'accepted') #ecfdf5; color: #166534;
                                    @elseif($application->status === 'pending') #eff6ff; color: #1e40af;
                                    @elseif($application->status === 'rejected') #fef2f2; color: #b91c1c;
                                    @else #fef3c7; color: #d97706;
                                    @endif
                                    font-size: 0.7rem; padding: 0.15rem 0.4rem; text-transform: uppercase;">
                                    {{ $application->status }}
                                </span>
                                @if($application->is_qualified)
                                    <span style="font-size: 0.65rem; color: #166534; font-weight: 600;"><i class="fa-solid fa-circle-check"></i> Qualified</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div style="text-align: center; padding: 2rem; color: #9ca3af; font-size: 0.875rem;">
                    <i class="fa-solid fa-file-signature" style="font-size: 2rem; color: #d1d5db; margin-bottom: 0.5rem; display: block;"></i>
                    No job applications found.
                </div>
            @endif
        </div>

        {{-- Tab Content: Orders (Product Purchases) --}}
        <div id="tab-orders" class="tab-content" style="display: none;">
            @if($user->profile->orders->isNotEmpty())
                <div style="display: flex; flex-direction: column; gap: 1rem;">
                    @foreach($user->profile->orders as $order)
                        <div style="border: 1px solid #f3f4f6; padding: 1.25rem; border-radius: 0.5rem; background: #f9fafb; display: flex; flex-direction: column; gap: 0.75rem;">
                            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #f1f2f4; padding-bottom: 0.5rem;">
                                <div>
                                    <span style="font-weight: 600; font-size: 0.875rem; color: #1f2937;">Order ID: #{{ $order->id }}</span>
                                    <span style="font-size: 0.75rem; color: #888; margin-left: 0.5rem;">Ordered on {{ $order->created_at->format('d M Y') }}</span>
                                </div>
                                <span class="badge" style="background: 
                                    @if($order->status === 'delivered') #ecfdf5; color: #166534;
                                    @elseif($order->status === 'pending') #fef3c7; color: #d97706;
                                    @elseif($order->status === 'cancelled') #fef2f2; color: #b91c1c;
                                    @else #eff6ff; color: #1e40af;
                                    @endif
                                    font-size: 0.7rem; padding: 0.15rem 0.4rem; text-transform: uppercase;">
                                    {{ $order->status }}
                                </span>
                            </div>
                            
                            {{-- Items Ordered --}}
                            <div style="display: flex; flex-direction: column; gap: 0.5rem; padding-left: 0.5rem;">
                                @foreach($order->items as $item)
                                    <div style="display: flex; justify-content: space-between; font-size: 0.8125rem; color: #4b5563;">
                                        <span>{{ $item->product->title ?? 'Unknown Product' }} (x{{ $item->quantity }})</span>
                                        <span>£{{ number_format($item->price * $item->quantity, 2) }}</span>
                                    </div>
                                @endforeach
                            </div>
                            
                            <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #f1f2f4; padding-top: 0.5rem; margin-top: 0.25rem;">
                                <div style="font-size: 0.75rem; color: #6b7280;">
                                    Seller: {{ $order->seller->full_name ?? 'Unknown' }}
                                </div>
                                <div style="font-weight: 700; color: #111827; font-size: 0.9rem;">
                                    Total Paid: £{{ number_format($order->total, 2) }}
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div style="text-align: center; padding: 2rem; color: #9ca3af; font-size: 0.875rem;">
                    <i class="fa-solid fa-basket-shopping" style="font-size: 2rem; color: #d1d5db; margin-bottom: 0.5rem; display: block;"></i>
                    No product purchases found.
                </div>
            @endif
        </div>

        {{-- Tab Content: Event Tickets --}}
        <div id="tab-tickets" class="tab-content" style="display: none;">
            @if($user->profile->tickets->isNotEmpty())
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem;">
                    @foreach($user->profile->tickets as $ticket)
                        <div style="border: 1px solid #f3f4f6; border-radius: 0.5rem; overflow: hidden; background: #fafafa; display: flex; flex-direction: column;">
                            <div style="padding: 0.85rem; display: flex; flex-direction: column; flex: 1; gap: 0.35rem;">
                                <div style="font-weight: 600; font-size: 0.875rem; color: #1f2937;">{{ $ticket->event->title ?? 'Unknown Event' }}</div>
                                <div style="font-size: 0.75rem; color: #ef4444; font-weight: 500;"><i class="fa-regular fa-clock"></i> {{ $ticket->event->event_date ? $ticket->event->event_date->format('d M Y, h:i A') : 'TBD' }}</div>
                                <div style="font-size: 0.75rem; color: #6b7280; font-family: monospace;">Ticket No: {{ $ticket->ticket_number }}</div>
                                <div style="margin-top: 0.5rem; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #f3f4f6; padding-top: 0.5rem;">
                                    <span style="font-size: 0.75rem; color: #4b5563;">
                                        Sec: {{ $ticket->section->name ?? 'Gen' }} @if($ticket->seat) &bull; Seat: {{ $ticket->seat->seat_number }} @endif
                                    </span>
                                    <span class="badge" style="background: 
                                        @if($ticket->status === 'confirmed') #ecfdf5; color: #166534;
                                        @elseif($ticket->status === 'used') #eff6ff; color: #1e40af;
                                        @else #fef2f2; color: #b91c1c;
                                        @endif
                                        font-size: 0.65rem; padding: 0.1rem 0.35rem; text-transform: uppercase;">
                                        {{ $ticket->status }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div style="text-align: center; padding: 2rem; color: #9ca3af; font-size: 0.875rem;">
                    <i class="fa-solid fa-ticket" style="font-size: 2rem; color: #d1d5db; margin-bottom: 0.5rem; display: block;"></i>
                    No event tickets purchased.
                </div>
            @endif
        </div>
    </div>
    
    {{-- JavaScript for Switching Tabs --}}
    <script>
        function switchTab(tabId) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
            // Show target tab content
            document.getElementById('tab-' + tabId).style.display = 'block';
            
            // Reset all buttons style
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.style.color = '#4b5563';
                btn.style.borderBottom = '2px solid transparent';
                btn.style.background = 'transparent';
            });
            
            // Highlight active button
            const activeBtn = document.getElementById('tab-btn-' + tabId);
            if (activeBtn) {
                activeBtn.style.color = '#4f46e5';
                activeBtn.style.borderBottom = '2px solid #4f46e5';
            }
        }

        // Initial setup
        document.addEventListener('DOMContentLoaded', () => {
            switchTab('posts');
        });
    </script>
    @endif
</div>
@endsection