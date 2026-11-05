<form action="{{ url('/login?type='.$type) }}" class="form-horizontal fix-w login-form-wrap" autocomplete="off" method="POST">
    {{ csrf_field() }}

    @if(Session::get('client_id') == 5 || Session::get('client_id') == 7)

    @else
        @if(isset($params->param_148))
            @if($params->param_148)
            <div class="logo mb-5">
                <img src="{{ $params->param_148 }}" />
            </div>
            @endif
        @endif
    @endif

    <h1>{{ $type == "parent" ? "Parent" : "" }} Login</h1>

    @if(Session::has('failure'))
        <div class="alert alert-danger">
            <i class="fa fa-ban-circle"></i>{!! Session::get('failure') !!}
        </div>
    @endif

    @if(Session::has('warning'))
        <div class="alert alert-info">
            <i class="fa fa-ban-circle"></i>{!! Session::get('warning') !!}
        </div>
    @endif
    
    <div class="form-group">
        <label>{{ $type == "parent" ? "Email" : "Username" }}</label>
        {{ Form::text('username','')->class("form-control")->required() }}
    </div>
    <div class="form-group">
        <label>Password</label>
        {{ Form::password('password')->class("form-control")->required() }}
    </div>
    <div class="forgot-row">
        <a href="{{url('forget-password#reset')}}" class="text-link forgot-link">Forgot password?</a>
    </div>
    <div class="text-center mt-4">
        <button type="submit" class="full">Login</button>
        @if($type == "parent")
        <div class="mt-2">
            Not a user? <a href="{{ url('sign-up#signup') }}" class="text-link">Sign Up</a>
        </div>
        @endif
    </div>

    @if($type == "parent")
    <div class="text-center mt-3 login-or">
        OR
    </div>
    <div class="text-center mt-3">
        <a href="https://app.sportzy.in/auth/google?client_id={{Session::get('client_id')}}" class="btn white full google-signin-btn">
            <span>Sign in with Google</span>
            <img src="{{url('assets/images/google_logo.png')}}" alt="Google logo" />
        </a>
    </div>
    @endif
</form>
