<?php
namespace App\Repositories\CRM;

use DB;
use App\Services\Salesforce;
use App\Models\Households;
use App\Models\HouseholdMembers;
use App\Models\HouseholdIntegration;
use App\Models\HouseholdMembersIntegrations;
use Mail;
use App\Models\Integration;
use Redirect;
use App\Models\HouseholdsAccountReviewProcess;
use App\Models\HouseholdMeetings;
use Carbon\Carbon;
use App\Repositories\Conversation\ConversationRepository;
use App\Repositories\Settings\SettingsRepository;
use App\Models\ConversationLibrary;
use App\Models\Advisors;
use App\Repositories\CRM\CRMRepository;
use App\Events\AddToGroup;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\Exception\UnsatisfiedDependencyException;
use App\Models\HouseholdMemberTypes;
use App\Models\Tasks;
use phpDocumentor\Reflection\Types\Integer;

class SalesForceRepository
{
    public function __construct(
        Salesforce $salesforce,
        CRMRepository $crmRepository,
        ConversationRepository $conversationRepository,
        SettingsRepository $settingsRepository
    ) {
        $this->org_id = 1;
        $this->salesforce = $salesforce;
        $this->crmRepository = $crmRepository;
        $this->conversationRepository = $conversationRepository;
        $this->integration_name = 'salesforce';
        $this->settingsRepository = $settingsRepository;
    }

    public function getVersions()
    {
        return $this->salesforce->get();
    }

    //creating a new Account
    public function createAccountRecord()
    {
    	return $this->salesforce->create('Account/', [
    		'Name' => 'Express Logistics and Transport'
    	]);
    }

    //updating an Account object
 	public function updateAccountRecord($id = null, array $params=[])
    {
    	return $this->salesforce->update('Account/', $id, $params);
    }

	//retrieving values from fields on an Account object
 	public function getAccountRecord($id = null)
    {
        return $this->salesforce->getById('Account/', $id);
    }

    //retrieving values from fields on an Account object
 	public function getContactRecord($id = null)
    {
         return $this->salesforce->getById('Contact/', $id);
    }


    public function createContact($request, $parentID = null, $recordTypeId = null)
    {
        $data = [
            'FirstName' => $request->first_name,
            'LastName'  => $request->last_name,
            'Email'     => $request->email,
            'Phone'     => isset($request->cell_phone) ? $request->cell_phone : $request->home_phone,
            'AccountId' => $parentID
        ];

        if (!empty($recordTypeId)){
            $data['RecordTypeId'] = $recordTypeId;
        }

        return $this->salesforce->create('Contact/', $data);
    }

    public function updateContact($request, $parentID = null, $id = null)
    {
        $data = [
            'FirstName' => $request->first_name,
            'LastName'  => $request->last_name,
            'Email'     => $request->email,
            'Phone'     => isset($request->cell_phone) ? $request->cell_phone : $request->home_phone,
            'AccountId' => $parentID
        ];

        return $this->salesforce->update('Contact/', $id, $data);
    }


    public function updateContactGeneric($id, $data)
    {
        return $this->salesforce->update('Contact/', $id, $data);
    }


    public function updateAccountGeneric($id, $data)
    {
        return $this->salesforce->update('Account/', $id, $data);
    }

  /**
     * Create client into salesforce
     * @return object
     */
    public function updateClient($id, $request)
    {
        $data = [
            'Name'  => ucwords(strtolower($request->formatted_name)),
            'Phone' => $request->cell_phone ?? $request->home_phone,
        ];

        if (!empty($request->address_1)){
            $data['BillingStreet'] = ucwords(strtolower($request->address_1));
        }

        if (!empty($request->city)){
            $data['BillingCity'] = ucwords(strtolower($request->city));
        }

        if (!empty($request->state)){
            $data['BillingState'] = ucwords(strtolower($request->state));
        }

        if (!empty($request->zip)){
            $data['BillingPostalCode'] = $request->zip;
        }

        return $this->salesforce->update('Account/', $id, $data);
    }


    /**
     * Create client into salesforce
     * @return object
     */
    public function createClient($request, $type='Prospect', $recordTypeId = null)
    {
        $data = [
            'Name'  => ucwords(strtolower($request->formatted_name)),
            'Phone' => $request->cell_phone ?? $request->home_phone,
        ];

        $data['Type'] = $type;

        if (!empty($recordTypeId)){
            $data['RecordTypeId'] = $recordTypeId;
        }

        if (!empty($request->address_1)){
            $data['BillingStreet'] = ucwords(strtolower($request->address_1));
        }

        if (!empty($request->city)){
            $data['BillingCity'] = ucwords(strtolower($request->city));
        }

        if (!empty($request->state)){
            $data['BillingState'] = ucwords(strtolower($request->state));
        }

        if (!empty($request->zip)){
            $data['BillingPostalCode'] = $request->zip;
        }

        return $this->salesforce->create('Account/', $data);
    }

