<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;

use Hash, Redirect;

class UserController extends Controller
{
    public function login(){
        // return Hash::make('sample');
        return view('login.login',['type'=>'admin']);
    }

    public function postLogin(Request $request)
    {

        $cre = ['username' => $request->input('username'), 'password' => $request->input('password')];
        $rules = ['username' => 'required', 'password' => 'required'];

        $validator = Validator::make($cre, $rules);

        if ($validator->passes()) {

            $cre['is_active'] = 1;

            if (Auth::attempt($cre)) {
                $user = User::find(Auth::id());
                
                if(!$user->api_token){
                    $user->api_token = Hash::make($user->id . strtotime('now'));
                    $user->save();
                }

                $access_rights = DB::table("user_permissions")->select("access_right_id")->where("user_id",$user->id)->pluck("access_right_id")->toArray();

                Session::put("access_rights", $access_rights);

                UserActivity::createNew($user->id, 'login', '', $request->ip());

                return Redirect::to('/dashboard');
            } else {
                return Redirect::back()->with('failure', 'Invalid username or password');
            }
        } else {
            return Redirect::back()->withErrors($validator)->withInput();
        }
    }

    public function forgetPassword(Request $request)
    {
        return view('reset');
    }

    public function postForgetPassword(Request $request)
    {
        if ($request->validate(['email' => 'required|email'])) {
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return back()->withErrors([
                    'email' => 'The provided credentials do not match our records.',
                ]);
            }

            $rand_pwd = User::getRandPassword();

            $user->password_check = $rand_pwd;
            $user->password = Hash::make($rand_pwd);
            $user->save();

            $subject = 'New Password for WattsWeb';
            $content = view('mails.reset_password', ['user' => $user]);

            $user->sendMail($user->email, '', '', $subject, $content);

            return Redirect::to('/')->with('message', 'New Password has been sent to your Registered Email ID');
            
        } else {
            return back()->withErrors([
                'email' => 'The provided credentials do not match our records.',
            ]);
        }
    }

    public function logout(Request $request)
    {
        UserActivity::createNew(Auth::user()->id, "logout", "", $request->ip());
        Auth::logout();
        return Redirect::to('/');
    }

    public function updatePassword(){
        return view('login.update_password')->with(['sidebar'=>'sidebar']);
    }

    public function passwordChange(){
        return Inertia::render('ChangePassword/Index')->withViewData(['sidebar' => '']);
    }

    public function profileDetail(){
        $profile = DB::table('users')
            ->select(
                'users.id',
                'users.name',
                'users.email',
                'users.mobile',
                'users.username',
                'users.profile_pic',
                'users.created_at',
            )
            ->where('users.id', Auth::id())
            ->first();

        return Inertia::render('User/ProfileDetail', [
            'profile' => $profile,
        ])->withViewData(['sidebar' => '']);
    }

    public function updatingPassword(Request $request){
        $cre = [
            'old_password' => $request->old_password,
            'new_password' => $request->new_password,
            'confirm_password' => $request->new_password_2
        ];
        
        $rules = [
            'old_password' => 'required', 
            'new_password' => 'required|min:5',
            'confirm_password' => 'required|same:new_password'
        ];
        
        $validator = Validator::make($cre, $rules);
        
        if ($validator->passes()){
            $user = DB::table('users')->where('id',Auth::user()->id)->where('password_check',$request->old_password)->first();
            if(!$user){
                return Redirect::back()->with('failure', 'Incorrect Old Password');
            }
            $user = User::find(Auth::user()->id);
            $user->password = Hash::make($request->new_password);
            $user->password_check = $request->new_password;
            $user->save();

        } else{
            return Redirect::back()->with('failure', $validator->errors()->first());
        }
        return Redirect::to('update-password')->with('success', 'Password Updated');
    }

    public function changePassword(Request $request){
        $user = User::AuthenticateUser($request->header("apiToken"));

        $cre = [
            'old_password' => $request->old_password,
            'new_password' => $request->new_password,
            'confirm_password' => $request->confirm_password
        ];
        
        $rules = [
            'old_password' => 'required', 
            'new_password' => 'required|min:8',
            'confirm_password' => 'required|same:new_password'
        ];
        
        $validator = Validator::make($cre, $rules);
        
        if ($validator->passes()){
            $user = DB::table('users')->where('id',$user->id)->where('password_check',$request->old_password)->first();
            if(!$user){
                $data['success'] = false;
                $data['message'] = 'Incorrect Old Password';
                return response()->json($data);  
            }
            $user = User::find($user->id);
            $user->password = Hash::make($request->new_password);
            $user->password_check = $request->new_password;
            $user->save();
            $data['success'] = true;
            $data['message'] = 'Password Updated';

        } else{
            $data['success'] = false;
            $data['message'] = $validator->errors()->first();
        }
        return response()->json($data);  
    }
}
