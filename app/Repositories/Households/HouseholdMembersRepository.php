<?php

namespace App\Repositories\Households;

use App\Models\Accounts;
use App\Models\Advisors;
use App\Models\Conversation;
use App\Models\HouseholdMeetingFrequency;
use App\Models\HouseholdMemberBeneficiaries;
use App\Models\HouseholdMemberEmployer;
use App\Models\HouseholdMembers;
use App\Models\HouseholdMemberTypes;
use App\Models\Households;
use App\Models\CompanyInfo;
use App\Models\HouseholdMeetings;
use App\Models\Sources;
use App\Models\Sales;
use Carbon\Carbon;
use App\Events\AddToGroup;

use DB;
use Schema;

class HouseholdMembersRepository
{

    public function __construct()
    {

    }

    public function copyHouseholdMembersFromDemo($org_id)
    {
        $data = DB::select('select * from `benjamin-portal`.household_members_demos;');
        $data = json_decode(json_encode($data), true);

        foreach ($data as &$d) {
            $d['org_id'] = $org_id;
        }

        HouseholdMembers::insert($data);
        return $data;
    }

    public function deleteHouseholdMembers($org_id)
    {
        HouseholdMembers::truncate();
    }

    public function getMembersInfo($id)
    {
        $household   = Households::find($id);
        $member_type = HouseholdMemberTypes::whereIn('type', ['Primary','Secondary'])->pluck('id')->toArray();

        $household_members = HouseholdMembers::with('memberEmployer', 'households', 'memberBeneficiaries')->where('household_id', $id)->where(function ($q) use ($member_type) {
            $q->whereIn('type_id', $member_type)->orWhereIn('type_id', [1, 2]);
        })->where('active', true);

        if ($household->primary_member_id) {
            $household_members = $household_members->orderBy(\DB::raw("FIELD(id, " . $household->primary_member_id . " ) "), 'desc');
        }

        $household_members = $household_members->get();

        foreach ($household_members as &$household_member) {
            $household_member->first_name = ucwords(strtolower($household_member->first_name));
            $household_member->last_name  = ucwords(strtolower($household_member->last_name));
            $household_member->email      = strtolower($household_member->email);
        }

        return $household_members;
    }

    public function setHouseholdPrimaryMember($id, $member_id)
    {
        Households::where('id', $id)->update(['primary_member_id' => $member_id]);
        return true;
    }

    public function advisorSave($id, $data)
    {
        try {
            $household = Households::find($id);

            $advisor1 = $household->advisor_1_id;
            $advisor2 = $household->advisor_2_id;

            if ($data['action'] != 'delete') {
                $advisors             = Advisors::find($data['adv_id']);
                $advisors->cell_phone = $data['cell_phone'];
                $advisors->save();
            }

            if (!empty($advisor1) && !empty($advisor2) && $data['action'] != 'delete') {

                if ($data['advisors_index'] == 'advisor_1_id') {

                    if (($advisor1 != $data['adv_id']) && ($advisor2 == $data['adv_id'])) {
                        $household->advisor_1_id = $data['adv_id'];
                        $household->advisor_2_id = $advisor1;
                    } else {
                        $household->advisor_1_id = $data['adv_id'];
                        $household->advisor_2_id = $advisor2;
                    }

                } else {

                    if (($advisor2 != $data['adv_id']) && ($advisor1 == $data['adv_id'])) {
                        $household->advisor_1_id = $advisor2;
                        $household->advisor_2_id = $data['adv_id'];
                    } else {
                        $household->advisor_1_id = $advisor1;
                        $household->advisor_2_id = $data['adv_id'];
                    }

                }

            } else if ($data['action'] == 'add') {

                if ($data['advisors_index'] == 'advisor_1_id' && $advisor1 != $data['adv_id']) {
                    $household->advisor_1_id = $data['adv_id'];
                    $household->advisor_2_id = $advisor1;
                }

                if ($data['advisors_index'] == 'advisor_2_id' && (!empty($advisor1)) && $advisor1 == $data['adv_id']) {
                    $household->advisor_1_id = $data['adv_id'];
                    $household->advisor_2_id = $advisor2;
                }

                if ($data['advisors_index'] == 'advisor_2_id' && (!empty($advisor1)) && $advisor1 != $data['adv_id']) {
                    $household->advisor_1_id = $advisor1;
                    $household->advisor_2_id = $data['adv_id'];
                }

            } else if ($data['action'] == 'update') {

                if ($data['advisors_index'] == 'advisor_1_id' && (!empty($advisor1)) && $advisor1 != $data['adv_id']) {
                    $household->advisor_1_id = $data['adv_id'];
                }

                if ($data['advisors_index'] == 'advisor_2_id' && (!empty($advisor1)) && $advisor2 != $data['adv_id']) {
                    $household->advisor_2_id = $data['adv_id'];
                }

            } else if ($data['action'] == 'delete') {
                $household[$data['advisors_index']] = 0;
            }

            $household->save();

            return [];

        } catch (Exception $e) {
            return [
                'code'    => 500,
                'message' => $e->getMessage(),
                'result'  => false,
            ];
        }
    }