    /**
     * create household into the salesforce
     * @return void
     */
    public function createHouseholdToSalesforce($instance)
    {
        $subQuery = DB::table('households_integrations')
        ->where('intergration', 'salesforce')
        ->pluck('household_id')
        ->toArray();
        //for cornercap, add clients to salesforce
        if ($instance == 'e683510d-93df-4afc-ac32-50fcf2cb5eed'){
            $type = 'MAP Client';

            //MAP Client in Salesforce
            $clientRecordTypeId = '012440000006GrvAAE';
            $contactRecordTypeId = '0122S0000006GwbQAE';

            $households = Households::select('households.*', 'household_members.cell_phone', 'household_members.home_phone')
            ->whereNotIn('households.id', $subQuery)
            ->join('household_members', function ($join) {
                $join->on('households.primary_member_id', '=', 'household_members.id');
            })
            ->groupBy('households.id')
            ->get();
        }else{
            $type = 'Prospect';
            $clientRecordTypeId = null;
            $contactRecordTypeId = null;


            $households = Households::select('households.*', 'household_members.cell_phone', 'household_members.home_phone', 'household_members.id')
            ->whereNotIn('households.id', $subQuery)
            ->join('household_members', function ($join) {
                $join->on('households.primary_member_id', '=', 'household_members.id');
            })
            ->where('households.prospect', true)
            ->groupBy('households.id')
            ->get();

        }

        foreach ($households as $household) {
            if ($household) {
                $client = $this->createClient($household, $type, $clientRecordTypeId);
                HouseholdIntegration::firstOrCreate([
                    'household_id' => $household->id,
                    'intergration' => 'salesforce'
                ], [
                    'intergration_id' => $client->id
                ]);


                if ($household->householdMembers->count() > 0) {
                    foreach ($household->householdMembers as $k => $member) {
                        $contact = $this->createContact($member, $client->id, $contactRecordTypeId);
                        HouseholdMembersIntegrations::firstOrCreate([
                            'household_member_id' => $member->id,
                            'intergration' => 'salesforce'
                        ], [
                            'intergration_id' => $contact->id
                        ]);
                    }
                }
            }
        }
    }

    public function updateHouseholdToSalesforce($instance)
    {

        if ($instance == 'e683510d-93df-4afc-ac32-50fcf2cb5eed'){
            $type = 'MAPClient';
        }else{
            $type = 'Prospect';
        }

        $subQuery = DB::table('households_integrations')
                    ->where('intergration', 'salesforce')
                    ->pluck('household_id')
                    ->toArray();

        $households = Households::select('households.*', 'accounts.address_1')
                    ->whereIn('households.id', $subQuery)
                    ->leftjoin('accounts', function ($join) {
                        $join->on('households.id', '=', 'accounts.household_id');
                    })
                    ->where('households.prospect', true)
                    ->groupBy('households.id')
                    ->get();

        foreach ($households as $household) {
            if ($household) {

                $client = $this->updateClient($household->householdSalesforceIntegration->intergration_id, $household, $type);

            }
        }

    }

    /**
    * @param $entity
    * @param $field
    * @param $value
    * @return mixed
    */
    public function searchObject($entity, $field, $value)
    {
        return $this->salesforce->search($entity, $field, $value);
    }


    /**
    * Send email when new client has been added to the salesforce
    * @param  $household
    * @return void
    */
    public function sendMail($client)
    {
        $data = $this->salesforce->getById('Client/', $client->id);
        Mail::send('email.clientAddedToSalesforce', ['data' => $data], function ($m) {
            $m->from('benjamin@getwela.com', 'Benjamin');
            $m->to('nick@getwela.com');
            $m->subject('New client has been added to SalesForce');
        });
    }

    public function getToken($request)
    {
        $callback  = Integration::where('id', decrypt($request['state']))->first();
        if ($callback) {
            $postRequest = array_merge($callback->toArray(), $request);

            //get access token from salesforce using code
            $token       = $this->salesforce->getAccessToken($postRequest);
            if (isset($token->access_token)) {
                $callback->access_token  = $token->access_token;
                $callback->refresh_token = $token->refresh_token;
                $callback->active        = 1;
                $callback->save();
                return true;
            }
        }
        return false;
    }

    /**
    * [refresh token for salesforce api and update integration record]
    * @param  $id [integration model id]
    * @return object
    */
    public function refreshAccessToken($integrationId = null)
    {
        $integrations = Integration::where('type', 'salesforce');
        if ($integrationId) {
            $integrations->where('id', $integrationId);
        }

        $integrations = $integrations->get();
        if ($integrations->count() > 0) {

            foreach ($integrations as $integration) {
                if ($integration->refresh_token != '') {
                    $token = $this->salesforce->refreshAccessToken($integration);

                    if (isset($token->access_token)) {
                        $integration->access_token  = $token->access_token;
                        $integration->active        = 1;
                        $integration->save();
                    }
                }
            }

            return true;
        }

        return false;
    }

    public function getRecords($limit = 25, $offset, $entity, $listviewsID = ''){
        return $this->salesforce->getRecords($limit, $offset, $entity, $listviewsID);
    }

    public function countRecords($entity){
        return $this->salesforce->countRecords($entity);
    }

    public function getContactById($entity, $contactId){
        return $this->salesforce->getById($entity, $contactId);
    }

    public function getContactByAccount($entity ,$query){
        return $this->salesforce->getContactByAccount($entity, $query);
    }
    
