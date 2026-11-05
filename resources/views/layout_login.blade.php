<?php $version = env('JS_VERSION'); ?>
<!DOCTYPE html>
<html>
<head>
    <title>{{env('APP_NAME')}}</title>
    <meta charset="utf-8">
    <meta name=viewport content="initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link rel="stylesheet" type="text/css" href="{{url('assets/plugins/bootstrap/css/bootstrap.min.css')}}" />

    <link rel="stylesheet" type="text/css" href="{{url('assets/css/fonts.css?v='.$version)}}">
    <link rel="stylesheet" type="text/css" href="{{url('/assets/css/login.css?v='.$version)}}">
</head>

<body>
    @yield('content')
    <script type="text/javascript">
        var base_url = "{{url('/')}}";
    </script>
    @yield('footer_scripts')
</body>

</html>
