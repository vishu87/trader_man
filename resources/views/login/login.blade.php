@extends('layout_login')

@section('content')
<div class="login-bg">
        
    <div class="row row-main">
        <div class="col-lg-6 cont1">
            @include("login.login_right")
        </div>

        <div class="col-lg-6 cont2">
            <div class="left-section" id="login">
                @include("login.login_form",["type" => $type])
            </div>
        </div>
    </div>

    <div class="footer-note">
        @if(isset($params->param_37))
            {!! $params->param_37 !!}
        @endif
    </div>

</div>
@endSection