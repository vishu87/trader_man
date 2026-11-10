{{-- Overview --}}
<div class="nav-section-header">Overview</div>
<ul class="nav-list">
    <x-menu-item
        title="Dashboard"
        href="{{ url('/dashboard') }}"
        :active="isset($sidebar) && $sidebar == 'dashboard'">
        <x-slot name="icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="3" width="7" height="7" rx="1"/>
                <rect x="14" y="3" width="7" height="7" rx="1"/>
                <rect x="3" y="14" width="7" height="7" rx="1"/>
                <rect x="14" y="14" width="7" height="7" rx="1"/>
            </svg>
        </x-slot>
    </x-menu-item>
</ul>

{{-- Portfolio --}}
<div class="nav-section-header">Portfolio</div>
<ul class="nav-list">
    <x-menu-item
        title="Portfolio"
        href="{{ url('/portfolio') }}"
        :active="isset($sidebar) && $sidebar == 'portfolio'">
        <x-slot name="icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="7" width="20" height="14" rx="2"/>
                <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>
            </svg>
        </x-slot>
    </x-menu-item>
    <x-menu-item
        title="Watchlist"
        href="{{ url('/watchlist') }}"
        :active="isset($sidebar) && $sidebar == 'watchlist'">
        <x-slot name="icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                <circle cx="12" cy="12" r="3"/>
            </svg>
        </x-slot>
    </x-menu-item>
    <x-menu-item
        title="Charts"
        href="{{ url('/charts') }}"
        :active="isset($sidebar) && $sidebar == 'charts'">
        <x-slot name="icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 3v18h18"/>
                <path d="M7 15l4-5 3 3 5-7"/>
            </svg>
        </x-slot>
    </x-menu-item>
    @if(config('services.trade_signals.enabled'))
    <x-menu-item
        title="Trade Signals"
        href="{{ url('/trade-signals') }}"
        :active="isset($sidebar) && $sidebar == 'trade-signals'">
        <x-slot name="icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round">
                <polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/>
                <polyline points="16 7 22 7 22 13"/>
            </svg>
        </x-slot>
    </x-menu-item>
    @endif
</ul>

{{-- Trades --}}
<div class="nav-section-header">Trades</div>
<ul class="nav-list">
    <x-menu-item
        title="Trades"
        href="{{ url('/trades') }}"
        :active="isset($sidebar) && $sidebar == 'trades'">
        <x-slot name="icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 3l4 4-4 4"/>
                <path d="M3 7h18"/>
                <path d="M7 21l-4-4 4-4"/>
                <path d="M21 17H3"/>
            </svg>
        </x-slot>
    </x-menu-item>
    <x-menu-item
        title="Zerodha Trades"
        href="{{ url('/zerodha-trades') }}"
        :active="isset($sidebar) && $sidebar == 'zerodha-trades'">
        <x-slot name="icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 4h16v16H4z"/>
                <path d="M8 9h8"/>
                <path d="M8 13h8"/>
                <path d="M8 17h5"/>
            </svg>
        </x-slot>
    </x-menu-item>
</ul>

{{-- Scanner --}}
<div class="nav-section-header">Scanner</div>
<ul class="nav-list">
    <x-menu-item
        title="Stock Scanner"
        href="{{ url('/stock-scanner') }}"
        :active="isset($sidebar) && $sidebar == 'stock-scanner'">
        <x-slot name="icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="7"/>
                <path d="M21 21l-4.3-4.3"/>
                <path d="M8 11h6"/>
                <path d="M11 8v6"/>
            </svg>
        </x-slot>
    </x-menu-item>
</ul>

{{-- Live trading --}}
<div class="nav-section-header">Live Chartink</div>
<ul class="nav-list">
    <x-menu-item
        title="Live Signals"
        href="{{ url('/live') }}"
        :active="isset($sidebar) && $sidebar == 'live-signals'">
        <x-slot name="icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M4.9 19.1a10 10 0 0 1 0-14.2"/>
                <path d="M7.8 16.2a6 6 0 0 1 0-8.4"/>
                <circle cx="12" cy="12" r="1.5"/>
                <path d="M16.2 7.8a6 6 0 0 1 0 8.4"/>
                <path d="M19.1 4.9a10 10 0 0 1 0 14.2"/>
            </svg>
        </x-slot>
    </x-menu-item>
    <x-menu-item
        title="Live Config"
        href="{{ url('/live-config') }}"
        :active="isset($sidebar) && $sidebar == 'live-config'">
        <x-slot name="icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round">
                <line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/>
                <line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/>
                <line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/>
                <line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>
            </svg>
        </x-slot>
    </x-menu-item>
    <x-menu-item
        title="Old Signals"
        href="{{ url('/scanner') }}"
        :active="isset($sidebar) && $sidebar == 'scanner'">
        <x-slot name="icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="7"/>
                <path d="M21 21l-4.3-4.3"/>
                <path d="M8 11h6"/>
                <path d="M11 8v6"/>
            </svg>
        </x-slot>
    </x-menu-item>
</ul>

