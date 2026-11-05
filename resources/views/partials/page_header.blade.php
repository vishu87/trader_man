@if(Auth::check())
@php
    $pageTitle    = $pageTitle    ?? 'Dashboard';
    $pageSubtitle = $pageSubtitle ?? 'Compliance overview across all registered entities';
    $pageFY       = $pageFY       ?? 'FY 2025-26';
@endphp

<header class="topbar">

    {{-- Mobile sidebar toggle --}}
    <button class="topbar-icon-btn mobile-toggle d-md-none" id="mobileMenuToggle" type="button" aria-label="Open menu">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
             stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <line x1="3" y1="6" x2="21" y2="6"/>
            <line x1="3" y1="12" x2="21" y2="12"/>
            <line x1="3" y1="18" x2="21" y2="18"/>
        </svg>
    </button>

    {{-- Page title block --}}
    <div class="topbar-title-block">
        <div class="topbar-title-row">
            <h1 class="topbar-title">{{ $pageTitle }}</h1>
            <span class="topbar-fy-pill">{{ $pageFY }}</span>
        </div>
        <span class="topbar-subtitle">{{ $pageSubtitle }}</span>
    </div>

    <div class="topbar-spacer"></div>

    {{-- Search --}}
    <div class="topbar-search">
        <span class="search-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="7"/>
                <path d="M21 21l-4.3-4.3"/>
            </svg>
        </span>
        <input type="search" id="globalSearch"
               placeholder="Search companies, CIN, DIN…"
               autocomplete="off">
    </div>

    {{-- Notifications --}}
    <div class="notif-badge">
        <button class="topbar-icon-btn" type="button" aria-label="Notifications">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.9"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                <path d="M13.7 21a2 2 0 0 1-3.4 0"/>
            </svg>
        </button>
        <span class="notif-dot"></span>
    </div>

    {{-- New Filing --}}
    <a href="{{ url('/filings/new') }}" class="btn-new-filing">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
             stroke="currentColor" stroke-width="2.2"
             stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 5v14M5 12h14"/>
        </svg>
        New Filing
    </a>

</header>
@endif
