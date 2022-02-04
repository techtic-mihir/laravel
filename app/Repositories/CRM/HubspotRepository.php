<?php
namespace App\Repositories\CRM;

use App\Services\Hubspot;
use App\Models\HouseholdAnalysis;
use App\Models\HouseholdNotes;
use Mail;
use Carbon\Carbon;
use App\Models\CustodianSalesRep;
use App\Models\Custodians;
use App\Models\Prospect;
use App\Models\Households;
use App\Models\HouseholdMembers;
use App\Models\HouseholdIntegration;
use App\Models\HouseholdMembersIntegrations;
use App\Models\TaskTypes;
use App\Models\Tasks;
use App\Repositories\Dashboard\DashboardRepository;
use App\Repositories\Settings\SettingsRepository;
use DB;
use App\Models\TasksAssignment;
use App\Models\Employee;
use App\Models\User;
use App\Events\AddToGroup;
use App\Models\Roles;
use App\Models\Sources;
use App\Models\Channel;
use App\Models\Integration;
use Helper;
use App\Models\IntegrationsSettings;

use App\Repositories\CRM\CRMRepository;

class HubspotRepository
{
    public function __construct(
        Hubspot $hubspot,
        DashboardRepository $dashboardRepository,
        SettingsRepository $settingsRepository,
        CRMRepository $crmRepository
    ) {
        $this->hubspot             = $hubspot;
        $this->dashboardRepository = $dashboardRepository;
        $this->settingsRepository  = $settingsRepository;
        $this->crmRepository       = $crmRepository;
        $this->org_id              = config('benjamin.org_id');
    }

    public function sendSalesEmail($request, $ids)
    {

        //create prospect/household
        $rep = CustodianSalesRep::where('id', $request['rep_id'])->first();
        $cia_rep = $this->dashboardRepository->getSalesRep($rep->state);
        $request['custodian_sales_rep'] = $rep->id;

        //get employee information from cia Rep(sales) name
        if(!empty($cia_rep)){
            $user = User::with('employee')->where('id', $cia_rep->user_id)->first();
            $request['assigned_id'] = $user->employee->id;
        } else {
            return null;
        }

        $details['properties'] = [];

        $details['properties'][] = ['property'=>'cia_sales_reps', 'value'=> str_replace(' ', '_', strtolower($cia_rep->name))];

        //puts this prospect in workflow
        $details['properties'][] = ['property'=>'new_sales_prospect', 'value' => true];

        $custodian = Custodians::where('id', $rep->custodian_id)->first();

        if ($custodian == 'Schwab'){
            $details['properties'][]  = ['property'=>'custodian', 'value' => 'charles_schwab'];
        }else{
            $details['properties'][]  = ['property'=>'custodian', 'value' => strtolower($custodian->name)];
        }


        $action = ($request['prospect_action'] == 'contacted_prospect') ? true : false;
        $details['properties'][] = ['property'=>'previous_meeting_with_prospect', 'value' => $action];

        if (isset($request['prospect_likleyhood'])){
            $likleyhood = ($request['prospect_likleyhood'] == 'warm') ? true : false;
            $details['properties'][] = ['property'=>'sales_done_deal', 'value' => $likleyhood];
        }

        if (empty($rep->email)){
            $rep->email = 'whitney@yourwealth.com';
        }

        //$details['properties'][] = ['property'=>'outside_sales_rep_email', 'value'=> $rep->email];
        $details['properties'][] = ['property'=>'outside_sales_rep_email', 'value' => 'whitney@yourwealth.com'];
        $details['properties'][] = ['property'=>'outside_sales_rep_first_name', 'value' => ucwords(strtolower($rep->fname))];
        $details['properties'][] = ['property'=>'outside_sales_rep_full_name', 'value' => ucwords(strtolower($rep->fname)) .' '. ucwords(strtolower($rep->lname))];


        //household details
        $household = Households::with('primaryMember')->where('id', $ids['household_id'])->first();

        if ($household){

            if($household->email){
                $details['properties'][] = ['property'=>'email', 'value'=>$household->email];
            }
            $details['properties'][] = ['property'=>'firstname', 'value'=>$household->primaryMember->first_name];
            $details['properties'][] = ['property'=>'lastname', 'value'=>$household->primaryMember->last_name];

            $phone = $household->primaryMember->cell_phone ?? $household->primaryMember->home_phone;
            if (!empty($phone)){
                $details['properties'][] = ['property'=>'phone', 'value'=>$phone];
            }


            if (config('benjamin.hubspot')){
                $contact = $this->hubspot->createContact($details);
                \Log::debug(print_r($details, true));
                \Log::debug('HUBSPOT NEW PROSPECT');
                \Log::debug(print_r($contact, true));

                if (isset($contact->identityProfile)) {
                    $hubspot_id = $contact->identityProfile->vid;
                } else {
                    $hubspot_id = $contact->vid;
                }
            } else {
                $hubspot_id = 0;
            }

            HouseholdIntegration::updateOrCreate(
                [
                    'household_id' => $household->id,
                    'intergration' => 'hubspot',
                ],
                [
                    'intergration_id'     => $hubspot_id,
                ]
            );
            if (config('benjamin.hubspot')){
                $update = $this->hubspot->updateContact($hubspot_id, $details);
                \Log::debug('UPDATING PROSPECT');
                \Log::debug(print_r($update, true));
            }
            // $household->save();
        }

        return $this->createHouseholdNote($ids['household_id'], $request['user_id']);
    }