    /**
     * Search salesforce for the given term.
     * @param string $search_term
     * @return object|mixed
     */
    public function quickSearch(string $search_term) {
        return $this->salesforce->searchQuery($search_term);
    }

    public function getContacts($data, $nextUrl= null, $id = null)
    {
        $appendText = '';

        if($id){
            $appendText .= "where Id = '".$id."'";
        } else {
            $appendText .= "";
        }

        //pull list of updated/created records
        $updated_contacts = $this->getUpdatedRecords('Contact', 2);
        
        $contact_list = implode("','", $updated_contacts);

        if (empty($nextUrl)){
            $queryResults = $this->salesforce->getCustomQuery('Account', "select id, name, (select id, name, email, phone, MobilePhone, FirstName, LastName, Birthdate, LastModifiedDate from Contacts where id IN('$contact_list')) from account $appendText");
            
        }else{
            $queryResults = $this->salesforce->getData($nextUrl);
        }

        if (isset($queryResults->records)){
            $data = array_merge($data, $queryResults->records);
        }

        if (isset($queryResults->nextRecordsUrl)){
            $data = $this->getContacts($data, $queryResults->nextRecordsUrl);
        }
        
        return $data;
    }

    /**
     * No limit contact pull by account
     * @param array $data
     * @param string $nextUrl
     * @param $id
     * @return array
     */
    public function getUnlimitedContacts(array $data, string $nextUrl= null, $id = null)
    {
        $appendText = '';
        
        if($id){
            $appendText .= "where Id = '".$id."'";
        } else {
            $appendText .= "";
        }
        
        if (empty($nextUrl)){
            $queryResults = $this->salesforce->getCustomQuery('Account', "select id, name, (select id, name, email, phone, MobilePhone, FirstName, LastName, Birthdate from Contacts) from account $appendText");
        }else{
            $queryResults = $this->salesforce->getData($nextUrl);
        }
        
        if ($queryResults->records){
            $data = array_merge($data, $queryResults->records);
        }
        
        if (isset($queryResults->nextRecordsUrl)){
            $data = $this->getUnlimitedContacts($data, $queryResults->nextRecordsUrl);
        }
        
        
        return $data;
    }
    
    /**
     * Get a list of updated/created entity ids for the given number of days.
     * Returns an object->ids[array] of updated or created id's of the entity given for the number of days specified.
     * @param string $entity -- [Contact],[Account],[Task]
     * @param int $days
     * @return array 
     */
    public function getUpdatedRecords($entity='Contact', int $days=2)
    {
        $date = Carbon::now()->subDays($days);
        $start = $date->format(\DateTime::ISO8601);
        $end = Carbon::now()->format(\DateTime::ISO8601);
     
        $query = "/?start={$start}&end={$end}";
        
        $queryResults=$this->salesforce->getUpdatedRecords($entity, $query);
        
        return array_unique($queryResults->ids);
    }
    
    /**
     * Pull contacts iteratively until we reach the total.
     * @param string $account_id
     * @param int $total
     * @return array
     */
    public function getMasterContacts(string $account_id, int $total)
    {
        $limit      = 1500;
        $failsafe   = 50; //75k records
        $processed  = 0;
        $data       = [];
        $where      = '';
        
        while ($processed < $total && $failsafe>0) {
            $queryResults = $this->salesforce->getCustomQuery('Contact', "select id, name, email, phone, MobilePhone, FirstName, LastName, Birthdate, AccountId, CreatedDate from Contact WHERE AccountId='$account_id' $where ORDER BY CreatedDate ASC limit $limit");

            if ($queryResults->records){
                foreach($queryResults->records as $record) {
                    $data[$record->Id] = $record;
                }
            }
            
            while (isset($queryResults->nextRecordsurl)) {
                $queryResults = $this->salesforce->getData($queryResults->nextRecordsurl);
                
                if ($queryResults->records){
                    foreach($queryResults->records as $record) {
                        $data[$record->Id] = $record;
                    }
                }
            }
            
            $processed = count($data); //count contacts we currently have in $data
            $date = end($data)->CreatedDate;
            $date = substr($date, 0,19);
            
            $where = " AND CreatedDate>={$date}-00:00";          
            $failsafe--;
        }
               
        return array_values($data);
    }
    