    public function familySave($household_id, $data)
    {

        $saveData = [];
        if (!isset($data['id'])) {
            $saveData['source'] = "user";
        }

        $dob = 'date';
        if($data['dob'] == 'Invalid date'){
           $dob = '';
        }

        $saveData['org_id']       = $data['org_id'];
        $saveData['first_name']   = $data['first_name'];
        $saveData['last_name']    = (!empty($data['last_name'])) ? $data['last_name'] : NULL;
        $saveData['cell_phone']   = $data['cell_phone'];
        $saveData['home_phone']   = $data['home_phone'];
        $saveData['deceased']     = (empty($data['deceased'])) ? 0 : $data['deceased'];
        $saveData['type_id']      = $data['member_type'];
        $saveData['work_phone']   = $data['work_phone'];
        $saveData['dob']          = (empty($dob)) ? null : date('Y-m-d', strtotime($data['dob']));
        $saveData['address_1']    = $data['address_1'];
        $saveData['email']        = $data['email'];
        $saveData['household_id'] = $household_id;
        $saveData['user_edit']    = Carbon::now();

        // $saveData['id']         = $data['id'];

        $checkData     = ['id' => $data['id']];
        $returnData    = HouseholdMembers::updateOrCreate($checkData, $saveData);

        $primaryType   = HouseholdMemberTypes::where('type', 'Primary')->value('id');
        $secondaryType = HouseholdMemberTypes::where('type', 'Secondary')->value('id');
        $otherType     = HouseholdMemberTypes::where('type', 'Other')->value('id');


        $household = Households::find($household_id);

        if($data['member_type'] == $primaryType) {
            $household->primary_member_id = $returnData['id'];
            $household->save();
        }

        $householdMember = HouseholdMembers::where('type_id', $data['member_type'])
                                ->where('household_id',$household_id)
                                ->where('id','<>',$returnData['id'])
                                ->update([ 'type_id' => $otherType ]);

        return $returnData;
    }

    public function familyDelete($id)
    {
        try {
            $returnData = HouseholdMembers::find($id)->delete();

            return [
                'code'   => 200,
                'result' => $returnData,
            ];
        } catch (Exception $e) {
            return [
                'code'    => 500,
                'message' => $e->getMessage(),
                'result'  => false,
            ];
        }
    }

    public function familyGet($id, $active = true)
    {
        try {
            $member_type      = HouseholdMemberTypes::whereIn('type', ['Primary','Secondary'])->pluck('id')->toArray();
            $member_type_data = HouseholdMemberTypes::whereIn('type', ['Other'])->pluck('id')->toArray();

            $household = Households::find($id);
            $family    = HouseholdMembers::with(['memberType', 'households'])->where('household_id', $id)
                ->whereNotIn('type_id', $member_type)
                ->whereIn('type_id', $member_type_data);

            if($active){
                $family = $family->where('active', true);
            }

            if ($household->primary_member_id) {
                $family = $family->orderBy(\DB::raw("FIELD(id, " . $household->primary_member_id . " ) "), 'desc');
            }

            $data['family'] = $family->get();

            return [
                'code'   => 200,
                'result' => $data,
            ];
        } catch (Exception $e) {
            return [
                'code'    => 500,
                'message' => $e->getMessage(),
                'result'  => false,
            ];
        }
    }

