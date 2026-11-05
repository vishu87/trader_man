@extends('layout')

@section('content')
    <div>
        @if(Session::has('failure'))
            <div class="alert alert-danger" style="margin-top: 10px;">
            <i class="fa fa-ban-circle"></i><strong>Failure!</strong> {{Session::get('failure')}}
            </div>
        @endif
        
        @if(Session::has('success'))
            <div class="alert alert-success">
            <i class="fa fa-ban-circle"></i><strong>Success!</strong> {{Session::get('success')}}
            </div>
        @endif
        <form action="{{url('updating-password')}}" method="POST">
            @csrf
            <div class="row">
                <div class="col-md-8 form-group">
                    <label>Enter Old Password</label>
                    <input type="password" class="form-control" name="old_password" required>
                </div>
                <div class="col-md-8 form-group">
                    <label>Enter New Password</label>
                    <input type="password" class="form-control" name="new_password" required>
                </div>
                <div class="col-md-8 form-group">
                    <label>Confirm New Password</label>
                    <input type="password" class="form-control" name="new_password_2" required>
                </div>
            </div>
            <button class="btn btn-primary">Update Password</button>
        </form>
    </div>

@endsection