    /**
     * Master Account/Contact pull - typically first integration pull.
     * Cycle accounts when they have more than 2k contacts as SF limit is 2k on pulls.
     * This function also does everything: create housholds, which is should not, members, which it should not
     * it even deals with primary members and advisors,.. which it should not.. but... w/e
     * 
     * We have added the concept of super-accounts, which are accounts over 2k contacts that will be cycled differently
     * and then merged into the main records data before being returned.
     */
    public function getMasterAccounts()
    {
        $data = [];
        $records = $this->getUnlimitedContacts($data);
        
        if(!empty($records)){
            
            $lastMeeting = $this->settingsRepository->getInstanceSetting('crm:salesforce:last_meeting');
            $advisor_1   = $this->settingsRepository->getInstanceSetting('crm:salesforce:advisor_1');
            
            for($i=0; $i<=count($records)-1; $i++){
                $record = $records[$i];
                                
                // skipping the record for no household name -- Guardian instance
                if($record->Name == 'No Household Name Assigned'){
                    continue;
                }
                                
                $householdExists   = HouseholdIntegration::where('intergration', 'salesforce')
                ->where('intergration_id', $record->Id)
                ->first();
                
                if ($householdExists) {
                    $household = Households::find($householdExists->household_id);
                    if(!$household || is_null($household)) {
                        $household = new Households();
                        $household->org_id   = 1;
                        $household->prospect = 1;
                        $household->name     = $record->Name;
                        
                        //assign dynamic advisor to household
                        if ( property_exists($record, $advisor_1) && $record->$advisor_1 !== '') {
                            $advisor = Advisors::where('name', $record->$advisor_1)->first();
                            if ($advisor) {
                                $household->advisor_1_id = $advisor->id;
                            } else {
                                $household->advisor_1_id = NULL;
                            }
                        } else {
                            $household->advisor_1_id = NULL;
                        }
                        
                        $household->save();
                        $household->refresh();
                        
                        HouseholdIntegration::firstOrCreate([
                            'household_id' => $household->id,
                            'intergration' => 'salesforce'
                        ], [
                            'intergration_id' => $record->Id
                        ]);
                        
                        event(new AddToGroup($household, 'Active Prospects'));
                    }
                } else {
                    $household = new Households();
                    $household->org_id   = 1;
                    $household->prospect = 1;
                    $household->name     = $record->Name;
                    
                    //assign dynamic advisor to household
                    if ( property_exists($record, $advisor_1) && $record->$advisor_1 !== '') {
                        $advisor = Advisors::where('name', $record->$advisor_1)->first();
                        if ($advisor) {
                            $household->advisor_1_id = $advisor->id;
                        } else {
                            $household->advisor_1_id = NULL;
                        }
                    } else {
                        $household->advisor_1_id = NULL;
                    }
                    
                    $household->save();
                    
                    HouseholdIntegration::firstOrCreate([
                        'household_id' => $household->id,
                        'intergration' => 'salesforce'
                    ], [
                        'intergration_id' => $record->Id
                    ]);
                    
                    event(new AddToGroup($household, 'Active Prospects'));
                    
                }
                
                //loop contacts
                if (empty($record->Contacts)){
                    continue;
                }
                
                $primaryMemberId = null;
                
                // super account?
                if($record->Contacts->totalSize > 1998) {
                    
                    $contacts = $this->getMasterContacts($record->Id, (int)$record->Contacts->totalSize);
                    $record->Contacts->records = $contacts;
                    $record->Contacts->done=1;
                }
                
                foreach($record->Contacts->records as $value){
                    
                    $memberExists = HouseholdMembersIntegrations::where('intergration', 'salesforce')
                    ->where('intergration_id', $value->Id)
                    ->first();
                    
                    if(!empty($memberExists)){
                        $member = HouseholdMembers::findOrNew($memberExists->household_member_id);
                    } else {
                        $member = new HouseholdMembers();
                        
                        if(!$household || is_null($household) || empty($household)){
                            print_r($member->id);
                        }
                        
                        $member->org_id       = 1;
                        $member->type_id      = 1;
                        $member->first_name   = $value->FirstName;
                        $member->last_name    = $value->LastName;
                        $member->cell_phone   = $value->MobilePhone;
                        $member->home_phone   = $value->Phone;
                        $member->email        = $value->Email;
                        $member->dob          = ($value->Birthdate) ?? null;
                        $member->household_id = $household->id;
                        $member->save();
                        
                        if (empty($household->primary_member_id)){
                            $household->primary_member_id  = $member->id;
                            $household->save();
                        }
                        
                        HouseholdMembersIntegrations::firstOrCreate([
                            'household_member_id' => $member->id,
                            'intergration'        => 'salesforce'
                        ], [
                            'intergration_id'     => $value->Id
                        ]);
                    }
                    
                    if(!$household || is_null($household) || empty($household)){
                        print_r($member->id);
                        
                    }
                    
                    $member->org_id       = 1;
                    $member->type_id      = 1;
                    $member->first_name   = $value->FirstName;
                    $member->last_name    = $value->LastName;
                    $member->cell_phone   = $value->MobilePhone;
                    $member->home_phone   = $value->Phone;
                    $member->email        = $value->Email;
                    $member->dob          = ($value->Birthdate) ?? null;
                    $member->household_id = $household->id;
                    $member->save();
                    
                    if (empty($household->primary_member_id)){
                        $household->primary_member_id = $member->id;
                        $household->save();
                    }
                    
                    if(!$memberExists){
                        HouseholdMembersIntegrations::firstOrCreate([
                            'household_member_id' => $member->id,
                            'intergration'        => 'salesforce'
                        ], [
                            'intergration_id'     => $value->Id
                        ]);
                    }
                    
                    //make Household Head as primary
                    if(property_exists($value, 'Level__c') && $value->Level__c == 'Primary'){
                        $primaryMemberId = $member->id;
                    }
                    
                }
                
                if ($primaryMemberId || empty($household->primary_member_id)){
                    $household->primary_member_id = ($primaryMemberId) ?? $member->id;
                    $household->save();
                }
                
                //save last meeting
                if($lastMeeting && !empty($record->$lastMeeting)){
                    $this->crmRepository->createHouseholdMeetings($household->id, $member->id, $record->$lastMeeting);
                }
                
            }
        }
        
        return $records;
    }
 