    public function memberInfoSave($data)
    {
        try {

            $home_phone = $data['home_phone'];
            $cell_phone = $data['cell_phone'];
            $work_phone = $data['work_phone'];
            $deceased   = $data['deceased'];
            $notify_via = $data['notify_via'];

            //$household  = $data['household'];
            $employer = $data['employer'];
            $address  = $data['address'];

            $member_id  = $data['member_id'];
            $check_data = ['household_member_id' => $member_id];
            $check_id   = ['id' => $member_id];
            //$household_id     = ['id' => $data['household_id']];

            $employer_data = [
                'employer' => $employer,
                'address'  => $address,
            ];
            HouseholdMemberEmployer::updateOrCreate($check_data, $employer_data);

            $household_member = [
                'home_phone' => $home_phone,
                'cell_phone' => $cell_phone,
                'work_phone' => $work_phone,
                'deceased'   => $deceased,
                'notify_via' => $notify_via,
            ];
            HouseholdMembers::updateOrCreate($check_id, $household_member);

            return [
                'code'   => 200,
                'result' => '',
            ];
        } catch (Exception $e) {
            return [
                'code'    => 500,
                'message' => $e->getMessage(),
                'result'  => false,
            ];
        }
    }

    public function updateSource($household_id, $data)
    {
        try {
            $source_id   = $data['source'];
            $household   = Households::where('id', $household_id)->update(['source_id' => $source_id]);
            $source      = Sources::select('name')->where('id', $data['source'])->first();
            $source_name = $source->name;
            return [
                'code'   => 200,
                'result' => $source_name,
            ];
        } catch (Exception $e) {
            return [
                'code'    => 500,
                'message' => $e->getMessage(),
                'result'  => false,
            ];
        }
    }

    public function updateMeetingFrequency($household_id, $data)
    {
        try {
            $meeting_id = $data['meetingFrequency'];
            $data       = array(
                'household_id' => $household_id,
                'frequency'    => $meeting_id,
                'user_id'      => 3,
            );
            $add_meeting_frequency = HouseholdMeetingFrequency::updateOrCreate($data);
            return [
                'code'   => 200,
                'result' => $add_meeting_frequency,
            ];
        } catch (Exception $e) {
            return [
                'code'    => 500,
                'message' => $e->getMessage(),
                'result'  => false,
            ];
        }
    }
    public function getSourceMeetingFrequency($id)
    {
        try {
            $data                      = [];
            $household                 = Households::find($id);
            $source                    = Sources::find($household->source_id);
            $householdMeetingFrequency = HouseholdMeetingFrequency::where('household_id', $id)->first();
            $accounts                  = Accounts::where('household_id', $id)->first();
            $conversation              = Conversation::where('household_id', $id)->orderBy('id', 'desc')->first();
            $lastMeeting               = HouseholdMeetings::where('household_id', $id)->orderBy('id', 'desc')->first();

            $data['clientSince']      = ($accounts) ? date("m-d-Y", strtotime($accounts->advisement_date)) : 'N/A';
            $data['source']           = ($source) ? $source->name : '';
            $data['meetingFrequency'] = ($householdMeetingFrequency) ? $householdMeetingFrequency->frequency : '';
            $data['conversation']     = ($conversation) ? $conversation->created_at : 'N/A';
            $data['lastMeeting']      = ($lastMeeting) ? date('Y-m-d',strtotime($lastMeeting->meeting)) : 'N/A';

            return [
                'code'   => 200,
                'result' => $data,
            ];
        } catch (Exception $e) {
            return [
                'code'    => 500,
                'message' => $e->getMessage(),
                'result'  => false,
            ];
        }
    }

    public function getSource($household_id)
    {
        try {
            $data['source']         = Sources::get();
            $household              = Households::find($household_id);
            $data['selected_value'] = $household->source_id;

            return [
                'code'   => 200,
                'result' => $data,
            ];
        } catch (Exception $e) {
            return [
                'code'    => 500,
                'message' => $e->getMessage(),
                'result'  => false,
            ];
        }
    }
    public function getClientSince()
    {
        $data = array([
            'id'          => '1',
            'clientSince' => 'Test 1'],
            ['id'         => '2',
                'clientSince' => 'Test 2'],
            ['id'         => '3',
                'clientSince' => 'Test 3',
            ]);

        return [
            'code'    => 200,
            'respose' => $data,
        ];
    }

    public function getMeetingFrequency()
    {
        $data = array([
            'id'          => '1',
            'lastContact' => 'Monthly'],
            ['id'         => '2',
                'lastContact' => 'Quarterly'],
            ['id'         => '3',
                'lastContact' => 'Bi-Annual'],
            ['id'         => '4',
                'lastContact' => 'Annual',
            ]);

        return [
            'code'    => 200,
            'respose' => $data,
        ];
    }