    public function createHouseholdNote($householdId, $userId){
    	return HouseholdNotes::create([
            'household_id' => $householdId,
            'user_id'      => $userId,
            'note'         => 'Initial confirmation email sent out.',
            'link'         => sprintf('/#/household/%s/notes-documents?modal=prospectEmail', $householdId)
    	]);
    }

    public function salesHubspot($request)
    {

        // get household by vid
        $household = HouseholdIntegration::with('household')->where('intergration_id', $request['vid'])->where('intergration','hubspot')->first()->toArray();

        if($household){

            $properties = [];
            // filter source type form data
            $properties = array_filter(array_map(function($info){
                            $index = array_search('FORM', array_column($info['versions'], 'source-type'));
                            if(is_numeric($index)){
                                return $info['versions'][$index];
                            }
                        }, $request['properties']));

            // $fill['vid'] = $request['vid'];
            $fill['household_id'] = $household['household_id'];
            $fill['account_id']   = $household['household']['account_id'];

            // field mapping for common one
            foreach ($properties as $key => $value)
            {
                $fieldMap = $this->hubspotPropertiesFieldMapping($key);
                if($fieldMap){
                    $fill[$fieldMap] = $value['value'];
                }
            }

            // firstname lastname mapping
            $fill['client_1_name'] = $this->hubspotPropertiesCommonFieldMapping($properties, 'firstname', 'lastname');
            $fill['client_2_name'] = $this->hubspotPropertiesCommonFieldMapping($properties, 'first_name_person_2', 'last_name_person_2');

            // fill data into analysis table
            HouseholdAnalysis::updateOrCreate(['household_id' => $fill['household_id']], $fill);

            // create analysis notes
            $notesData =  HouseholdNotes::create([
                'household_id' => $household['household_id'],
                'user_id'      => !empty($request['user_id']) ? $request['user_id'] : 1,
                'note'         => 'Paperwork filled out at ' . Carbon::now()->format('m-d-Y H:i:s')
            ]);

            return $notesData;
        }
    }

    public function hubspotPropertiesCommonFieldMapping($data, $field1, $field2)
    {
        if(isset($data[$field1]) || isset($data[$field2]))
        {
            $fname = isset($data[$field1]) ? $data[$field1]['value'] : null;
            $lname = isset($data[$field2]) ? $data[$field2]['value'] : null;
            return trim($fname. ' ' . $lname);
        }
        return '';
    }

    public function hubspotPropertiesFieldMapping($dbField)
    {
        $data = [
            'non_retirement_accounts'                   => 'outside_asset_non_retirement',
            'retirement_savings'                        => 'outside_asset_retirement',
            'cash_in_checking_savings'                  => 'outside_asset_cash',
            'other_investment_accounts'                 => 'outside_asset_other',
            'how_long_until_you_pay_off_your_mortgage_' => 'expense_rent_mortgage',
        ];

        if(isset($data[$dbField])){
            return $data[$dbField];
        } else {
            return '';
        }
    }