    // pull accounts/contacts from salesforce and insert/update to household/household-member
    /**
     * Gets accounts/contacts that have been updated or created in the last x days 
     * Also insert/update to household/household-member.
     * @return array
     */
    public function getAllContacts()
    {
        $data = [];
        $records = $this->getContacts($data);
        
        if(!empty($records)){

            $lastMeeting = $this->settingsRepository->getInstanceSetting('crm:salesforce:last_meeting');
            $advisor_1   = $this->settingsRepository->getInstanceSetting('crm:salesforce:advisor_1');

            for($i=0; $i<=count($records)-1; $i++){
                $record = $records[$i];

                // skipping the record for no household name -- Guardian instance
                if($record->Name == 'No Household Name Assigned'){
                    continue;
                }

                $householdExists   = HouseholdIntegration::where('intergration', 'salesforce')
                ->where('intergration_id', $record->Id)
                ->first();

                if ($householdExists) {
                    $household = Households::find($householdExists->household_id);
                    if(!$household || is_null($household)) {
                        $household = new Households();
                        $household->org_id   = 1;
                        $household->prospect = 1;
                        $household->name     = $record->Name;

                        //assign dynamic advisor to household
                        if ( property_exists($record, $advisor_1) && $record->$advisor_1 !== '') {
                            $advisor = Advisors::where('name', $record->$advisor_1)->first();
                            if ($advisor) {
                                $household->advisor_1_id = $advisor->id;
                            } else {
                                $household->advisor_1_id = NULL;
                            }
                        } else {
                            $household->advisor_1_id = NULL;
                        }

                        $household->save();
                        $household->refresh();

                        HouseholdIntegration::firstOrCreate([
                            'household_id' => $household->id,
                            'intergration' => 'salesforce'
                        ], [
                            'intergration_id' => $record->Id
                        ]);

                        event(new AddToGroup($household, 'Active Prospects'));                        
                    }
                } else {
                    $household = new Households();
                    $household->org_id   = 1;
                    $household->prospect = 1;
                    $household->name     = $record->Name;

                    //assign dynamic advisor to household
                    if ( property_exists($record, $advisor_1) && $record->$advisor_1 !== '') {
                        $advisor = Advisors::where('name', $record->$advisor_1)->first();
                        if ($advisor) {
                            $household->advisor_1_id = $advisor->id;
                        } else {
                            $household->advisor_1_id = NULL;
                        }
                    } else {
                        $household->advisor_1_id = NULL;
                    }

                    $household->save();

                    HouseholdIntegration::firstOrCreate([
                        'household_id' => $household->id,
                        'intergration' => 'salesforce'
                    ], [
                        'intergration_id' => $record->Id
                    ]);

                    event(new AddToGroup($household, 'Active Prospects'));

                }

                //loop contacts
                if (empty($record->Contacts)){
                    continue;
                }

                $primaryMemberId = null;
                foreach($record->Contacts->records as $value){

                    $memberExists = HouseholdMembersIntegrations::where('intergration', 'salesforce')
                                    ->where('intergration_id', $value->Id)
                                    ->first();

                    if(!empty($memberExists)){
                        $member = HouseholdMembers::findOrNew($memberExists->household_member_id);
                    } else {
                        $member = new HouseholdMembers();

                        if(!$household || is_null($household) || empty($household)){
                            print_r($member->id);
                        }

                        $member->org_id       = 1;
                        $member->type_id      = 1;
                        $member->first_name   = $value->FirstName;
                        $member->last_name    = $value->LastName;
                        $member->cell_phone   = $value->MobilePhone;
                        $member->home_phone   = $value->Phone;
                        $member->email        = $value->Email;
                        $member->dob          = ($value->Birthdate) ?? null;
                        $member->household_id = $household->id;
                        $member->save();

                        if (empty($household->primary_member_id)){
                            $household->primary_member_id  = $member->id;
                            $household->save();
                        }

                        HouseholdMembersIntegrations::firstOrCreate([
                            'household_member_id' => $member->id,
                            'intergration'        => 'salesforce'
                        ], [
                            'intergration_id'     => $value->Id
                        ]);
                    }

                    if(!$household || is_null($household) || empty($household)){
                        print_r($member->id);

                    }

                    $member->org_id       = 1;
                    $member->type_id      = 1;
                    $member->first_name   = $value->FirstName;
                    $member->last_name    = $value->LastName;
                    $member->cell_phone   = $value->MobilePhone;
                    $member->home_phone   = $value->Phone;
                    $member->email        = $value->Email;
                    $member->dob          = ($value->Birthdate) ?? null;
                    $member->household_id = $household->id;
                    $member->save();

                    if (empty($household->primary_member_id)){
                        $household->primary_member_id = $member->id;
                        $household->save();
                    }

                    if(!$memberExists){
                        HouseholdMembersIntegrations::firstOrCreate([
                            'household_member_id' => $member->id,
                            'intergration'        => 'salesforce'
                        ], [
                            'intergration_id'     => $value->Id
                        ]);
                    }
 
                    //make Household Head as primary
                    if(property_exists($value, 'Level__c') && $value->Level__c == 'Primary'){
                        $primaryMemberId = $member->id;
                    }    
                }

                if ($primaryMemberId || empty($household->primary_member_id)){
                    $household->primary_member_id = ($primaryMemberId) ?? $member->id;
                    $household->save();
                }                

                //save last meeting
                if($lastMeeting && !empty($record->$lastMeeting)){
                    $this->crmRepository->createHouseholdMeetings($household->id, $member->id, $record->$lastMeeting);
                }

            }
        }
        return $records;
    }

