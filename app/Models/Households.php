<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\HouseholdMembers;
use App\Models\GroupsHousehold;
use App\Models\HouseholdMemberBeneficiaries;
use App\Models\Group;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\Exception\UnsatisfiedDependencyException;
use App\Models\MeetingLocations;
use App\Events\AssignHouseholdNotification;
use App\Models\HouseholdEmployees;
use App\Events\LastModifiedDate as LastModifiedDateEvent;

class Households extends Model
{
    protected $table = 'households';

    protected $guarded = ['id'];

    protected $appends  = [
        'initials_avatar',
        'estimate_value',
        'formatted_name',
        'schedule_meeting_status',
        'beneficiaries_member',
        'meeting_last_location'
    ];

    public function accounts(){
        $instance = $this->hasMany('\App\Models\Accounts','household_id','id');
        $instance->where('status_id','<>', 16);
        return $instance;
    }

    public function accountsSum(){
    	return $this->accounts()->selectRaw("IFNULL(sum(balance), 0) as total_balance");
    }

    public function totalNetWorth(){
    	return $this->accounts()->select('household_id',\DB::Raw('IFNULL(sum(balance), 0) as total_balance'))->groupBy('household_id');
    }

    public function householdMembers()
    {
        return $this->hasMany('App\Models\HouseholdMembers', 'household_id', 'id')->where('deceased','=',0);
    }

    public function householdAddresses()
    {
        return $this->hasMany('App\Models\HouseholdAddress', 'household_id', 'id');
    }

    public function householdCpa()
    {
        return $this->hasOne('App\Models\HouseholdCpa', 'household_id', 'id');
    }

    public function groupHouseholds()
    {
        return $this->hasMany('App\Models\GroupsHousehold', 'household_id')->orderBy('updated_at');
    }

    public function advisor1()
    {
        return $this->hasOne('App\Models\Advisors', 'id', 'advisor_1_id');
    }

    public function advisor2()
    {
        return $this->hasOne('App\Models\Advisors', 'id', 'advisor_2_id');
    }

    public function sales()
    {
        return $this->hasOne('App\Models\Sales', 'id', 'sales_1_id');
    }

    public function status()
    {
        return $this->hasOne('App\Models\HouseholdStatus', 'id', 'status_id');
    }

    public function source()
    {
        return $this->hasOne('App\Models\Sources', 'id', 'source_id');
    }

    public function primaryMember()
    {
        return $this->hasOne('App\Models\HouseholdMembers', 'id', 'primary_member_id');
    }

    public function referredHousehold()
    {
        return $this->hasOne('App\Models\Households', 'id', 'referred_household_id');
    }

    public function meetingRequest()
    {
        return $this->hasMany('App\Models\MeetingRequests', 'household_id', 'id');
    }

    public function meetings()
    {
        return $this->hasMany('App\Models\HouseholdMeetings', 'household_id', 'id');
    }

    public function getNameAttribute($value)
    {
        return ucwords(strtolower($value));
    }

    public function getInitialsAvatarAttribute($value){
        $name = $this->name;
        $words = explode(" ",$name);
        $InitialsCharacter = "";

        foreach ($words as $w) {
            if(!empty($w[0])){
                $InitialsCharacter .= $w[0];
            }
        }
        return $InitialsCharacter;
    }

    public function getEstimateValueAttribute()
    {
        return $this->estimate;
    }

    public function contacts(){
        return $this->hasMany('App\Models\HouseholdMembers', 'household_id', 'id')
                        ->where('deceased','=', 0)
                        ->groupBy('email');
    }

    public function custodianSales(){
        return $this->hasOne('App\Models\CustodianSalesRep','id','custodian_sales_rep');
    }

    /**
    * household formatted name using members
    */
    public function getFormattedNameAttribute()
    {
        $household = $this;
        $formattedName = '';

        $first_names = [];
        $last_names = [];

        if ($household->householdMembers->count() > 0) {
            $members = $household->householdMembers;

            //only loop the first 2 members (type_id = 1) of the household
            $i = 0;
            foreach ($members as $member) {
                if ($i == 2) {
                    break;
                }

                if (!in_array(trim(ucwords(strtolower($member->first_name))), $first_names) && !empty(trim($member->first_name))) {
                    $first_names[] = trim(ucwords(strtolower(trim($member->first_name))));
                }

                if (!in_array(trim(ucwords(strtolower($member->last_name))), $last_names) && !empty(trim($member->last_name))) {
                    $last_names[] = trim(ucwords(strtolower(trim($member->last_name))));
                }

                $i += 1;
            }

            if (count($last_names) == 1) {
                $formattedName =  $householdName = implode(' & ', array_map('trim', $first_names)) . ' ' . $last_names[0];
            } elseif (count($last_names) == 2 && count($first_names) == 2) {
                $formattedName =  $householdName = trim($first_names[0]). ' ' . trim($last_names[0]) . ' & ' . trim($first_names[1]). ' ' . trim($last_names[1]);
            }elseif (!empty($household->primaryMember) && !empty(trim($household->primaryMember->first_name)) && !empty(trim($household->primaryMember->last_name))) {
                $formattedName =  ucwords(strtolower(trim($household->primaryMember->first_name))) . ' ' . ucwords(strtolower(trim($household->primaryMember->last_name)));
            }
        }

        if (empty($formattedName) && !empty($household->name) && $household->name <> '[need_update]'){
            $formattedName = $household->name;
        }

        if (empty($formattedName)){
            $account = $household->accounts->first();

            if ($account){
                $formattedName = $account->name;
            }
        }

        return trim($formattedName);
    }