    /**
    * [create hubspot prospect into household table]
    * @param  $request [array]
    * @return [object]
    */
    public function createHubspotProspect($request)
    {
        if(!isset($request['properties'])){
           return;
        }

        if (!isset($request['properties']['lastname'])){
            return;
        }

        //see if household already exists by hubspot id
        $integration = HouseholdIntegration::with('household')->where('intergration_id', $request['vid'])->where('intergration','hubspot')->first();

        $properties = $request['properties'];
        $vid       = $request['vid'];

        $email     = $properties['email']['value'];

        if (isset($properties['phone']['value'])){
            $phone  = $properties['phone']['value'];
        }else{


            if (isset($properties['mobilephone']['value'])){
                $phone  = $properties['mobilephone']['value'];
            }else{
                $phone = '000-000-0000';
            }
        }

        $firstName = $properties['firstname']['value'];
        $lastName  = $properties['lastname']['value'];

        if (!$integration){
            $household       = new Households();
            $household->name = $firstName .' '. $lastName;
            $household->org_id = 1;
            $household->status_id = 1;
            $household->prospect = true;
            $household->save();
        }else{
            $household = Households::where('id', $integration->household_id)->first();
        }

        $householdId = $household->id;

        $integration = HouseholdIntegration::updateOrCreate(
            [ 'household_id'    => $householdId, 'intergration' => 'hubspot' ],
            [ 'intergration_id' => $vid ]
        );

        $member  = HouseholdMembers::updateOrCreate([
            'email'        => $email,
            'household_id' => $householdId,
            'first_name'   => $firstName,
            'last_name'    => $lastName,
            'cell_phone'   => $phone,
            'org_id'       => 1,
            'type_id'      => 1
        ]);

        //saving primary member
        $household->primary_member_id = $member->id;
        $household->save();

        event(new AddToGroup($household, 'Unscheduleds'));

        return $household;
    }