    public function addMembers($household_id, $data)
    {
        try {
            $insertedId = [];
            foreach ($data['members'] as $key => $member) {
                $member['last_name']    = (strstr($member['first_name'], ' ')) ? strstr($member['first_name'], ' ') : '';
                $member['first_name']   = strtok($member['first_name'], ' ');
                $member['household_id'] = $household_id;
                $member['org_id']       = $data['org_id'];
                $lastMember             = HouseholdMembers::create(['email' => $member['email'], 'home_phone' => $member['home_phone'], 'work_phone' => $member['work_phone'], 'first_name' => $member['first_name'], 'last_name' => $member['last_name'], 'household_id' => $household_id, 'org_id' => $data['org_id']]);

                /*$lastMember = HouseholdMembers::updateOrCreate(['email' => $member['email'],'household_id'=>  $household_id,'first_name' => $member['first_name']],$member);*/
                $insertedId[] = $lastMember->id;
            }
            $lastObj                 = HouseholdMembers::find($lastMember->id);
            $membersList             = HouseholdMembers::whereIn('id', $insertedId)->get();
            $response                = [];
            $response['data']        = $lastObj;
            $response['membersList'] = $membersList;

            return [
                'code'   => 200,
                'result' => $response,
            ];
        } catch (Exception $e) {
            return [
                'code'    => 500,
                'message' => $e->getMessage(),
                'result'  => false,
            ];
        }
    }

    public function addBeneficiary($householdId, $input)
    {
        if (count($input['primaryBeneficiaryInfo']) > 0 || count($input['contigentBeneficiaryInfo']) > 0) {
            $insertedId = [];
            $typeId     = HouseholdMemberTypes::where('type', 'beneficiary')->first()->id;
            $accountId  = (!empty($input['accountId'])) ? $input['accountId'] : null;

            //primaryBeneficiaryInfo insert into household member
            foreach ($input['primaryBeneficiaryInfo'] as $key => $value) {
                $insert = HouseholdMembers::create([
                    'org_id'               => $input['org_id'],
                    'household_id'         => $householdId,
                    'type_id'              => $typeId,
                    'first_name'           => $value['beneficiaryFirstName'],
                    'last_name'            => $value['beneficiaryLastName'],
                    'beneficiary_category' => 'primary',
                    'share_percentage'     => $value['sharePercentage'],
                    'relationship'         => $value['relationship'],
                    'ssn'                  => $value['socialSecurityNumber'],
                    'dob'                  => ($value['dateOfBirth']) ? date('Y-m-d', strtotime($value['dateOfBirth'])) : null,
                    'address_1'            => '',
                    'city'                 => '',
                    'state'                => '',
                    'zip'                  => '',
                ]);
                $insertedId[] = $insert->id;

                //HouseholdMemberBeneficiaries insert data
                HouseholdMemberBeneficiaries::create([
                    'household_member_id' => $insert->id,
                    'account_id'          => $accountId,
                    'type'                => 'primary',
                    'name'                => trim($value['beneficiaryFirstName'] . ' ' . $value['beneficiaryLastName']),
                    'dob'                 => ($value['dateOfBirth']) ? date('Y-m-d', strtotime($value['dateOfBirth'])) : null,
                ]);
            }

            //contigentBeneficiaryInfo insert into household member
            foreach ($input['contigentBeneficiaryInfo'] as $key => $value) {
                $insert = HouseholdMembers::create([
                    'org_id'               => $input['org_id'],
                    'household_id'         => $householdId,
                    'type_id'              => $typeId,
                    'first_name'           => $value['beneficiaryFirstName'],
                    'last_name'            => $value['beneficiaryLastName'],
                    'beneficiary_category' => 'contigent',
                    'share_percentage'     => $value['sharePercentage'],
                    'relationship'         => $value['relationship'],
                    'ssn'                  => $value['socialSecurityNumber'],
                    'dob'                  => ($value['dateOfBirth']) ? date('Y-m-d', strtotime($value['dateOfBirth'])) : null,
                    'address_1'            => '',
                    'city'                 => '',
                    'state'                => '',
                    'zip'                  => '',
                ]);
                $insertedId[] = $insert->id;

                //HouseholdMemberBeneficiaries insert data
                HouseholdMemberBeneficiaries::create([
                    'household_member_id' => $insert->id,
                    'account_id'          => $accountId,
                    'type'                => 'contingent',
                    'name'                => trim($value['beneficiaryFirstName'] . ' ' . $value['beneficiaryLastName']),
                    'dob'                 => ($value['dateOfBirth']) ? date('Y-m-d', strtotime($value['dateOfBirth'])) : null,
                ]);
            }
        }

        $response = [];
        if (!empty($insertedId)) {
            //get first record from insertion process
            $firstRec                = HouseholdMembers::find($insertedId[0]);
            $membersList             = HouseholdMembers::whereIn('id', $insertedId)->get();
            $response['data']        = $firstRec;
            $response['membersList'] = $membersList;
        }

        return $response;
    }

