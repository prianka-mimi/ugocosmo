@extends('layouts.dashboard')
<style>
.toggle-switch { position: relative; display: inline-block; width: 44px; height: 24px; }
.toggle-switch input { opacity: 0; width: 0; height: 0; position: absolute; }
.toggle-slider { position: absolute; cursor: pointer; inset: 0; background: #d1d5db; border-radius: 24px; transition: 0.3s; }
.toggle-slider::before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background: white; border-radius: 50%; transition: 0.3s; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
.toggle-switch input:checked + .toggle-slider { background: #22c55e; }
.toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }
.toggle-switch input:disabled + .toggle-slider { opacity: 0.5; cursor: not-allowed; }
</style>

@section('page-title', 'Manage FAQ')

@section('content')
<div class="card">
    <div class="card-header">
        <h3>FAQ List</h3>
        <form method="GET" action="{{ route('faq.index') }}" style="display: flex; gap: 0.5rem; align-items: center; flex: 1; justify-content: flex-end; margin-left: 1rem;">
            <select name="status" onchange="this.form.submit()" style="padding: 0.4rem 1.8rem 0.4rem 0.6rem; border: 1px solid #ddd; border-radius: 0.375rem; font-size: 0.875rem; background: #fff; appearance: none; -webkit-appearance: none; background-image: none; background-repeat: no-repeat; background-position: right 0.5rem center; background-size: 0.75rem;">
                <option value="">All Status</option>
                <option value="active" {{ ($statusFilter ?? "") === "active" ? "selected" : "" }}>Active</option>
                <option value="inactive" {{ ($statusFilter ?? "") === "inactive" ? "selected" : "" }}>Inactive</option>
            </select>
            @if($statusFilter ?? "")
                <a href="{{ route('faq.index') }}" class="btn-cancel" style="text-decoration: none; padding: 0.4rem 0.75rem; font-size: 0.875rem;" title="Clear filters"><i class="fa-solid fa-rotate-left"></i></a>
            @endif
            <a href="{{ route('faq.create') }}" class="btn-primary" style="padding: 0.4rem 1rem; font-size: 0.875rem;"><i class="fa-solid fa-plus"></i> Add FAQ</a>
        </form>
    </div>

    <table id="dataTable">
        <thead>
            <tr>
                <th style="width: 50px;">SL</th>
                <th>Question</th>
                <th>Status</th>
                <th>Created</th>
                <th style="width: 120px; text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($faqs as $faq)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $faq->question }}</td>
                <td style="display: flex; align-items: center; gap: 0.5rem;">
                    <x-status-badge :status="$faq->status" />
                    <form method="POST" action="{{ route('faq.toggleStatus', $faq) }}" style="display: inline;">
                        @csrf
                        @method('PATCH')
                        <label class="toggle-switch" style="margin-bottom: 0;">
                            <input type="checkbox" {{ $faq->status === 'active' ? 'checked' : '' }} onchange="this.disabled=true; this.form.submit();" />
                            <span class="toggle-slider"></span>
                        </label>
                    </form>
                </td>
                <td>{{ $faq->created_at->format('M d, Y') }}</td>
                <td style="text-align: right; white-space: nowrap;">
                    <a href="{{ route('faq.show', $faq) }}" class="btn-view-icon" title="View"><i class="fa-solid fa-eye"></i></a>
                    <a href="{{ route('faq.edit', $faq) }}" class="btn-edit" title="Edit"><i class="fa-solid fa-pen"></i></a>
                    <button onclick="confirmDelete('faq-delete-{{ $faq->id }}')" class="btn-delete" title="Delete"><i class="fa-solid fa-trash"></i></button>
                    <form id="faq-delete-{{ $faq->id }}" action="{{ route('faq.destroy', $faq) }}" method="POST" style="display: none;">
                        @csrf
                        @method('DELETE')
                    </form>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @if($faqs->isEmpty())
        <p style="text-align: center; color: #999; padding: 2rem; font-size: 0.875rem;">No FAQ entries yet.</p>
    @endif
</div>
    <script>
        @if(session('success'))
            toastr.success("{{ session('success') }}");
        @endif
    </script>
@endsection