    public function getScheduleMeetingStatusAttribute(){

        $household = $this;
        $householdGroup = [];
        $instanceType = config('benjamin.instance_type');
        if($instanceType != 'insurance'){
            $unscheduleGroup = Group::where('name','Unscheduleds')->first();

            if ($unscheduleGroup){
                $householdGroup  = GroupsHousehold::where('group_id',$unscheduleGroup->id)->where('household_id',$household->id)->get();
            }

        }
        if(count($householdGroup) > 0 ) {
            return 'pending';
        } else {
            return 'completed';
        }
    }

    public function partner(){
        return $this->hasOne('App\Models\Partners','id','partner_id');
    }

    public function scopeStatusId($query, $status_id)
    {
        return $query->where('status_id', $status_id);
    }

    public function additionalInfo()
    {
        return $this->hasOne('App\Models\HouseholdAdditionInformation','household_id','id');
    }

    public function householdJunxureIntegration()
    {
        return $this->hasOne('App\Models\HouseholdIntegration','household_id','id')->where('intergration','junxure');
    }

    public function householdSalesforceIntegration()
    {
        return $this->hasOne('App\Models\HouseholdIntegration','household_id','id')->where('intergration','salesforce');
    }

    public function householdHubspotIntegration()
    {
        return $this->hasOne('App\Models\HouseholdIntegration','household_id','id')->where('intergration','hubspot');
    }

    public function companyInfo()
    {
        return $this->hasOne('App\Models\CompanyInfo','household_id','id');
    }

    public function getBeneficiariesMemberAttribute()
    {
        $household = $this;
        $members = HouseholdMembers::where('household_id',$household->id)->where('deceased','=',0)->pluck('id');
        return HouseholdMemberBeneficiaries::whereIn('household_member_id',$members)->get();
    }


    public function assignEmployee($householdId, $advisorId)
    {

        if (empty($advisorId)) {
            return;
        }

        $sql = sprintf("
            select e.id as eid, ad.id as aid from advisors ad
            join assistants a on a.id = ad.assistant_id
            join employees e on e.user_id = a.user_id
            where ad.id  = %s", $advisorId);

        $householdEmployees = \DB::select($sql);

        if ($householdEmployees) {
            foreach ($householdEmployees as $key => $householdemployee) {
                HouseholdEmployees::updateOrCreate([
                    'household_id' => $householdId,
                    'employee_id' => $householdemployee->eid
                ]);
            }
        }
    }


    public static function boot()
    {
        parent::boot();
        static::created(function($model) {
            $model->uuid = (string) Uuid::uuid4();
            $model->save();
            $model->assignEmployee($model->id, $model->advisor_1_id);
            event(new AssignHouseholdNotification($model));
        });

        static::updating(function($model) {
            if ($model->advisor_1_id != $model->getOriginal('advisor_1_id')) {
                if ($model->advisor_1_id) {
                    $model->assignEmployee($model->id, $model->advisor_1_id);
                }
            }
        });

        static::updated(function($model) {
            $instanceType = config('benjamin.instance_type');
            if($instanceType == 'insurance'){
                event(new LastModifiedDateEvent( $model->id, $model->updated_at));
            }
        });
    }

    public function employee()
    {
        return $this->hasOne('App\Models\Employee','id','employee_id');
    }

    public function getMeetingLastLocationAttribute(){
        $lastmeetings = HouseholdMeetings::where('household_id',$this->id)->latest()->first();
        return MeetingLocations::find($lastmeetings['location']);
    }

    public function householdEmployees()
    {
        return $this->hasMany('App\Models\HouseholdEmployees', 'household_id');
    }

    public function employees()
    {
        return $this->belongsToMany(Employee::class, 'household_employees', 'household_id' , 'employee_id')->withTimestamps();
    }

    public function householdOrionIntegration()
    {
        return $this->hasOne('App\Models\HouseholdIntegration','household_id','id')->where('intergration','orion');
    }
}