    public function addHouseholdMembers($data)
    {
        $household = Households::create([
            'name'      => $data['members'][0]['first_name'],
            'org_id'    => 1,
            'status_id' => 1,
            'prospect'  => 1,
        ]);
        $householdId = $household->id;

        $insertedId = [];
        foreach ($data['members'] as $key => $member) {
            $member['last_name']  = strstr($member['first_name'], ' ');
            $member['first_name'] = strtok($member['first_name'], ' ');

            $lastMember = HouseholdMembers::create(['email' => $member['email'],
                'household_id'                                  => $householdId,
                'home_phone'                                    => $member['home_phone'],
                'cell_phone'                                    => $member['cell_phone'],
                'work_phone'                                    => $member['work_phone'],
                'first_name'                                    => $member['first_name'],
                'last_name'                                     => $member['last_name'],
                'org_id'                                        => $data['org_id']]);

            $insertedId[] = $lastMember->id;
        }
        $lastObj     = HouseholdMembers::find($lastMember->id);
        $membersList = HouseholdMembers::whereIn('id', $insertedId)->get();

        $response         = [];
        $response['data'] = $lastObj;

        $household->primary_member_id = $lastObj['id'];
        $household->save();
        $response['membersList'] = $membersList;
        $response['household']   = $household;

        event(new AddToGroup($household, 'Active Prospects'));

        return $response;
    }

    public function getHouseholdMemberType()
    {
        $data = HouseholdMemberTypes::get();
        return $data;
    }

    public function getBorrowerInfo($id)
    {
        $return         = [];
        $memberTypeId   = HouseholdMemberTypes::where('type', 'Primary')->first()->id;
        $coMemberTypeId = HouseholdMemberTypes::where('type', 'Secondary')->first()->id;

        $member            = HouseholdMembers::where('household_id', $id);
        $membersecond      = HouseholdMembers::where('household_id', $id);
        $return['primary'] = $member->where('type_id', $memberTypeId)->first();
        $return['second']  = $membersecond->where('type_id', $coMemberTypeId)->first();

        return $return;
    }

    public function updateMemberInfo($data, $member_id)
    {
        $member = HouseholdMembers::find($member_id);
        $householdId = Households::where('id', $member->household_id)->first()->id;

        $company = CompanyInfo::where('household_id', $householdId)->first();

        if ($company){
            $activeMembers = HouseholdMembers::where('household_id', $householdId)->where('active', 1)->count();
            $company->active_seats = $activeMembers;
            $company->save();
        }

        $member = $member->update($data);

        return $data;
    }

    public function addTDBeneficiaryMember($householdId, $input)
    {

        $member = HouseholdMembers::create([
            'org_id'       => $input['org_id'],
            'household_id' => $householdId,
            'first_name'   => $input['firstName'],
            'last_name'    => $input['lastName'],
            'relationship' => $input['relationship'],
            'ssn'          => $input['socialSecurityNumber'],
            'dob'          => ($input['dateOfBirth']) ? date('Y-m-d',  strtotime($input['dateOfBirth'])) : null,
        ]);

        return $member;
    }

