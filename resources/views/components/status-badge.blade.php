@props(['status' => 'inactive', 'type' => 'status'])

@php
    $status = $status ?? 'inactive';

    $verifiedConfig = [
        'verified'   => ['bg' => '#dbeafe', 'text' => '#1d4ed8', 'icon' => 'fa-circle-check', 'iconColor' => '#3b82f6'],
        'unverified' => ['bg' => '#fef2f2', 'text' => '#991b1b', 'icon' => 'fa-circle-xmark', 'iconColor' => '#ef4444'],
    ];

    $statusConfig = [
        'active'     => ['bg' => '#dcfce7', 'text' => '#166534', 'icon' => 'fa-circle-check', 'iconColor' => '#22c55e'],
        'pending'    => ['bg' => '#fef9c3', 'text' => '#854d0e', 'icon' => 'fa-clock', 'iconColor' => '#eab308'],
        'suspended'  => ['bg' => '#fef3c7', 'text' => '#92400e', 'icon' => 'fa-pause', 'iconColor' => '#f59e0b'],
        'blocked'    => ['bg' => '#fee2e2', 'text' => '#991b1b', 'icon' => 'fa-ban', 'iconColor' => '#ef4444'],
        'inactive'   => ['bg' => '#fee2e2', 'text' => '#991b1b', 'icon' => 'fa-circle-xmark', 'iconColor' => '#ef4444'],
    ];

    $config = $type === 'verified' ? $verifiedConfig : $statusConfig;
    $badge  = $config[$status] ?? $config['inactive'] ?? ['bg' => '#fee2e2', 'text' => '#991b1b', 'icon' => 'fa-circle-xmark', 'iconColor' => '#ef4444'];
@endphp

<span style="display:inline-flex;align-items:center;gap:4px;background:{{ $badge['bg'] }};color:{{ $badge['text'] }};padding:2px 10px;border-radius:9999px;font-size:0.75rem;font-weight:600;">
    <i class="fa-solid {{ $badge['icon'] }}" style="color:{{ $badge['iconColor'] }};"></i> {{ ucfirst($status) }}
</span>
