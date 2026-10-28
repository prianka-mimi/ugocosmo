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

@section('page-title', 'Manage Product Categories')

@section('content')
<div class="card">
    <div class="card-header">
        <h3>Product Category List</h3>
        <form method="GET" action="{{ route('product-categories.index') }}" style="display: flex; gap: 0.5rem; align-items: center; flex: 1; justify-content: flex-end; margin-left: 1rem;">
            <!-- Status Filter -->
            <select name="status" onchange="this.form.submit()" style="padding: 0.4rem 1.8rem 0.4rem 0.6rem; border: 1px solid #ddd; border-radius: 0.375rem; font-size: 0.875rem; background: #fff; appearance: none; -webkit-appearance: none; background-image: none; background-repeat: no-repeat; background-position: right 0.5rem center; background-size: 0.75rem;">
                <option value="">All Status</option>
                <option value="active" {{ ($statusFilter ?? "") === "active" ? "selected" : "" }}>Active</option>
                <option value="inactive" {{ ($statusFilter ?? "") === "inactive" ? "selected" : "" }}>Inactive</option>
            </select>
            
            <!-- Type Filter -->
            <select name="product_type" onchange="this.form.submit()" style="padding: 0.4rem 1.8rem 0.4rem 0.6rem; border: 1px solid #ddd; border-radius: 0.375rem; font-size: 0.875rem; background: #fff; appearance: none; -webkit-appearance: none; background-image: none; background-repeat: no-repeat; background-position: right 0.5rem center; background-size: 0.75rem;">
                <option value="">All Types</option>
                <option value="physical" {{ ($typeFilter ?? "") === "physical" ? "selected" : "" }}>Physical Product</option>
                <option value="digital" {{ ($typeFilter ?? "") === "digital" ? "selected" : "" }}>Digital Product</option>
            </select>

            @if(($statusFilter ?? "") || ($typeFilter ?? ""))
                <a href="{{ route('product-categories.index') }}" class="btn-cancel" style="text-decoration: none; padding: 0.4rem 0.75rem; font-size: 0.875rem;" title="Clear filters"><i class="fa-solid fa-rotate-left"></i></a>
            @endif
            <a href="{{ route('product-categories.create') }}" class="btn-primary" style="padding: 0.4rem 1rem; font-size: 0.875rem;"><i class="fa-solid fa-plus"></i> Add Category</a>
        </form>
    </div>

    <table id="dataTable">
        <thead>
            <tr>
                <th style="width: 50px;">SL</th>
                <th>Category Name</th>
                <th>Product Type</th>
                <th>Status</th>
                <th>Created</th>
                <th style="width: 120px; text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            @foreach($categories as $category)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $category->name }}</td>
                <td>
                    <span class="badge" style="background: {{ $category->product_type === 'physical' ? '#eff6ff' : '#faf5ff' }}; color: {{ $category->product_type === 'physical' ? '#1e40af' : '#6b21a8' }};">
                        {{ $category->product_type === 'physical' ? 'Physical' : 'Digital' }}
                    </span>
                </td>
                <td>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <span class="badge {{ $category->is_active ? 'badge-free' : 'badge-popular' }}" style="background: {{ $category->is_active ? '#f0fdf4' : '#fef2f2' }}; color: {{ $category->is_active ? '#166534' : '#b91c1c' }};">
                            {{ $category->is_active ? 'Active' : 'Inactive' }}
                        </span>
                        <form method="POST" action="{{ route('product-categories.toggleStatus', $category) }}" style="display: inline;">
                            @csrf
                            @method('PATCH')
                            <label class="toggle-switch" style="margin-bottom: 0;">
                                <input type="checkbox" {{ $category->is_active ? 'checked' : '' }} onchange="this.disabled=true; this.form.submit();" />
                                <span class="toggle-slider"></span>
                            </label>
                        </form>
                    </div>
                </td>
                <td>{{ $category->created_at ? $category->created_at->format('M d, Y') : '-' }}</td>
                <td style="text-align: right; white-space: nowrap;">
                    <a href="{{ route('product-categories.show', $category) }}" class="btn-view-icon" title="View"><i class="fa-solid fa-eye"></i></a>
                    <a href="{{ route('product-categories.edit', $category) }}" class="btn-edit" title="Edit"><i class="fa-solid fa-pen"></i></a>
                    <button onclick="confirmDelete('category-delete-{{ $category->id }}')" class="btn-delete" title="Delete"><i class="fa-solid fa-trash"></i></button>
                    <form id="category-delete-{{ $category->id }}" action="{{ route('product-categories.destroy', $category) }}" method="POST" style="display: none;">
                        @csrf
                        @method('DELETE')
                    </form>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    
    @if($categories->isEmpty())
        <p style="text-align: center; color: #999; padding: 2rem; font-size: 0.875rem;">No product categories found.</p>
    @endif
</div>
<script>
    @if(session('success'))
        toastr.success("{{ session('success') }}");
    @endif
    @if(session('error'))
        toastr.error("{{ session('error') }}");
    @endif
</script>
@endsection
