<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ \App\Models\AppSetting::get('tab_name', config('app.name', 'UgoCosmo')) }}</title>
    @php
        $favicon = \App\Models\AppSetting::get('favicon');
        $faviconVersion = \App\Models\AppSetting::get('favicon_version', 1);
        $faviconUrl = $favicon && file_exists(public_path('storage/' . $favicon))
            ? asset('storage/' . $favicon) . '?v=' . $faviconVersion
            : asset('img/logo.png');
    @endphp
    <link rel="icon" type="image/x-icon" href="{{ $faviconUrl }}">
    @vite('resources/css/app.css')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/Dropify/0.2.2/css/dropify.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/summernote/0.8.20/summernote-lite.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.17.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        .swal2-custom-popup {
            border-radius: 18px !important;
            padding: 1.5rem !important;
        }
        .swal2-title {
            font-size: 1.15rem !important;
            font-weight: 700 !important;
            color: #111827 !important;
        }
        .swal2-html-container {
            font-size: 0.95rem !important;
            color: #4b5563 !important;
            margin-top: 0.25rem;
            line-height: 1.5;
        }
        .swal2-actions {
            display: flex !important;
            gap: 0.75rem !important;
            justify-content: center !important;
        }
        .swal2-confirm {
            background: var(--primary) !important;
            color: #fff !important;
            border: none !important;
            box-shadow: none !important;
            padding: 0.75rem 1.25rem !important;
            font-weight: 700 !important;
            border-radius: 9999px !important;
        }
        .swal2-deny,
        .swal2-cancel {
            background: #f3f4f6 !important;
            color: #111827 !important;
            border: none !important;
            box-shadow: none !important;
            padding: 0.75rem 1.25rem !important;
            font-weight: 700 !important;
            border-radius: 9999px !important;
        }
        :root {
            --primary: {{ \App\Models\AppSetting::get('primary_color', '#E87450') }};
            --primary-light: #fdf5f0;
        }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            color: #1b1b18;
            min-height: 100vh;
        }
        .dashboard-wrapper {
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: 260px;
            background: #fff;
            border-right: 1px solid #e5e5e5;
            padding: 0;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            overflow-y: auto;
            z-index: 100;
            transition: width 0.3s ease;
        }
        .sidebar.collapsed {
            width: 70px;
        }
        .sidebar.collapsed .sidebar-logo img {
            max-width: 36px;
        }
        .sidebar.collapsed .sidebar-logo-text,
        .sidebar.collapsed .sidebar-section-title,
        .sidebar.collapsed .sidebar-menu > li > a span {
            display: none;
        }
        .sidebar.collapsed .sidebar-menu > li > a {
            justify-content: center;
            padding: 0.75rem;
        }
        .sidebar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.80rem;
            border-bottom: 1px solid #f0f0f0;
        }
        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .sidebar-logo img {
            max-width: 100px;
            transition: max-width 0.3s ease;
        }
        .collapse-btn {
            background: none;
            border: none;
            cursor: pointer;
            color: #555;
            font-size: 1.1rem;
            padding: 0.4rem 0.5rem;
            border-radius: 0.375rem;
            transition: color 0.2s ease, background 0.2s ease;
        }
        .collapse-btn:hover {
            color: var(--primary);
            background: rgba(0,0,0,0.05);
        }
        .sidebar-menu {
            list-style: none;
            padding: 0.75rem 0;
            margin: 0;
        }
        .sidebar-menu li {
            margin: 0;
        }
        .sidebar-menu > li > a {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.65rem 1.25rem;
            color: #555;
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s ease;
            border-left: 3px solid transparent;
        }
        .sidebar-menu > li > a:hover,
        .sidebar-menu > li > a.active {
            background: var(--primary-light);
            color: var(--primary);
            border-left-color: var(--primary);
        }
        .sidebar-menu > li > a i {
            width: 20px;
            text-align: center;
            font-size: 0.9rem;
            flex-shrink: 0;
        }
        .sidebar-menu .submenu {
            list-style: none;
            padding: 0;
            margin: 0;
            display: none;
        }
        .sidebar-menu li.open > .submenu {
            display: block;
        }
        .sidebar-menu .submenu li a {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.65rem 1.75rem;
            color: #666;
            text-decoration: none;
            font-size: 0.85rem;
            border-left: 3px solid transparent;
            transition: all 0.2s ease;
        }
        .sidebar-menu .submenu li a:hover,
        .sidebar-menu .submenu li a.active {
            background: #f8fafc;
            color: var(--primary);
            border-left-color: var(--primary);
        }
        .sidebar-menu .submenu li a i {
            width: 20px;
            text-align: center;
            font-size: 0.8rem;
            flex-shrink: 0;
        }
        .sidebar-menu > li > a .menu-toggle {
            margin-left: auto;
            transition: transform 0.2s ease;
        }
        .sidebar-menu li.open > a .menu-toggle {
            transform: rotate(180deg);
        }
        .sidebar-section-title {
            padding: 0.75rem 1.5rem 0.25rem;
            font-size: 0.6875rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #999;
        }
        .sidebar.collapsed .sidebar-section-title {
            text-align: center;
            padding: 0.75rem 0;
        }
        .main-content {
            flex: 1;
            margin-left: 260px;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            transition: margin-left 0.3s ease;
        }
        .sidebar.collapsed ~ .main-content {
            margin-left: 70px;
        }
        .top-header {
            background: #fff;
            border-bottom: 1px solid #e5e5e5;
            padding: 0.75rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 50;
        }
        .header-left {
            padding-left: 3rem;
        }
        .header-left h3 {
            font-size: 1.125rem;
            font-weight: 600;
            color: #1b1b18;
        }
        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .user-dropdown {
            position: relative;
            display: inline-block;
        }
        .user-dropdown-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem;
            background: #fff;
            border: none;
            border-radius: 2rem;
            cursor: pointer;
            font-size: 0.875rem;
            color: #333;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .user-dropdown-btn:hover {
            color: var(--primary);
        }
        .user-dropdown-btn .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #e5e5e5;
        }
        .user-dropdown-btn .user-avatar-placeholder {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            color: #888;
            border: 2px solid #e5e5e5;
        }
        .user-dropdown-btn i {
            font-size: 0.75rem;
            transition: transform 0.2s ease;
        }
        .user-dropdown.open .user-dropdown-btn i {
            transform: rotate(180deg);
        }
        .user-dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            top: calc(100% + 0.5rem);
            background: #fff;
            border: 1px solid #e5e5e5;
            border-radius: 0.5rem;
            box-shadow: 0 4px 16px rgba(0,0,0,0.08);
            min-width: 180px;
            z-index: 100;
            padding: 0.5rem 0;
        }
        .user-dropdown.open .user-dropdown-content {
            display: block;
        }
        .user-dropdown-content a,
        .user-dropdown-content button {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1rem;
            color: #333;
            text-decoration: none;
            font-size: 0.8125rem;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
            cursor: pointer;
            transition: background 0.15s ease;
        }
        .user-dropdown-content a:hover,
        .user-dropdown-content button:hover {
            background: var(--primary-light);
            color: var(--primary);
        }
        .user-dropdown-content .logout-btn {
            color: #dc2626;
        }
        .user-dropdown-content .logout-btn:hover {
            background: #fef2f2;
            color: #b91c1c;
        }
        .page-content {
            padding: 1.5rem 2rem;
            flex: 1;
        }
        .card {
            background: #fff;
            border-radius: 0.75rem;
            padding: 1.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            border: 1px solid #f0f0f0;
            width: 100%;
            box-sizing: border-box;
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
            gap: 0.75rem;
        }
        .card-header h3 {
            font-size: 1rem;
            font-weight: 600;
            color: #1b1b18;
        }
        .card-header-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        .search-box {
            position: relative;
        }
        .search-box input {
            padding: 0.45rem 0.75rem 0.45rem 2rem;
            border: 1px solid #ddd;
            border-radius: 0.5rem;
            font-size: 0.8125rem;
            font-family: inherit;
            outline: none;
            width: 220px;
            transition: border-color 0.2s ease;
        }
        .search-box input:focus {
            border-color: var(--primary);
        }
        .search-box i {
            position: absolute;
            left: 0.65rem;
            top: 50%;
            transform: translateY(-50%);
            color: #aaa;
            font-size: 0.8rem;
        }
        .btn-primary {
            padding: 0.45rem 1rem;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 0.5rem;
            font-size: 0.8125rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .btn-primary:hover {
            background: color-mix(in srgb, var(--primary) 85%, black);
        }
        .btn-sm {
            padding: 0.3rem 0.6rem;
            font-size: 0.75rem;
            border-radius: 0.375rem;
        }
        .btn-view {
            padding: 0.35rem 0.65rem;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            transition: all 0.2s ease;
        }
        .btn-view:hover {
            background: color-mix(in srgb, var(--primary) 85%, black);
            transform: translateY(-1px);
        }
        .btn-view-icon {
            padding: 0.35rem 0.55rem;
            background: var(--primary);
            color: #fff;
            border: none;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            transition: all 0.2s ease;
        }
        .btn-view-icon:hover {
            background: color-mix(in srgb, var(--primary) 85%, black);
        }
        /* Toggle Switch */
        .toggle-switch { position: relative; display: inline-block; width: 44px; height: 24px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; cursor: pointer; inset: 0; background: #ccc; border-radius: 24px; transition: 0.3s; }
        .toggle-slider::before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background: white; border-radius: 50%; transition: 0.3s; }
        .toggle-switch input:checked + .toggle-slider { background: #22c55e; }
        .toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }

        .btn-edit {
            padding: 0.35rem 0.65rem;
            background: #0d9488;
            color: #fff;
            border: none;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            transition: all 0.2s ease;
        }
        .btn-edit:hover {
            background: #0f766e;
            transform: translateY(-1px);
        }
        .btn-approve {
            padding: 0.35rem 0.8rem;
            background: linear-gradient(135deg, #4ade80, #22c55e);
            color: #fff;
            border: none;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(34,197,94,0.3);
        }
        .btn-approve:hover {
            box-shadow: 0 4px 12px rgba(34,197,94,0.4);
            transform: translateY(-1px);
        }
        .btn-reject {
            padding: 0.35rem 0.8rem;
            background: #fff;
            color: #ef4444;
            border: 1px solid #ef4444;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-reject:hover {
            background: #fef2f2;
            border-color: #dc2626;
            color: #dc2626;
        }
        .btn-delete {
            padding: 0.35rem 0.65rem;
            background: #ef4444;
            color: #fff;
            border: none;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
            transition: all 0.2s ease;
        }
        .btn-delete:hover {
            background: #dc2626;
            transform: translateY(-1px);
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        table th,
        table td {
            padding: 0.75rem 0.5rem;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
            font-size: 0.8125rem;
        }
        table th {
            font-weight: 600;
            color: #555;
            text-transform: uppercase;
            font-size: 0.6875rem;
            letter-spacing: 0.05em;
        }
        table td {
            color: #333;
        }
        table tbody tr:hover {
            background: #fafafa;
        }
        .badge {
            display: inline-block;
            padding: 0.2rem 0.6rem;
            border-radius: 9999px;
            font-size: 0.6875rem;
            font-weight: 600;
            letter-spacing: 0.02em;
        }
        .badge-free {
            background: #f0fdf4;
            color: #166534;
        }
        .badge-popular {
            background: #fef2f2;
            color: #b91c1c;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .form-group label {
            display: block;
            font-size: 0.8125rem;
            font-weight: 600;
            color: #3d3d3a;
            margin-bottom: 0.35rem;
        }
        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="password"],
        .form-group input[type="number"],
        .form-group input[type="color"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.6rem 0.9rem;
            border: 1px solid #ddd;
            border-radius: 0.5rem;
            font-size: 0.9375rem;
            font-family: inherit;
            outline: none;
            transition: border-color 0.2s ease;
            box-sizing: border-box;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(232, 116, 80, 0.1);
        }
        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.show {
            display: flex;
        }
        .modal {
            background: #fff;
            border-radius: 0.75rem;
            padding: 2rem;
            max-width: 400px;
            width: 90%;
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
        }
        .modal h3 {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            color: #1b1b18;
        }
        .modal p {
            font-size: 0.875rem;
            color: #666;
            margin-bottom: 1.5rem;
            line-height: 1.5;
        }
        .modal-actions {
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
        }
        .btn-cancel {
            padding: 0.5rem 1rem;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 0.5rem;
            font-size: 0.8125rem;
            cursor: pointer;
            color: #555;
            transition: all 0.2s ease;
        }
        .btn-cancel:hover {
            background: #f5f5f5;
        }
        .btn-danger {
            padding: 0.5rem 1rem;
            background: #dc2626;
            color: #fff;
            border: none;
            border-radius: 0.5rem;
            font-size: 0.8125rem;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .btn-danger:hover {
            background: #b91c1c;
        }
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.25rem;
            font-size: 0.8125rem;
        }
        .alert-success {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
        }
        .plan-card {
            border: 1px solid #f0f0f0;
            border-radius: 0.75rem;
            padding: 2rem 1.5rem;
            text-align: center;
            transition: all 0.2s ease;
            position: relative;
        }
        .plan-card:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(232, 116, 80, 0.1);
        }
        .plan-card .popular-badge {
            position: absolute;
            top: -0.75rem;
            left: 50%;
            transform: translateX(-50%);
            background: var(--primary);
            color: #fff;
            font-size: 0.6875rem;
            font-weight: 700;
            padding: 0.2rem 0.75rem;
            border-radius: 9999px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .plan-name {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1b1b18;
            margin-bottom: 0.5rem;
        }
        .plan-price {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 0.25rem;
        }
        .plan-price span {
            font-size: 0.875rem;
            color: #888;
            font-weight: 400;
        }
        .plan-desc {
            font-size: 0.8125rem;
            color: #888;
            margin-bottom: 1.25rem;
        }
        .plan-features {
            list-style: none;
            text-align: left;
            margin-bottom: 1.5rem;
        }
        .plan-features li {
            padding: 0.4rem 0;
            font-size: 0.8125rem;
            color: #555;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .plan-features li i {
            color: #22c55e;
            font-size: 0.75rem;
        }
        .checkbox-group input[type="checkbox"] {
            accent-color: var(--primary);
        }
        .checkbox-group label {
            font-size: 0.8125rem;
            color: #555;
        }
        .color-preview {
            width: 100%;
            height: 40px;
            border-radius: 0.5rem;
            border: 1px solid #ddd;
            margin-top: 0.5rem;
        }
        /* DataTables custom pagination */
        .dataTables_wrapper .dataTables_paginate {
            margin-top: 1rem;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button {
            padding: 0.4rem 0.75rem;
            border-radius: 0.375rem;
            border: 1px solid #ddd;
            background: #fff;
            color: #555;
            cursor: pointer;
            margin: 0 0.15rem;
            font-size: 0.8125rem;
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
            background: var(--primary-light);
            color: var(--primary);
            border-color: var(--primary);
        }
        .dataTables_wrapper .dataTables_paginate .paginate_button.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter {
            margin-bottom: 1rem;
        }
        .dataTables_wrapper .dataTables_length select {
            padding: 0.35rem 0.5rem;
            border: 1px solid #ddd;
            border-radius: 0.375rem;
            font-size: 0.8125rem;
        }
        .dataTables_wrapper .dataTables_filter input {
            padding: 0.4rem 0.65rem;
            border: 1px solid #ddd;
            border-radius: 0.5rem;
            font-size: 0.8125rem;
            outline: none;
            margin-left: 0.5rem;
        }
        .dataTables_wrapper .dataTables_filter input:focus {
            border-color: var(--primary);
        }
        .dataTables_wrapper .dataTables_info {
            padding: 0.6rem 0;
            font-size: 0.8125rem;
            color: #888;
        }
        .note-editor.has-content .note-placeholder {
            display: none !important;
        }
        .dataTables_wrapper table.dataTable tbody tr {
            background: #fff;
        }
        .dataTables_wrapper table.dataTable thead th {
            border-bottom: 2px solid #e5e5e5;
        }
    </style>
</head>
<body>
    <div class="dashboard-wrapper">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-logo">
                    <a href="{{ url('/') }}">
                        <img src="{{ \App\Models\AppSetting::get('logo') ? asset('storage/' . \App\Models\AppSetting::get('logo')) : asset('img/logo.png') }}" alt="Logo"/>
                    </a>
                </div>
            </div>
            @php
                $user = auth()->user();
                $can = function($perm) use ($user) {
                    if (!$user) return false;
                    if ($user->role === 'admin') return true;
                    try {
                        if ($user->hasRole('admin')) return true;
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('sidebar: hasRole check failed: ' . $e->getMessage());
                    }
                    try {
                        return $user->can($perm);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning("sidebar: can('{$perm}') failed: " . $e->getMessage());
                        return false;
                    }
                };
            @endphp
            <ul class="sidebar-menu">
                <li>
                    <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
                        <i class="fa-solid fa-table-cells"></i> <span>Dashboard</span>
                    </a>
                </li>
                @if($can('users.view'))
                <li>
                    <a href="{{ route('admin.users') }}" class="{{ request()->routeIs('admin.users*') ? 'active' : '' }}">
                        <i class="fa-solid fa-users"></i> <span>Users</span>
                    </a>
                </li>
                @endif
                @if($can('verifications.view'))
                <li>
                    <a href="{{ route('admin.verifications.index') }}" class="{{ request()->routeIs('admin.verifications.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-user-shield"></i> <span>Verifications</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('admin.bank-accounts.index') }}" class="{{ request()->routeIs('admin.bank-accounts.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-building-columns"></i> <span>Bank Verifications</span>
                    </a>
                </li>
                @endif
                @if($can('faq.manage'))
                <li>
                    <a href="{{ route('faq.index') }}" class="{{ request()->routeIs('faq.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-circle-question"></i> <span>FAQ</span>
                    </a>
                </li>
                @endif
                @if($can('settings.manage'))
                <li>
                    <a href="{{ route('service-categories.index') }}" class="{{ request()->routeIs('service-categories.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-list"></i> <span>Service Categories</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('product-categories.index') }}" class="{{ request()->routeIs('product-categories.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-tags"></i> <span>Product Categories</span>
                    </a>
                </li>
                @endif
                @if($can('pages.manage'))
                <li>
                    <a href="{{ route('page.index') }}" class="{{ request()->routeIs('page.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-file-lines"></i> <span>Pages</span>
                    </a>
                </li>
                @endif
                @if($can('subscriptions.manage'))
                <li>
                    <a href="{{ route('subscription.index') }}" class="{{ request()->routeIs('subscription.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-crown"></i> <span>Subscription</span>
                    </a>
                </li>
                @endif
                @if($can('posts.moderate') || $can('products.moderate') || $can('services.moderate') || $can('events.moderate') || $can('jobs.moderate'))
                <li>
                    <a href="{{ route('admin.moderation.posts') }}" class="{{ request()->routeIs('admin.moderation.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-flag"></i> <span>Moderation</span>
                    </a>
                </li>
                @endif
                @if($can('orders.view') || $can('orders.manage') || $can('dashboard.view'))
                <li>
                    <a href="{{ route('admin.orders.index') }}" class="{{ request()->routeIs('admin.orders.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-receipt"></i> <span>Orders</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('admin.wallet.index') }}" class="{{ request()->routeIs('admin.wallet.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-wallet"></i> <span>Platform Wallet (2.5%)</span>
                    </a>
                </li>
                @endif
                @if($can('disputes.view') || $can('disputes.manage'))
                <li>
                    <a href="{{ route('admin.disputes.index') }}" class="{{ request()->routeIs('admin.disputes.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-scale-balanced"></i> <span>Disputes</span>
                    </a>
                </li>
                @endif
                @if($can('aliexpress.view'))
                <li>
                    <a href="{{ route('admin.aliexpress.index') }}" class="{{ request()->routeIs('admin.aliexpress.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-box-open"></i> <span>AliExpress</span>
                    </a>
                </li>
                @endif
                @if($can('conversations.view') || $can('conversations.reply'))
                <li>
                    <a href="{{ route('admin.conversations.index') }}" class="{{ request()->routeIs('admin.conversations.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-envelope"></i> <span>Contact Messages</span>
                    </a>
                </li>
                @endif
            </ul>
            <div class="sidebar-section-title">Administration</div>
            <ul class="sidebar-menu">
                @if($can('dashboard.view'))
                <li>
                    <a href="{{ route('admin.dashboard') }}" class="{{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                        <i class="fa-solid fa-chart-pie"></i> <span>Analytics</span>
                    </a>
                </li>
                @endif
                @if($can('roles.view') || $can('permissions.manage'))
                <li class="{{ request()->routeIs('admin.roles.*') ? 'open' : '' }}">
                    <a href="#" onclick="toggleSubmenu(event, 'rolesPermissionsSubmenu')" class="{{ request()->routeIs('admin.roles.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-shield-halved"></i> <span>Roles &amp; Permissions</span>
                        <i class="fa-solid fa-chevron-down menu-toggle"></i>
                    </a>
                    <ul class="submenu" id="rolesPermissionsSubmenu" style="display: {{ request()->routeIs('admin.roles.*') ? 'block' : 'none' }};">
                        @if($can('roles.view'))
                        <li>
                            <a href="{{ route('admin.roles.index') }}" class="{{ request()->routeIs('admin.roles.index') ? 'active' : '' }}">
                                <i class="fa-solid fa-users-gear"></i> <span>Roles</span>
                            </a>
                        </li>
                        @endif
                        @if($can('permissions.manage'))
                        <li>
                            <a href="{{ route('admin.roles.index', ['section' => 'permissions']) }}" class="{{ request()->routeIs('admin.roles.index') && request('section') === 'permissions' ? 'active' : '' }}">
                                <i class="fa-solid fa-key"></i> <span>Permissions</span>
                            </a>
                        </li>
                        @endif
                    </ul>
                </li>
                @endif
                @if($can('audit.view'))
                <li>
                    <a href="{{ route('admin.audit') }}" class="{{ request()->routeIs('admin.audit') ? 'active' : '' }}">
                        <i class="fa-solid fa-clock-rotate-left"></i> <span>Audit Logs</span>
                    </a>
                </li>
                @endif
                @if($can('settings.manage'))
                <li>
                    <a href="{{ route('settings.index') }}" class="{{ request()->routeIs('settings.index') ? 'active' : '' }}">
                        <i class="fa-solid fa-gear"></i> <span>App Settings</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('settings.bank.index') }}" class="{{ request()->routeIs('settings.bank.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-building-columns"></i> <span>Bank Settings</span>
                    </a>
                </li>
                <li>
                    <a href="{{ route('admin.livestream-gifting.index') }}" class="{{ request()->routeIs('admin.livestream-gifting.*') ? 'active' : '' }}">
                        <i class="fa-solid fa-gift"></i> <span>Livestream Gifting</span>
                    </a>
                </li>
                @endif
            </ul>
        </aside>
        <div class="main-content" id="mainContent">
            <header class="top-header" style="position: relative;">
                <button class="collapse-btn" onclick="toggleSidebar()" title="Toggle sidebar" style="position: absolute; top: 1rem; left: 1rem; z-index: 10;">
                    <i class="fa-solid fa-bars" id="collapseIcon"></i>
                </button>
                <div class="header-left">
                    <h3>@yield('page-title', 'Dashboard')</h3>
                </div>
                <div class="header-right">
                    <div class="user-dropdown" id="userDropdown">
                        <button class="user-dropdown-btn" onclick="toggleDropdown()">
                            @if(auth()->user()->avatar)
                                <img src="{{ asset('storage/' . auth()->user()->avatar) }}" alt="Avatar" class="user-avatar"/>
                            @else
                                <div class="user-avatar-placeholder">
                                    <i class="fa-solid fa-hand-holding-dollar"></i>
                                </div>
                            @endif
                            <span>{{ auth()->user()->name }}</span>
                            <i class="fa-solid fa-chevron-down"></i>
                        </button>
                        <div class="user-dropdown-content">
                            <a href="{{ route('profile') }}">
                                <i class="fa-solid fa-user-pen"></i> My Profile
                            </a>
                            <button onclick="showLogoutModal()" class="logout-btn">
                                <i class="fa-solid fa-right-from-bracket"></i> Log Out
                            </button>
                        </div>
                    </div>
                </div>
            </header>
            <main class="page-content">
                @yield('content')
            </main>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal">
            <h3>Confirm Delete</h3>
            <p>Are you sure you want to delete the data?</p>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="hideDeleteModal()">Cancel</button>
                <button class="btn-danger" id="confirmDeleteBtn">Delete</button>
            </div>
        </div>
    </div>

    <!-- Logout Confirmation Modal -->
    <div class="modal-overlay" id="logoutModal">
        <div class="modal">
            <h3>Confirm Logout</h3>
            <p>Are you sure you want to log out?</p>
            <div class="modal-actions">
                <button class="btn-cancel" onclick="hideLogoutModal()">Cancel</button>
                <form method="POST" action="{{ route('logout') }}" style="display: inline;" id="logoutForm">
                    @csrf
                    <input type="hidden" name="redirect_to" value="/"/>
                    <button type="submit" class="btn-danger">Log Out</button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Dropify/0.2.2/js/dropify.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/summernote/0.8.20/summernote-lite.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.js"></script>
    <script>
        toastr.options = {
            "closeButton": true,
            "progressBar": true,
            "positionClass": "toast-top-right",
            "timeOut": "4000",
        };
        // Sidebar collapse
        function toggleSidebar() {
            var sidebar = document.getElementById('sidebar');
            var icon = document.getElementById('collapseIcon');
            sidebar.classList.toggle('collapsed');
            if (sidebar.classList.contains('collapsed')) {
                icon.classList.remove('fa-bars');
                icon.classList.add('fa-chevron-right');
            } else {
                icon.classList.remove('fa-chevron-right');
                icon.classList.add('fa-bars');
            }
        }

        // User dropdown
        function toggleDropdown() {
            document.getElementById('userDropdown').classList.toggle('open');
        }

        function toggleSubmenu(e, id) {
            e.preventDefault();
            var el = document.getElementById(id);
            var li = e.currentTarget.closest('li');
            if (el) {
                var isHidden = el.style.display === 'none' || el.style.display === '';
                el.style.display = isHidden ? 'block' : 'none';
                if (li) li.classList.toggle('open', isHidden);
            }
        }

        // Unified delete modal
        var deleteFormTarget = null;
        function confirmDelete(formId) {
            deleteFormTarget = formId;
            document.getElementById('deleteModal').classList.add('show');
        }
        function hideDeleteModal() {
            document.getElementById('deleteModal').classList.remove('show');
            deleteFormTarget = null;
        }
        document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
            if (deleteFormTarget) {
                document.getElementById(deleteFormTarget).submit();
            }
        });

        // Logout modal
        function showLogoutModal() {
            document.getElementById('logoutModal').classList.add('show');
        }
        function hideLogoutModal() {
            document.getElementById('logoutModal').classList.remove('show');
        }

        // Close modals on backdrop click
        document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) {
                    overlay.classList.remove('show');
                    deleteFormTarget = null;
                }
            });
        });
        document.addEventListener('click', function(e) {
            var dd = document.getElementById('userDropdown');
            if (dd && !dd.contains(e.target)) {
                dd.classList.remove('open');
            }
        });

        // SweetAlert2 confirmation for marked forms
        function initSwalConfirmForms() {
            document.querySelectorAll('form.swal-confirm').forEach(function(form) {
                if (form.dataset.swalBound === '1') {
                    return;
                }
                form.dataset.swalBound = '1';

                form.addEventListener('submit', function(event) {
                    event.preventDefault();
                    var title = form.dataset.swalTitle || 'Confirm action';
                    var text = form.dataset.swalText || 'Are you sure you want to continue?';
                    var confirmButtonText = form.dataset.swalConfirmText || 'Yes, continue';
                    var icon = form.dataset.swalIcon || 'warning';

                    if (typeof Swal === 'undefined') {
                        if (window.confirm(text)) {
                            form.submit();
                        }
                        return;
                    }

                    Swal.fire({
                        title: title,
                        text: text,
                        icon: icon,
                        showCancelButton: true,
                        confirmButtonText: confirmButtonText,
                        cancelButtonText: 'Cancel',
                        reverseButtons: true,
                        buttonsStyling: false,
                        customClass: {
                            popup: 'swal2-custom-popup',
                            confirmButton: 'swal2-confirm',
                            cancelButton: 'swal2-cancel'
                        }
                    }).then(function(result) {
                        if (result.isConfirmed) {
                            form.submit();
                        }
                    });
                });
            });
        }

        function confirmRejectVerification() {
            if (typeof Swal === 'undefined') {
                var reason = window.prompt('Please enter a rejection reason:');
                if (reason) {
                    document.getElementById('rejectReasonInput').value = reason;
                    document.getElementById('rejectVerificationForm').submit();
                }
                return;
            }

            Swal.fire({
                title: 'Reject Verification',
                input: 'textarea',
                inputLabel: 'Rejection Reason',
                inputPlaceholder: 'e.g. Documents were unclear or expired…',
                inputAttributes: {
                    'aria-label': 'Rejection reason',
                    'maxlength': 800
                },
                inputValidator: function(value) {
                    return !value && 'Please enter a rejection reason';
                },
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Reject',
                cancelButtonText: 'Cancel',
                reverseButtons: true,
                buttonsStyling: false,
                customClass: {
                    popup: 'swal2-custom-popup',
                    confirmButton: 'swal2-confirm',
                    cancelButton: 'swal2-cancel'
                }
            }).then(function(result) {
                if (result.isConfirmed) {
                    document.getElementById('rejectReasonInput').value = result.value;
                    document.getElementById('rejectVerificationForm').submit();
                }
            });
        }

        initSwalConfirmForms();

        // Initialize DataTables
        $(document).ready(function() {
            if ($('#dataTable').length) {
                var hideFilter = $('#dataTable').data('hide-filter');
                $('#dataTable').DataTable({
                    pageLength: 20,
                    lengthMenu: [10, 20, 50, 100],
                    ordering: false,
                    searching: !hideFilter,
                    language: {
                        search: "",
                        searchPlaceholder: "Search...",
                        lengthMenu: "Show _MENU_ entries",
                        info: "Showing _START_ to _END_ of _TOTAL_",
                        paginate: {
                            previous: "<i class='fa-solid fa-chevron-left'></i>",
                            next: "<i class='fa-solid fa-chevron-right'></i>"
                        }
                    },
                    dom: hideFilter ? 'rt<"bottom"ip><"clear">' : '<"top"f>rt<"bottom"ip><"clear">',
                });
            }
            if ($('.dropify').length) {
                $('.dropify').dropify();
            }
            if ($('.rich-editor').length) {
                $('.rich-editor').summernote({
                    height: 200,
                    toolbar: [
                        ['style', ['style']],
                        ['font', ['bold', 'italic', 'underline', 'strikethrough', 'clear']],
                        ['fontsize', ['fontsize']],
                        ['color', ['color']],
                        ['para', ['ul', 'ol', 'paragraph']],
                        ['insert', ['link', 'video']],
                        ['view', ['fullscreen', 'codeview', 'help']]
                    ],
                    callbacks: {
                        onInit: function() {
                            var editor = $(this);
                            if (!editor.summernote('isEmpty')) {
                                editor.next('.note-editor').addClass('has-content');
                            }
                        },
                        onChange: function(contents) {
                            var editor = $(this);
                            var noteEditor = editor.next('.note-editor');
                            if (editor.summernote('isEmpty')) {
                                noteEditor.removeClass('has-content');
                            } else {
                                noteEditor.addClass('has-content');
                            }
                        },
                        onBlur: function() {
                            var noteEditor = $(this).next('.note-editor');
                            if (!noteEditor.hasClass('has-content')) {
                                noteEditor.find('.note-placeholder').show();
                            }
                        },
                        onFocus: function() {
                            $(this).next('.note-editor').find('.note-placeholder').hide();
                        },
                        onImageUpload: function(files) {
                            toastr.warning('Image upload is not supported. Use a URL to insert images.', 'Image Upload Not Supported');
                        },
                        initFilter: function() {}
                    },
                });
            }
        });

        // Approve / Reject via AJAX - instant submit, no confirmation
        document.querySelectorAll('.action-form').forEach(function(form) {
            var btn = form.querySelector('button');
            var userId = form.dataset.userId;
            var action = form.dataset.action;

            btn.addEventListener('click', function() {
                var clickedBtn = this;
                var otherForm = form.parentElement.querySelector('.action-form[data-action="' + (action === 'approve' ? 'reject' : 'approve') + '"]');
                var otherBtn = otherForm ? otherForm.querySelector('button') : null;

                clickedBtn.disabled = true;
                if (otherBtn) otherBtn.disabled = true;
                clickedBtn.textContent = action === 'approve' ? 'Approving...' : 'Rejecting...';

                fetch(form.action, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        'Accept': 'application/json',
                    },
                    body: new FormData(form),
                }).then(function(r) {
                    if (r.ok) {
                        var pair = document.querySelectorAll('.action-form[data-user-id="' + userId + '"]');
                        pair.forEach(function(f) { f.style.display = 'none'; });
                    } else {
                        clickedBtn.disabled = false;
                        clickedBtn.textContent = action === 'approve' ? 'Approve' : 'Reject';
                        if (otherBtn) otherBtn.disabled = false;
                    }
                }).catch(function() {
                    clickedBtn.disabled = false;
                    clickedBtn.textContent = action === 'approve' ? 'Approve' : 'Reject';
                    if (otherBtn) otherBtn.disabled = false;
                });
            });
        });

    </script>
    @stack('scripts')
</body>
</html>

