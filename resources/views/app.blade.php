<?php $version = env('JS_VERSION', '1'); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>vivo Vision - Backend</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Bootstrap + Bootstrap Icons --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />

    {{-- Fonts (IBM Plex Sans + Mono) --}}
    <link rel="stylesheet" href="{{url('/assets/css/fonts.css?v='.$version)}}" />

    {{-- Design system --}}
    <link rel="stylesheet" href="{{url('/assets/css/styles.css?v='.$version)}}" />
    <link rel="stylesheet" href="{{url('/assets/css/sidebar.css?v='.$version)}}" />
    <link rel="stylesheet" href="{{url('/assets/css/custom.css?v='.$version)}}" />
</head>
<body>

<div class="app-wrapper">
    {{-- Sidebar --}}
    <aside class="sidebar" id="sidebar">
        @include('sidebar')
    </aside>

    {{-- Mobile overlay --}}
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    {{-- Main column — Vue (Inertia) owns the full column including the topbar --}}
    <div class="main-content">
        @inertia
    </div>
</div>

<div id="snackbar"></div>

<script>
    var base_url = "{{url('/')}}";
    @if(Auth::check())
        var api_key   = "{{ Auth::user()->api_token }}";
        var client_id = "{{ Auth::user()->client_id }}";
    @else
        var api_key   = "";
        var client_id = "";
    @endif
</script>

<script src="{{url('assets/plugins/jquery.min.js')}}"></script>
<script src="{{url('assets/plugins/popper.js')}}"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

{{-- Mobile sidebar toggle --}}
<script>
(function () {
    var btn     = document.getElementById('mobileMenuToggle');
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    if (!btn) return;
    function open()  { sidebar.classList.add('mobile-open');  overlay.classList.add('active'); }
    function close() { sidebar.classList.remove('mobile-open'); overlay.classList.remove('active'); }
    btn.addEventListener('click', open);
    overlay.addEventListener('click', close);
})();
</script>

{{-- Sidebar collapse toggle --}}
<script>
(function () {
    var sidebar   = document.getElementById('sidebar');
    var collapseBtn  = document.getElementById('sidebarCollapseBtn');
    var collapseIcon = document.getElementById('sidebarCollapseIcon');
    if (!sidebar || !collapseBtn) return;

    var STORAGE_KEY = 'sidebar_collapsed';

    function setCollapsed(collapsed) {
        if (collapsed) {
            sidebar.classList.add('collapsed');
            collapseIcon.className = 'bi bi-chevron-double-right';
            collapseBtn.title = 'Expand sidebar';
        } else {
            sidebar.classList.remove('collapsed');
            collapseIcon.className = 'bi bi-chevron-double-left';
            collapseBtn.title = 'Collapse sidebar';
        }
        try { localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0'); } catch(e) {}
    }

    // Restore persisted state (no flash — runs before first paint)
    try {
        if (localStorage.getItem(STORAGE_KEY) === '1') setCollapsed(true);
    } catch(e) {}

    collapseBtn.addEventListener('click', function () {
        setCollapsed(!sidebar.classList.contains('collapsed'));
    });
})();
</script>
<script src="{{url('assets/plugins/bootbox.min.js')}}" type="text/javascript"></script>

@vite(['resources/js/app.js'])
@yield('footer_scripts')
</body>
</html>
