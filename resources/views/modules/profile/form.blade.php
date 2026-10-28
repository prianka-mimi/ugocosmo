@extends('layouts.dashboard')

@section('page-title', 'My Profile')

@section('content')
<div class="card">
    <div class="card-header">
        <h3>Edit Profile</h3>
    </div>
    <form method="POST" action="{{ route('profile.update') }}" enctype="multipart/form-data">
        @csrf
        <div style="text-align: center; margin-bottom: 1.5rem;">
            <div style="width: 100px; height: 100px; border-radius: 50%; overflow: hidden; margin: 0 auto 0.75rem; background: #f0f0f0; display: flex; align-items: center; justify-content: center;">
                @if($user->avatar)
                    <img src="{{ asset('storage/' . $user->avatar) }}" alt="Avatar" style="width: 100%; height: 100%; object-fit: cover;"/>
                @else
                    <i class="fa-solid fa-user" style="font-size: 2rem; color: #ccc;"></i>
                @endif
            </div>
            <input type="file" name="avatar" class="dropify" data-height="100" data-max-file-size="2M" data-allowed-file-extensions="jpg jpeg png gif" data-default-file="{{ $user->avatar ? asset('storage/' . $user->avatar) : '' }}"/>
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
            <div class="form-group">
                <label for="name">Full Name</label>
                <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" required />
            </div>
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="{{ old('username', $user->username) }}" required />
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" required />
            </div>
            <div class="form-group">
                <label for="mobile_number">Mobile Number</label>
                <input type="text" id="mobile_number" name="mobile_number" value="{{ old('mobile_number', $user->mobile_number) }}" />
            </div>
        </div>
        <div class="form-group">
            <label for="address_line_1">Address Line 1</label>
            <input type="text" id="address_line_1" name="address_line_1" value="{{ old('address_line_1', $user->address_line_1) }}" />
        </div>
        <div class="form-group">
            <label for="address_line_2">Address Line 2</label>
            <input type="text" id="address_line_2" name="address_line_2" value="{{ old('address_line_2', $user->address_line_2) }}" />
        </div>
        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
            <div class="form-group">
                <label for="city">City</label>
                <input type="text" id="city" name="city" value="{{ old('city', $user->city) }}" />
            </div>
            <div class="form-group">
                <label for="postal_code">Postal Code</label>
                <input type="text" id="postal_code" name="postal_code" value="{{ old('postal_code', $user->postal_code) }}" />
            </div>
            <div class="form-group">
                <label for="country">Country</label>
                <input type="text" id="country" name="country" value="{{ old('country', $user->country) }}" />
            </div>
        </div>
        <div style="text-align: right; margin-top: 1rem;">
            <button type="submit" class="btn-primary" style="padding: 0.65rem 1.5rem;">Save Changes</button>
        </div>
    </form>
    <script>
        @if(session('success'))
            toastr.success("{{ session('success') }}");
        @endif
    </script>
</div>
@endsection