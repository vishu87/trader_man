<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Models\UserPrivilege;


class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public static function getRandPassword()
    {

        $string1 = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
        $string2 = "abcdefghijklmnopqrstuvwxyz";
        $string3 = "0123456789";
        $string4 = "$@";
        $string5 = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789$@";

        $n = rand(0, strlen($string1) - 1);
        $rand_pwd =  $string1[$n];

        for ($i = 0; $i < 2; $i++) {
            $n = rand(0, strlen($string2) - 1);
            $rand_pwd .=  $string2[$n];
        }

        $n = rand(0, strlen($string3) - 1);
        $rand_pwd .=  $string3[$n];

        $n = rand(0, strlen($string4) - 1);
        $rand_pwd .=  $string4[$n];

        for ($i = 0; $i < 3; $i++) {
            $n = rand(0, strlen($string5) - 1);
            $rand_pwd .=  $string5[$n];
        }

        return $rand_pwd;
    }

    public static function sendMail($tomail, $cc, $bcc, $subject, $content)
    {
        $mail_queue = new MailQueue;
        $mail_queue->mailto = $tomail;
        $mail_queue->subject = $subject;
        $mail_queue->content = $content;
        $mail_queue->save();
    }

    public static function checkPermission($id)
    {
        $permissions = DB::table('user_permissions')->where('user_id', Auth()->user()->id)->pluck('access_right_id')->toArray();

        if (in_array($id, $permissions)) {
            return true;
        } else {
            return false;
        }
    }

    public static function checkPermissionApi($user_id, $id)
    {
        $permissions = DB::table('user_permissions')->where('user_id', $user_id)->pluck('access_right_id')->toArray();

        if (in_array($id, $permissions)) {
            return true;
        } else {
            return false;
        }
    }

    public static function checkSuperAdmin($user_id)
    {
        $user_access =  DB::table('users')->where('id', $user_id)->pluck('privilege')->toArray();

        return in_array(1, $user_access);
    }

    public static function AuthenticateUser($api_token){
        if(!$api_token || $api_token == NULL){
            die("user not found");
        } else {

            $user = Cache::remember("user-".$api_token, 2*60, function() use ($api_token){
                $user = DB::table("users")->select("id","name","api_token","privilege","client_id")->where('api_token',$api_token)->where("is_active",1)->first();

                if($user){

                }
                return $user;
            });
            if($user){
                return $user;
            } else {
                die("user not found");
            }
        }
    }

    public static function sendSMS($mobile_number, $message, $type){
        
        if($type == "otp") {
            $template_id = "1107167159676444975";
            $sender_id = "AVYAYT";
        }

        $ch = curl_init();
        
        $base_url = "http://sms.digimiles.in/bulksms/bulksms?username=".env("SMS_USERNAME")."&password=".env("SMS_PASSWORD")."&type=0&dlr=1&destination=".$mobile_number."&source=".$sender_id."&message=".curl_escape($ch, $message)."&entityid=".env("PE_ID")."&tempid=".$template_id."&tmid=1001667586729907800,1602100000000009244";

        curl_setopt($ch, CURLOPT_URL,  $base_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $buffer = curl_exec($ch);
    }


    public static function check_hod($user_id){
        $user = Department::where('hod_id',$user_id)->count();
        if($user > 0){
            return true;
        }else{
            return false;
        }
    }


    public static function check_auth($api_token){
        $user = User::select('id','username','email','name','profile_photo','photo_thumb','photo_full','mobile','is_active','status','client_id')->where('api_token',$api_token)->first();
        
        return $user;
    }

    public static function checkCEO($employee_id){
        $user_access = [];
        $user_access =  UserPrivilege::where('employee_id',$employee_id)->pluck('privilege')->all();
        if(in_array(32,$user_access)){
            return true;
        } else {
            return false;
        }
    }

    public static function sendNotification($heading, $content, $user_id){

        return;
        
        $contents = array(
            "en" => $content
        );

        $headings = array(
            "en" => $heading
        );

        $fields = array(
            'app_id' => "041426f5-b43a-4086-a14b-aff7f374fe1e",
            'filters' => array(array("field" => "tag", "key" => "code", "relation" => "=", "value" => 'user-'.$user_id)),
            'contents' => $contents,
            'headings' => $headings,
            'android_accent_color' => 'FF123c66'
        );
        
        $fields = json_encode($fields);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=utf-8','Authorization: Basic OTljZDUzNGItNDE5MC00NjMyLTk0ZjgtMzEzZmVhODNiOTU4'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

        $response = curl_exec($ch);
        curl_close($ch);
        
    }
}
