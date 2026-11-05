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

{{-- Registrations --}}
<div class="nav-section-header">Registrations</div>
<ul class="nav-list">
    <x-menu-item
        title="Registrations"
        href="{{ url('/registrations') }}"
        :active="isset($sidebar) && $sidebar == 'registrations'">
        <x-slot name="icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                <circle cx="9" cy="7" r="4"/>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
            </svg>
        </x-slot>
    </x-menu-item>
</ul>

{{-- Settings --}}
<div class="nav-section-header">Settings</div>
<ul class="nav-list">
    <x-menu-item
        title="Masters"
        href="{{ url('/masters') }}"
        :active="isset($sidebar) && $sidebar == 'masters'">
        <x-slot name="icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round">
                <ellipse cx="12" cy="5" rx="9" ry="3"/>
                <path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
                <path d="M3 12c0 1.66 4 3 9 3s9-1.34 9-3"/>
            </svg>
        </x-slot>
    </x-menu-item>
</ul>