    public function loadCentorbiCustomFields(){

        $custom  = $this->salesforce->getCustomFields('Account');

        $collect = collect($custom->fields);
        $fields  = $collect->where('custom', 1)->implode('name', ', ');

        $data    = $this->salesforce->getCustomQuery('Account', sprintf("select id, %s from account", $fields));

        $runDate = date(Carbon::now());

        foreach ($data->records as $record) {

            $integration = HouseholdIntegration::where(['intergration' => 'salesforce', 'intergration_id' => $record->Id])->first();

            if ($integration) {

                $integration = $integration->load('household');
                $keyTypes = [
                    'ewealthplan_week_1__c',
                    'ewealthplan_week_2__c',
                    'ewealthplan_week_3__c',
                    'ewealthplan_week_4__c',
                    'Next_Review_Date__c',
                    'Client_Review_Process_Active__c',
                    'Client_Review_Appt_Booked__c',
                    'Days_till_Review__c',
                    'Next_Review_Date__c',
                    'Household_Category__c'
                ];

                foreach ($record as $key => $value) {

                    if (in_array($key, $keyTypes)) {

                        $step = substr($key, 0, strpos($key, '__c'));


                        $reviewProcess =  HouseholdsAccountReviewProcess::firstOrCreate([
                            'household_id' => $integration->household->id,
                            'step' => $step,
                            'date' => $runDate
                        ]);

                        $reviewProcess->value = $value;
                        $reviewProcess->date  = $runDate;

                        $reviewProcess->save();

                        if ($key == 'Next_Review_Date__c' && trim($value) != '') {

                            $date     = Carbon::parse($value);
                            $date->tz = 'US/Central';

                            //save meeting to household meetings if not there already
                            $meeting = HouseholdMeetings::firstOrCreate([
                                'household_id' => $integration->household->id,
                                'household_member_id' => $integration->household->primary_member_id,
                                'meeting' => $date,
                                'start' => $date,
                                'end' => Carbon::parse($date)->addHours(2)
                            ]);

                            $userIds = Advisors::where('id', $integration->household->advisor_1_id)->pluck('user_id')->toArray();
                            $meeting->users()->sync($userIds);
                        }
                    }
                }
            }
        }
    }


    public function updateCentorbiPrimaryMember(){
        $data = $this->salesforce->getCustomQuery('Account', "select id, Head_Of_House_Hold__c from account");

        foreach($data->records as $record){

            //if head of hosuehold set, look up and set as primary member
            if (!empty($record->Head_Of_House_Hold__c)){
                $memberIntegration = HouseholdMembersIntegrations::where('intergration', 'salesforce')->where('intergration_id', $record->Head_Of_House_Hold__c)->first();

                if ($memberIntegration){

                    $householdExists   = HouseholdIntegration::where('intergration', 'salesforce')
                    ->where('intergration_id', $record->Id)
                    ->first();


                    if ($householdExists) {
                        $household = Households::find($householdExists->household_id);

                        echo 'updating primary member' . "\n";

                        $household->primary_member_id = $memberIntegration->household_member_id;
                        $household->save();

                    }

                }

            }


        }
    }

