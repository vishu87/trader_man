@php
    $initials = '';
    $userName = 'User';
    $userRole = '';
    if (Auth::check()) {
        $userName  = Auth::user()->name;
        $nameParts = explode(' ', trim($userName));
        $initials  = strtoupper(substr($nameParts[0], 0, 1));
        if (count($nameParts) > 1) {
            $initials .= strtoupper(substr(end($nameParts), 0, 1));
        }
        $userRole = Auth::user()->role ?? 'Admin';
    }
@endphp

{{-- ── Brand header ───────────────────────────────────────── --}}
<div class="sidebar-brand">
    <div class="brand-logo-mark">v</div>
    <div class="brand-text-block">
        <span class="brand-name">vivo</span>
        <span class="brand-sub">Vision</span>
    </div>
    <button class="sidebar-collapse-btn" id="sidebarCollapseBtn" title="Collapse sidebar">
        <i class="bi bi-chevron-double-left" id="sidebarCollapseIcon"></i>
    </button>
</div>

{{-- ── Navigation ─────────────────────────────────────────── --}}
<nav class="sidebar-nav">
    @include('menus.admin')
</nav>

{{-- ── User footer ─────────────────────────────────────────── --}}
@if(Auth::check())
<div class="sidebar-footer">
    <div class="dropdown dropup w-100">
        <button class="user-menu-trigger" type="button"
                data-bs-toggle="dropdown" data-bs-offset="0,8"
                aria-expanded="false">
            <div class="user-avatar-sidebar">{{ $initials }}</div>
            <div class="user-info-block">
                <span class="user-info-name">{{ $userName }}</span>
                <span class="user-info-role">{{ $userRole }}</span>
            </div>
            <span class="user-menu-chevron">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <path d="M8 9l4-4 4 4"/><path d="M16 15l-4 4-4-4"/>
                </svg>
            </span>
        </button>

        <ul class="dropdown-menu sidebar-user-dropdown">
            <li>
                <div class="sidebar-dropdown-header">
                    <div class="user-avatar-sidebar" style="width:32px;height:32px;font-size:12px;">
                        {{ $initials }}
                    </div>
                    <div>
                        <div class="sidebar-dropdown-name">{{ $userName }}</div>
                        <div class="sidebar-dropdown-email">{{ Auth::user()->email }}</div>
                    </div>
                </div>
            </li>
            <li><hr class="sidebar-dropdown-divider"></li>
            <li>
                <a class="sidebar-dropdown-item" href="{{ url('/user/profile-detail') }}">
                    <i class="bi bi-person"></i> My Profile
                </a>
            </li>
            <li>
                <a class="sidebar-dropdown-item" href="{{ url('/password-update') }}">
                    <i class="bi bi-key"></i> Change Password
                </a>
            </li>
            <li><hr class="sidebar-dropdown-divider"></li>
            <li>
                <a class="sidebar-dropdown-item danger" href="{{ url('/logout') }}">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </li>
        </ul>
    </div>
</div>
@endif