    public function salesSave($id, $data)
    {
        try {
            $household = Households::find($id);

            $sales1  = $household->sales_1_id;
            $sales2  = $household->sales_2_id;

            if ($data['action'] != 'delete') {
                $sales             = Sales::find($data['sales_id']);
                $sales->cell_phone = $data['cell_phone'];
                $sales->save();
            }

            if(!empty($sales1) && !empty($sales2) && $data['action'] != 'delete'){

                if($data['sales_index'] == 'sales_1_id'){

                    if (($sales1 != $data['sales_id']) && ($sales2 == $data['sales_id'])) {
                        $household->sales_1_id = $data['sales_id'];
                        $household->sales_2_id = $sales1;
                    }else{
                        $household->sales_1_id = $data['sales_id'];
                        $household->sales_2_id = $sales2;
                    }

                }else{

                    if (($sales2 != $data['sales_id']) && ($sales1 == $data['sales_id'])) {
                        $household->sales_1_id = $sales2;
                        $household->sales_2_id = $data['sales_id'];
                    }else{
                        $household->sales_1_id = $sales1;
                        $household->sales_2_id = $data['sales_id'];
                    }

                }

            }else if ($data['action'] == 'add'){

                if ($data['sales_index'] == 'sales_1_id' && $sales1 != $data['sales_id']) {
                    $household->sales_1_id = $data['sales_id'];
                    $household->sales_2_id = $sales1;
                }

                if ($data['sales_index'] == 'sales_2_id' && (!empty($sales1)) && $sales1 == $data['sales_id']) {
                    $household->sales_1_id = $data['sales_id'];
                    $household->sales_2_id = $sales2;
                }

                if ($data['sales_index'] == 'sales_2_id' && (!empty($sales1)) && $sales1 != $data['sales_id']) {
                    $household->sales_1_id = $sales1;
                    $household->sales_2_id = $data['sales_id'];
                }

            }else if ($data['action'] == 'update'){

                if ($data['sales_index'] == 'sales_1_id' && (!empty($sales1)) && $sales1 != $data['sales_id']) {
                    $household->sales_1_id = $data['sales_id'];
                }

                if ($data['sales_index'] == 'sales_2_id' && (!empty($advisor1)) && $sales2 != $data['sales_id']) {
                    $household->sales_2_id = $data['sales_id'];
                }

            }else if ($data['action'] == 'delete'){
                $household[$data['sales_index']] = 0;
            }

            $household->save();

            return [];

        } catch (Exception $e) {
            return [
                'code'    => 500,
                'message' => $e->getMessage(),
                'result'  => false,
            ];
        }
    }

    public function salesGet($id)
    {
        $household = Households::find($id);

        $array_sales = [$household->sales_1_id, $household->sales_2_id];

        $data['sales'] = Sales::whereIn('id', $array_sales)->get();

        $data['sales'] = $data['sales']->map(function ($value) use ($household) {
            if ($value['id'] == $household->sales_1_id) {
                $value['sales_index'] = 'sales_1_id';
            } else {
                $value['sales_index'] = 'sales_2_id';
            }
            return $value;
        });

        return $data;
    }

    public function getTeamMember($id, $active = false)
    {
        $household        = Households::find($id);
        $householdMembers = HouseholdMembers::with(['households'])
                            ->where('id', '<>', $household->primary_member_id) // ignore primary member
                            ->where('household_id', $id);

        if($active){
            $householdMembers = $householdMembers->where('active', true);
        }

        if ($household->primary_member_id) {
            $householdMembers = $householdMembers->orderBy(\DB::raw("FIELD(id, " . $household->primary_member_id . " ) "), 'desc');
        }
        return $householdMembers->get();
    }

    public function saveTeamMember($householdId, $input)
    {
        $data = [];
        if (!isset($input['id'])) {
            $data['source'] = "user";
        }

        $data['org_id']       = 1;
        $data['contact_type'] = $input['contact_type'];
        $data['first_name']   = $input['first_name'];
        $data['last_name']    = $input['last_name'];
        $data['title']        = $input['title'];
        $data['email']        = $input['email'];
        $data['cell_phone']   = $input['cell_phone'];
        $data['work_phone']   = $input['work_phone'];
        $data['household_id'] = $householdId;

        $householdMembers = HouseholdMembers::updateOrCreate(['id' => $input['id']], $data);

        if($input['contact_type'] == '1'){
            HouseholdMembers::where('id','!=', $householdMembers->id)->update(['contact_type' => '2']);
        }

        $household = Households::find($householdMembers->household_id);
        $household->primary_member_id = $householdMembers->id;
        $household->save();



        return $householdMembers;
    }

    public function deleteTeamMember($id)
    {
        return HouseholdMembers::find($id)->delete();
    }

    public function getAllMembers($id)
    {
        return HouseholdMembers::where('household_id' , $id)->get();
    }
}