    public function processCentorbiFlows(){


        /*schedule 1st message*/

        DB::insert("insert into conversations(household_id, conversation_library_id, method, created_at, updated_at, household_member_id, send, user_id)
        select
        p.household_id,
        (select id from conversation_library where name = 'Centorbi_Meeting_Schedule'),
        'text',
        NOW(),
        NOW(),
        h.primary_member_id,
        timestamp(date(DATE_ADD(p.date, INTERVAL 0 DAY)), SEC_TO_TIME(FLOOR(TIME_TO_SEC('09:00:00') + RAND() * (TIME_TO_SEC(TIMEDIFF('17:00:00', '09:00:00')))))),
        (select id from users where name = 'Benjamin')

        from vw_households_account_review_process p
            join households h on h.id = p.household_id
        where p.step = 'Client_Review_Appt_Booked' and p.value = 0
        and p.household_id in (
            select household_id from vw_households_account_review_process where step = 'Client_Review_Process_Active' and value = 1
        ) and (select count(*) from conversations where household_id = p.household_id and conversation_library_id = (select id from conversation_library where name = 'Centorbi_Meeting_Schedule')) < 3");



        /*schedule 2nd message*/
        DB::insert("insert into conversations(household_id, conversation_library_id, method, created_at, updated_at, household_member_id, send, user_id)
        select
        p.household_id,
        (select id from conversation_library where name = 'Centorbi_Meeting_Schedule'),
        'text',
        NOW(),
        NOW(),
        h.primary_member_id,
        timestamp(date(DATE_ADD(c.send, INTERVAL 7 DAY)), SEC_TO_TIME(FLOOR(TIME_TO_SEC('09:00:00') + RAND() * (TIME_TO_SEC(TIMEDIFF('17:00:00', '09:00:00')))))),
        (select id from users where name = 'Benjamin')

        from vw_households_account_review_process p
            join households h on h.id = p.household_id
            join (select household_id, max(send) 'send' from conversations where conversation_library_id = (select id from conversation_library where name = 'Centorbi_Meeting_Schedule') group by household_id) c on c.household_id = p.household_id
        where p.step = 'Client_Review_Appt_Booked' and p.value = 0
        and p.household_id in (
            select household_id from vw_households_account_review_process where step = 'Client_Review_Process_Active' and value = 1
        ) and (select count(*) from conversations where household_id = p.household_id and conversation_library_id = (select id from conversation_library where name = 'Centorbi_Meeting_Schedule')) < 3");



        /*schedule 3rd message*/
        DB::insert("insert into conversations(household_id, conversation_library_id, method, created_at, updated_at, household_member_id, send, user_id)
        select
        p.household_id,
        (select id from conversation_library where name = 'Centorbi_Meeting_Schedule'),
        'text',
        NOW(),
        NOW(),
        h.primary_member_id,
        timestamp(date(DATE_ADD(c.send, INTERVAL 7 DAY)), SEC_TO_TIME(FLOOR(TIME_TO_SEC('09:00:00') + RAND() * (TIME_TO_SEC(TIMEDIFF('17:00:00', '09:00:00')))))),
        (select id from users where name = 'Benjamin')

        from vw_households_account_review_process p
            join households h on h.id = p.household_id
            join (select household_id, max(send) 'send' from conversations where conversation_library_id = (select id from conversation_library where name = 'Centorbi_Meeting_Schedule') group by household_id) c on c.household_id = p.household_id
        where p.step = 'Client_Review_Appt_Booked' and p.value = 0
        and p.household_id in (
            select household_id from vw_households_account_review_process where step = 'Client_Review_Process_Active' and value = 1
        ) and (select count(*) from conversations where household_id = p.household_id and conversation_library_id = (select id from conversation_library where name = 'Centorbi_Meeting_Schedule')) < 3");



        /*if booked, delete all scheduled conversations*/
        DB::delete("delete from conversations where sent is null and conversation_library_id = (select id from conversation_library where name = 'Centorbi_Meeting_Schedule')
        and household_id in (
            select household_id from vw_households_account_review_process where step = 'Client_Review_Appt_Booked' and value = 1
        )");



        /*send text if 1 day out*/

        DB::insert("insert into conversations(household_id, conversation_library_id, method, created_at, updated_at, household_member_id, send, user_id)
        select
        p.household_id,
        (select id from conversation_library where name = 'Centorbi_Meeting_Confirm'),
        'text',
        NOW(),
        NOW(),
        h.primary_member_id,
        timestamp(date(DATE_ADD(p.date, INTERVAL 0 DAY)), SEC_TO_TIME(FLOOR(TIME_TO_SEC('09:00:00') + RAND() * (TIME_TO_SEC(TIMEDIFF('17:00:00', '09:00:00')))))),
        (select id from users where name = 'Benjamin')
         from vw_households_account_review_process p
             join households h on h.id = p.household_id
         where p.step = 'Client_Review_Appt_Booked' and p.value = 1
        and p.household_id in (
            select household_id from vw_households_account_review_process where step = 'Days_till_Review' and value = '1'
        ) and not exists(select * from conversations where household_id = p.household_id and conversation_library_id = (select id from conversation_library where name = 'Centorbi_Meeting_Confirm'))
        ");


    }

    public function querySalesforce(){
        print_r($this->salesforce->getCustomQuery('Account', "select id, name, Head_of_house_hold__c,(select id, name, Email, Phone, FirstName, LastName from Contacts) from account"));
    }

    public function householdFieldMapping(&$household, &$record)
    {
        $household->name = $record->Name;
        $household->entity_id = $record->Id;
    }

    public function householdMemberFieldMapping(&$member, &$record)
    {
        $member->first_name = $record->FirstName;
        $member->last_name  = $record->LastName;
        $member->cell_phone = $record->Phone;
        $member->email      = $record->Email;
        $member->entity_id  = $record->Id;
    }

    public function stageData()
    {
        $data = [];
        $data = $this->getContacts($data);

        if(!empty($data)){
            $res = $this->parseContacts($data);

            $this->crmRepository->insertHousehold('salesforce', $res['household']);
            $this->crmRepository->insertHouseholdMembers('salesforce', $res['householdMembers']);
        }

        return $data;
    }

    public function parseContacts($records)
    {
        $household        = [];
        $householdMembers = [];
        $householdIndex   = 0;

        $memberType = HouseholdMemberTypes::pluck('id', 'type')->toArray();
        $primaryMemberTypeID = $memberType['Primary'];
        $otherMemberTypeID   = $memberType['Other'];

        for($i=0; $i<=count($records)-1; $i++){
            $record = $records[$i];

            //set household
            $householdObj = new \stdClass();

            $householdObj->org_id       = $this->org_id ;
            $householdObj->integration =  $this->integration_name;

            $this->householdFieldMapping($householdObj, $record);

            $household[] = $householdObj;
            $householdIndex = $householdIndex + 1;

            //loop contacts
            if (empty($record->Contacts)){
                continue;
            }

            $memberCnt = 0;
            foreach($record->Contacts->records as $value){

                //set household member
                $householdMembersObj = new \stdClass();

                $householdMembersObj->org_id       = $this->org_id;
                $householdMembersObj->household_id = $householdIndex;
                $householdMembersObj->active       = 1;
                $householdMembersObj->deceased     = 0;
                $householdMembersObj->integration =  $this->integration_name;
                $this->householdMemberFieldMapping($householdMembersObj, $value);

                //assign type_id
                $householdMembersObj->type_id = ($memberCnt == 0) ? $primaryMemberTypeID : $otherMemberTypeID;
                $memberCnt++;

                $householdMembers[] = $householdMembersObj;
            }
        }

        return [
            'household'        => $household,
            'householdMembers' => $householdMembers
        ];
    }

    public function addNotes($intergration_id, $note, $other = [])
    {
        $data = [
            'ParentId' => $intergration_id,
            'Title'    => isset($note['title']) ? $note['title'] : 'notes',
            'Body'     => isset($note['note']) ? $note['note'] : ''
        ];

        $response = $this->salesforce->create('Note/', $data);

        if($response && $response->id) {
            $noteId     = $response->id;
            $note       = $this->getNoteById($intergration_id);
            $collection = collect($note->records);

            if($collection->isNotEmpty) {
                if ($collection->contains('Id', $noteId)) {
                    return true;
                }
            } else {
                return false;
            }
        }
        return false;
    }


    public function getRecordType(){
      return $this->salesforce->getCustomQuery('Account', "select Id, Name, Description, DeveloperName, IsActive from RecordType where sobjecttype='Contact'");
    }

    /**
    * get saleforce CRM tasks
    *
    * @return void
    */
    public function getTasks($integration, $otherDB = false, $taskId = null, $isGuardian = false)
    {
        $columns = [
            'Id', 'WhoId', 'AccountId',
            'Subject', 'ActivityDate', 'Description',
            'Priority', 'TaskSubtype', 'ReminderDateTime'
        ];

        $tasks = [];
        if($isGuardian){
            // This list of status is only for Guardian, we will need to determine the best way to pull for everyone, perhaps a setting with status to use for tasks.
            if(!is_null($taskId)){
                $lists = $this->salesforce->getCustomQuery('Task', sprintf("select %s from task where status IN('In Progress','Waiting on someone else','Not Started') AND Id = '$taskId'", implode(', ', $columns) ));
            } else {
                $lists = $this->salesforce->getCustomQuery('Task', sprintf("select %s from task where status IN('In Progress','Waiting on someone else','Not Started')", implode(', ', $columns) ));
            }
        } else {
            $lists = $this->salesforce->getCustomQuery('Task', sprintf("select %s from task", implode(', ', $columns) ));
            print_r($lists);
        }
        // This gets a lists of possible task statuses.
        //$lists = $this->salesforce->getCustomQuery('TaskStatus', "select ApiName, IsClosed, MasterLabel from TaskStatus");
        
        if (property_exists($lists, 'records'))  {
            $tasks = $lists->records;
            if($isGuardian && !is_null($taskId)){
                return $tasks;
            }
            
            // if tasks exits
            if (!empty($tasks)) {
                if(is_array($integration)){
                    $userId = $integration[0]->user_id;
                }else{
                    $userId = $integration->user_id;
                }
                
                $tasks = array_map(function($task) use($userId){
                    $task->userId = $userId;
                    return $task;
                }, $tasks);
                $this->crmRepository->updateOrCreateTask($tasks, 'salesforce');
            }
        }
        
        return $tasks;
    }

    /**
    * get saleforce CRM notes by ID
    *
    * @param  integer $integration_id  [integer]
    * @return object
    */
    public function getNoteById($intergration_id)
    {
        return $this->salesforce->getCustomQuery('Note', "Select Id, Body, Title from Note where ParentID='$intergration_id'");
    }
    
    public function setTasksToCompleted($taskId, $integration = null)
    {
        $task = Tasks::find($taskId);

        if(empty($task)){
            return false;
        }

        if($task->integration == 'salesforce' && !empty($task->integration_id)){

            $data = $this->salesforce->update('Task/', $task->integration_id, ['Status' => 'Completed']);

            return true;
        }
    }

    public function dataPull($integration)
    {
        $this->refreshAccessToken($integration->id);
        $integration->refresh();

        
        $contacts = $this->getAllContacts();
        
        $tasks = $this->getTasks($integration);
        
        return [
            'contacts'  => $contacts,
            'tasks'     => $tasks
        ];
    }
}