    /**
    * [create call task for hubspot contact prospects]
    * @param $task [Array]
    * @return [object]
    */
    public function createCallTask($request){
        $intergration = HouseholdIntegration::where('intergration_id', $request['vid'])
        ->where('intergration', 'hubspot')
        ->first();

        if (!$intergration){
            return false;
        }

        $household = $intergration->household;
        if(!$household){
            return false;
        }

        $benjaminUserId = User::where('name','Benjamin')->value('id');

        $taskType = TaskTypes::where('name', 'Call')->first();
        $task     = Tasks::create([
            'household_id' => $household->id,
            'status_id'    => 1,
            'type_id'      => $taskType->id,
            'title'        => 'Call Task',
            'importance'   => 'active',
            'due_date'     => date('Y-m-d'),
            'user_id'      => $benjaminUserId
        ]);


        $operationsUsers = DB::select("select e.id 'employee_id' from users u
                join user_roles ur on ur.user_id = u.id
                join employees e on e.user_id = u.id
                join roles r on r.id = ur.role_id
                where r.slug = 'operations'");


        foreach ($operationsUsers as $user) {
            TasksAssignment::updateOrCreate([
                'task_id'     => $task->id,
                'employee_id' => $user->employee_id
            ]);
        }

        return $task;
    }

    //add a prospect to hubspot
    public function addProspectToHubspot($household){

        $details['properties'][] = ['property'=>'email', 'value'=>$household->primaryMember->email];
        $details['properties'][] = ['property'=>'firstname', 'value'=>$household->primaryMember->first_name];
        $details['properties'][] = ['property'=>'lastname', 'value'=>$household->primaryMember->last_name];

        $phone = $household->primaryMember->cell_phone ?? $household->primaryMember->home_phone;
        if (!empty($phone)){
            $details['properties'][] = ['property'=>'phone', 'value'=>$phone];
        }

        $contact = $this->hubspot->createContact($details);
        \Log::debug(print_r($details, true));
        \Log::debug('HUBSPOT NEW PROSPECT');
        \Log::debug(print_r($contact, true));

        if (isset($contact->identityProfile)) {
            $hubspot_id = $contact->identityProfile->vid;
        } else {
            $hubspot_id = $contact->vid;
        }


        HouseholdIntegration::updateOrCreate(
            [
                'household_id' => $household->id,
                'intergration' => 'hubspot',
            ],
            [
                'intergration_id'     => $hubspot_id,
            ]
        );

        $update = $this->hubspot->updateContact($hubspot_id, $details);

    }


    public function updateProspectDetailsForMeeting($household, $meeting){

        if (empty($household->householdHubSpotIntegration)){
            //create hubspot contact first
            $this->addProspectToHubspot($household);
        }

        $details['properties'] = [];

        $dateTime = Carbon::parse($meeting->start);

        $time = Carbon::parse($dateTime->format('Y-m-d'));
        $time->setTimezone('UTC');

        //for stupid daylight savings
        if ($time >= '2019-03-10' && $time <= '2019-11-03'){
            $time = $time->addHours(-4);
        }else{
            $time = $time->addHours(-5);
        }

        $time = ['property'=>'date_and_time_of_prospect_appointment', 'value'=> $time->timestamp*1000];


        $leadAdvisor = $meeting->users->first();

        $advisor = ['property'=>'prospect_advisor', 'value'=>strtolower($this->split_name($leadAdvisor->name)[0])];

        $advisor_email = ['property'=>'prospect_advisor_emails', 'value'=> $leadAdvisor->email];

        //$meeting_place = $meeting->location;

        $location = ['property'=>'meeting_location', 'value'=>$meeting->address];


        $details['properties'][] = $time;
        $details['properties'][] = $advisor;
        $details['properties'][] = $location;
        $details['properties'][] =  $advisor_email;
        $details['properties'][] = ['property'=>'time_of_prospect_appointment', 'value'=>$dateTime->format('g:i A')];
        $details['properties'][] =  ['property'=>'scheduled_appointment_tracking', 'value'=>true];
        $details['properties'][] =  ['property'=>'scheduled_appointment', 'value'=>true];


        $details['properties'][] = ['property'=>'working_with_financial_advisor', 'value'=>$household->money_managed];

        $goal = strtolower($household->goal);

        if ($goal == 'donot-know'){
            $goal = "i_don't_know";
        }elseif ($goal == 'generate-income'){
            $goal = 'generate_income';
        }


        $details['properties'][]  = ['property'=>'goal_with_your_investments', 'value'=>$goal];
        $details['properties'][]  = ['property'=>'when_do_you_plan_to_retire', 'value'=>$household->year];
        $details['properties'][]  = ['property'=>'lead_flow_estimate_of_investable_assets', 'value'=>$household->estimate_value];

        $referred = $household->cia_referred;

        if ($referred == 1){
            $referred = true;
        }else{
            $referred = false;
        }

        $details['properties'][]  = ['property'=>'referred_to_cia', 'value'=>$referred];


        $sourceId = $household->source_id;
        $source = Sources::where('id', $sourceId)->first();

        if (empty($source)){
            $details['properties'][]  = ['property'=>'source', 'value'=>'referral'];
        }else{
            $details['properties'][]  = ['property'=>'source', 'value'=>strtolower($source->name)];
        }


        $channelId = $household->channel_id;
        $channel = Channel::where('id', $channelId)->first();

        if (empty($channel)){
            $details['properties'][]  = ['property'=>'channel', 'value'=>'referral'];
        }else{
            $details['properties'][]  = ['property'=>'channel', 'value'=>strtolower($channel->name)];
        }



        \Log::debug('HUBSPOT WEBHOOK UPDATE:'.$household->householdHubSpotIntegration);
        \Log::debug(print_r($details, true));


        //update demographic stuff
        $details['properties'][] = ['property'=>'email', 'value'=>$household->primaryMember->email];
        $details['properties'][] = ['property'=>'firstname', 'value'=>$household->primaryMember->first_name];
        $details['properties'][] = ['property'=>'lastname', 'value'=>$household->primaryMember->last_name];


        $phone = $household->primaryMember->cell_phone ?? $household->primaryMember->home_phone;
        if (!empty($phone)){
            $details['properties'][] = ['property'=>'phone', 'value'=>$phone];
        }

        $val = $this->hubspot->updateContact($household->householdHubSpotIntegration->intergration_id, $details);

        if (!empty($val)){
            Mail::raw(print_r($val, true), function($m)
            {
                $m->to('hesom@getwela.com');
                $m->subject('Hubspot Webhook - ERROR');
            });
        }


        \Log::debug('HUBSPOT WEBHOOK RESULT:'.$household->householdHubSpotIntegration->intergration_id);
        \Log::debug(print_r($val, true));


        Mail::raw(print_r($details, true), function($m)
        {
            $m->to('hesom@getwela.com');
            $m->subject('Hubspot Webhook Update');
        });


    }


    public function updateHubspotProspect($household){

        if (empty($household->householdHubSpotIntegration)){
            return;
        }


        $details['properties'] = [];

        $details['properties'][] = ['property'=>'email', 'value'=>$household->primaryMember->email];
        $details['properties'][] = ['property'=>'firstname', 'value'=>$household->primaryMember->first_name];
        $details['properties'][] = ['property'=>'lastname', 'value'=>$household->primaryMember->last_name];
        $details['properties'][] = ['property'=>'phone', 'value'=>$household->primaryMember->home_phone];

        $val = $this->hubspot->updateContact($household->householdHubSpotIntegration->intergration_id, $details);

    }

    function split_name($name) {
        $name = trim($name);
        $last_name = (strpos($name, ' ') === false) ? '' : preg_replace('#.*\s([\w-]*)$#', '$1', $name);
        $first_name = trim( preg_replace('#'.$last_name.'#', '', $name ) );
        return array($first_name, $last_name);
    }


    public function updateProspectInProgress($household){

        if (empty($household->householdHubSpotIntegration)){
            return;
        }

        $details['properties'] = [];

        $details['properties'][] = ['property'=>'scheduled_appointment', 'value'=>false];
        $details['properties'][] =  ['property'=>'scheduled_appointment_tracking', 'value'=>false];

        \Log::debug('HUBSPOT WEBHOOK UPDATE:'.$household->householdHubSpotIntegration->intergration_id);
        \Log::debug(print_r($details, true));


        $val = $this->hubspot->updateContact($household->householdHubSpotIntegration->intergration_id, $details);

        \Log::debug('HUBSPOT WEBHOOK RESULT:'.$household->householdHubSpotIntegration->intergration_id);
        \Log::debug(print_r($val, true));
    }


    /**
    * add notes for single/multiple contacts
    *
    * @param array|mixed $integrationID
    * @param array $note
    */
    public function addNotes($integrationID, $note, $other = [])
    {
        $integration = Integration::where('type', 'hubspot')->first();
        if (!$integration) {
            return false;
        }

        $content = '';
        if (isset($note['title']) && $note['title'] != '') {
            $content .= sprintf('%s%s', $note['title'], "\n");
            $content .= sprintf('%s', $note['note']);
        } else {
            $content .= isset($note['note']) ? sprintf('%s', $note['note']) : '';
        }

        $data = [
            'associations' => [
                'contactIds' => is_array($integrationID) ? $integrationID : explode(' ', $integrationID)
            ],
            'metadata' => [
                'body' => $content
            ]
        ];

        $val = $this->hubspot->addEngagements($data);
        return $val;
    }

    /*
    * refresh access token
    */
    public function refreshAccessToken()
    {
        return true;
    }

    /**
    * create/update hubspot integration
    * @param array $request
    * @param int $userId
    * @return \Illuminate\Database\Eloquent\Model
    */
    public function makeIntegration($request, $userId)
    {
        $setting = IntegrationsSettings::where('integration', 'hubspot')->first();
        $request['api_key'] = $setting->api_key;

        Integration::updateOrCreate([
            'id' => $request['id'],
        ],[
            'user_id' => $userId,
            'type'    => $request['integration'],
            'api_key' => $request['api_key'],
            'auth_url' => $setting->auth_url,
            'access_token_url' => $setting->access_token_url,
            'active'  => 1
        ]);

        return true;
    }

    /**
    * get all contacts for hubspot CRM
    *
    * @param  int $integrationId
    * @return void
    */
    public function getAllContacts($integrationId = null)
    {
        $offset  = null;
        $hasmore = true;

        $lastMeeting = $this->settingsRepository->getInstanceSetting('crm:hubspot:last_meeting');
        $advisor_1   = $this->settingsRepository->getInstanceSetting('crm:hubspot:advisor_1');

        //if has more contact
        while ($hasmore) {
            $data = $this->hubspot->getAllContacts($offset);

            //loop contacts
            foreach ($data->contacts as $contact) {

                if (property_exists($contact, 'vid')) {

                    //check household integration for that contact
                    $checkIntegration = ['intergration_id' => $contact->vid, 'intergration' => 'hubspot'];
                    $integration      = HouseholdIntegration::firstOrCreate($checkIntegration);

                    //find if household or create new household for contact
                    $household           = Households::firstOrNew(['id' => $integration->household_id]);
                    $household->org_id   = 1;
                    $household->prospect = 1;

                    $firstname = '';
                    $lastname  = '';

                    //check property exists in contact
                    if (property_exists($contact, 'properties')) {
                        $name   = [];

                        $properties      = $contact->{'properties'};
                        $firstnameExists = (property_exists($properties, 'firstname'));
                        $lastnameExists  = (property_exists($properties, 'lastname'));

                        if ($firstnameExists && property_exists($properties->firstname, 'value')) {
                            $name[] = $firstname = $contact->{'properties'}->firstname->value;
                        }

                        if ($lastnameExists && property_exists($properties->lastname, 'value')) {
                            $name[] = $lastname = $contact->{'properties'}->lastname->value;
                        }

                        $household->name = implode(' ', $name);
                    }

                    //assign dynamic advisor to household
                    if ( property_exists($contact->properties, $advisor_1) && $contact->properties->$advisor_1->value !== '') {
                        $advisor = Advisors::where('name', $contact->properties->$advisor_1->value)->first();
                        if ($advisor) {
                            $household->advisor_1_id = $advisor->id;
                        } else {
                            $household->advisor_1_id = NULL;
                        }
                    } else {
                        $household->advisor_1_id = NULL;
                    }

                    $household->save();

                    $integration->household_id = $household->id;
                    $integration->save();

                    //check household member integration for that contact
                    $memberIntegration = HouseholdMembersIntegrations::firstOrCreate($checkIntegration);

                    //find household member or create new household member for that household
                    $member = HouseholdMembers::firstOrNew(['id' => $memberIntegration->household_member_id]);

                    $member->household_id = $household->id;
                    $member->org_id       = 1;
                    $member->type_id      = 1;
                    $member->first_name   = $firstname;
                    $member->last_name    = $lastname;

                    //check email and update for household member
                    if (property_exists($contact, 'identity-profiles')) {

                        $profiles = $contact->{'identity-profiles'};

                        foreach ($profiles as $profile) {

                            if ($profile->vid == $contact->vid) {

                                if (property_exists($profile, 'identities')) {
                                    $identities = Helper::object_to_array($profile->identities);
                                    $identity   = array_filter($identities, function($info){ return ($info['type'] == 'EMAIL' && !empty($info['is-primary'])); });

                                    if (!empty($identity)) {
                                        $member->email = current($identity)['value'];
                                    }
                                }
                            }
                        }
                    }

                    $member->save();

                    $memberIntegration->household_member_id = $member->id;
                    $memberIntegration->save();

                    $household->primary_member_id = $member->id;
                    $household->save();
                }
            }

            $hasmore = ($data->{'has-more'} == true) ?? false;
            $offset  = ($data->{'vid-offset'}) ?? null;
        }
    }

    public function addClientsToHubspot(){


            $clients = DB::select("
                select distinct hm.household_id, hm.first_name, hm.last_name, hm.email, COALESCE(replace(hm.cell_phone, '-', ''), replace(hm.home_phone, '-', '')) 'phone',
                aa.name 'advisor', ua.email 'advisor_email' ,       h.address_1, h.city, h.state, h.zip
                from households h
                join household_members hm on h.id = hm.household_id
                join advisors aa on aa.id = h.advisor_1_id
                join users ua on ua.id = aa.user_id
            where h.status_id <> 16
            and ifnull(h.prospect, 0) = 0
            and h.id not in (select household_id From households_integrations where intergration = 'hubspot')
            and hm.first_name <> ''
            and hm.email not in ('', 'none')
            and hm.email like '%@%'
            order by hm.household_id

            ");


            foreach($clients as $client){

                $details['properties'] = [];

                $details['properties'][] = ['property'=>'lifecyclestage', 'value'=>'customer'];
                $details['properties'][] = ['property'=>'email', 'value'=>trim(strtolower($client->email))];
                $details['properties'][] = ['property'=>'firstname', 'value'=>trim(ucwords(strtolower($client->first_name)))];
                $details['properties'][] = ['property'=>'lastname', 'value'=>trim(ucwords(strtolower($client->last_name)))];
                $details['properties'][] = ['property'=>'phone', 'value'=>trim($client->phone)];
                $details['properties'][] = ['property'=> 'benjamin_client_import', 'value'=> true];

                $details['properties'][] = ['property'=> 'address', 'value'=> trim(ucwords(strtolower($client->address_1)))];
                $details['properties'][] = ['property'=> 'city', 'value'=> trim(ucwords(strtolower($client->city)))];
                $details['properties'][] = ['property'=> 'state_abbreviation', 'value'=> $client->state];
                $details['properties'][] = ['property'=> 'zip', 'value'=> $client->zip];

                $advisorForHubspot = strtolower($this->split_name($client->advisor)[0]);

                if (in_array($client->advisor_email,['mdelagarza@yourwealth.com'])){
                    $advisorForHubspot = 'michael_de_la_garza';
                }

                $details['properties'][] = ['property'=>'advisor', 'value'=>$advisorForHubspot];

                $contact = $this->hubspot->createContact($details);

                //this means contact is already in the system, do an update
                if (isset($contact->identityProfile)) {
                    $hubspot_id = $contact->identityProfile->vid;

                    //update
                    $contact = $this->hubspot->updateContact($hubspot_id, $details);
                } else {
                    $hubspot_id = $contact->vid;
                }


                HouseholdIntegration::updateOrCreate(
                    [
                        'household_id' => $client->household_id,
                        'intergration' => 'hubspot',
                    ],
                    [
                        'intergration_id'     => $hubspot_id,
                    ]
                );


            }


    }

    /**
    * get hubspot CRM tasks
    *
    * @return void
    */
    public function getTasks()
    {
        $integration = Integration::where(['type' => 'hubspot', 'active' => 1])->first();

        if (empty($integration)) {
            return false;
        }

        $tasks = [];

        $offset  = null;
        $hasmore = true;

        //if has more tasks
        while ($hasmore) {

            $hasmore = false;
            $data = $this->hubspot->getTasks($offset);
            if (property_exists($data, 'results')) {

                $collections = json_decode(json_encode($data), true);
                $results = [];

                //loop engagements with contacts
                foreach ($collections['results'] as $collection) {

                    if (array_key_exists('type', $collection['engagement']) && $collection['engagement']['type'] == 'TASK') {
                        $results[] = $collection;
                    }
                }

                $tasks = array_merge($tasks, $results);

                $tasks =  array_map(function($task) use($integration){
                        $task['userId'] = $integration->user_id;
                        return $task;
                }, $tasks);

                $hasmore = ($data->{'hasMore'} == true) ?? false;
                $offset  = ($data->{'offset'}) ?? null;
            }
        }

        // if tasks exits
        if (!empty($tasks)) {
            $this->crmRepository->updateOrCreateTask($tasks, 'hubspot');
        }
    }

    public function setTasksToCompleted($taskId, $integration = null)
    {
        $task = Tasks::find($taskId);

        if(empty($task)){
            return false;
        }

        if($task->integration == 'hubspot' && !empty($task->integration_id)){

            $details = [
                'metadata' => [
                    'status' => 'COMPLETED'
                ]
            ];

            $data = $this->hubspot->updateTask($task->integration_id, $details);

            return true;
        }
    }
}
