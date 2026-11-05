<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class UserActivity extends Model 
{
	protected $table = 'user_activities';

	public static function createNew($user_id, $activity_type, $remarks, $ip_address, $entity_id=0){
		$activity = new UserActivity;
		$activity->user_id = $user_id;
		$activity->entity_type = $activity_type;
		$activity->remarks = $remarks;
		$activity->ip_address = $ip_address;
		$activity->entity_id = $entity_id;
		$activity->updated_at = date("Y-m-d H:i:s");
		$activity->created_at = date("Y-m-d H:i:s");
		$activity->save();


	}
}