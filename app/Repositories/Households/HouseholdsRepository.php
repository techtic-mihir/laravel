<?php
namespace App\Repositories\Households;

use App\Events\AddToGroup;
use App\Events\RemoveFromGroup;
use App\Models\Accounts;
use App\Models\Advisors;
use App\Models\Conversation;
use App\Models\ConversationLibrary;
use App\Models\ConversationsDetail;
use App\Models\ConversationsGroup;
use App\Models\ConversationsHouseholds;
use App\Models\ConversationsNotesLog;
use App\Models\CustodianSalesRep;
use App\Models\Document;
use App\Models\DocumentTag;
use App\Models\Employee;
use App\Models\Fund;
use App\Models\Group;
use App\Models\GroupsHousehold;
use App\Models\HistoricalBalances;
use App\Models\HouseholdAddress;
use App\Models\HouseholdAnalysis;
use App\Models\HouseholdAnalysisDebts;
use App\Models\HouseholdAnticipatedAccount;
use App\Models\HouseholdCpa;
use App\Models\HouseholdIntegration;
use App\Models\HouseholdMeetings;
use App\Models\HouseholdMemberBeneficiaries;
use App\Models\HouseholdMembers;
use App\Models\HouseholdNotes;
use App\Models\Households;
use App\Models\HouseholdStatus;
use App\Models\HouseholdStatusLog;
use App\Models\InsuranceEzlynx;
use App\Models\Integration;
use App\Models\MeetingStatus;
use App\Models\Reminder;
use App\Models\Sales;
use App\Models\Tasks;
use App\Models\TasksAssignment;
use App\Models\HouseholdMemberTypes;
use App\Models\TaskStatus;
use App\Models\TaskTypes;
use App\Models\User;
use App\Repositories\Conversation\ConversationRepository;
use App\Repositories\Conversation\ConversationScheduleRepository;
use App\Repositories\Groups\GroupsRepository;
use App\Repositories\CRM\HubspotRepository;
use App\Repositories\Query\ScheduleRepository;
use App\Repositories\Twilio\TwilioRepository;
use App\Services\Chat;
use App\Services\EZLynx;
use App\Services\Junxure;
use Carbon\Carbon;
use DB;
use Illuminate\Support\Facades\Storage;
use PDF;
use Spatie\ArrayToXml\ArrayToXml;
use \DrewM\MailChimp\MailChimp;
use App\Models\GroupsFund;
use App\Repositories\CRM\ZohoRepository;
use App\Repositories\CRM\SalesForceRepository;
use App\Repositories\CRM\RedtailRepository;
use App\Repositories\CRM\WealthboxRepository;
use App\Repositories\CRM\JunxureRepository;
use App\Models\HouseholdEmployees;
use App\Repositories\Settings\SettingsRepository;
use App\Models\QueryField;
use App\Models\HouseholdMeetingsUser;
use App\Models\UserSavedSetting;
use App\Models\InstanceSettings;


class HouseholdsRepository
{
    const FROM = '+17702885324';

    public function __construct(
        Chat $chat,
        Junxure $junxure,
        EZLynx $ezlynx,
        ConversationRepository $conversationRepository,
        ScheduleRepository $scheduleRepository,
        TwilioRepository $twilioRepository,
        GroupsRepository $groupsRepository,
        ConversationScheduleRepository $conversationScheduleRepository,
        HubspotRepository $hubspotRepository,
        ZohoRepository $zohoRepository,
        SalesForceRepository $salesForceRepository,
        RedtailRepository $redtailRepository,
        WealthboxRepository $wealthboxRepository,
        JunxureRepository $junxureRepository,
        SettingsRepository $settingsRepository
    ) {
        $this->chat                           = $chat;
        $this->junxure                        = $junxure;
        $this->org                            = config('benjamin.org_id');
        $this->instance_type                  = config('benjamin.instance_type');
        $this->junxureUserId                  = 'b547284b-dac7-45be-8a9a-f678ae8af42a';
        $this->powerSearchID                  = 'b4472f5e-c035-4892-b1a4-5209f1da54bb';
        $this->ezlynx                         = $ezlynx;
        $this->conversationRepository         = $conversationRepository;
        $this->scheduleRepository             = $scheduleRepository;
        $this->twilioRepository               = $twilioRepository;
        $this->groupsRepository               = $groupsRepository;
        $this->conversationScheduleRepository = $conversationScheduleRepository;
        $this->zohoRepository                 = $zohoRepository;
        $this->salesforceRepository           = $salesForceRepository;
        $this->redtailRepository              = $redtailRepository;
        $this->wealthboxRepository            = $wealthboxRepository;
        $this->junxureRepository              = $junxureRepository;
        $this->hubspotRepository              = $hubspotRepository;
        $this->settingsRepository             = $settingsRepository;
    }

    public function copyHouseholdsFromDemo($org_id)
    {
        $data = DB::select('select * from `benjamin-portal`.households_demos;');
        $data = json_decode(json_encode($data), true);

        foreach ($data as &$d) {
            $d['org_id'] = $org_id;
        }

        Households::insert($data);
        return true;
    }

    public function deleteHouseholds($org_id)
    {
        Households::truncate();
    }

    public function get($with = false)
    {
        $households = Households::query();
        if ($with) {
            $households->with('accounts');
        }
        return $households->get();
    }

    public function save($data)
    {
        return Households::create([
            'name'   => $data['name'],
            'org_id' => $data['org_id'],
        ]);
    }

    public function getHousehold($id, $scope = [])
    {
        if(isset($id) && !empty($id)){
            $with = ['primaryMember', 'householdCpa', 'householdAddresses', 'householdMembers.memberBeneficiaries', 'referredHousehold', 'status', 'partner', 'companyInfo', 'advisor1', 'advisor2'];
            
            $today = Carbon::now();
            
            $upcoming_meeting = HouseholdMeetings::with('meetingUser')
            ->where('status_id', 1)
            ->where('complete', 0)
            ->where('household_id', $id)
            ->whereDate('meeting', '>=', $today->format('Y-m-d'))
            ->orderBy('start')
            ->first();
            
            if (strtolower($this->instance_type) == 'sales') {
                if (!in_array('companyInfo', $with)) {
                    array_push($with, 'companyInfo');
                }
            }
            
            $household = Households::with($with)->find($id);
            
            //filter using scope
            if (!empty($scope)) {
                extract($scope);
                $scope = explode("+", $scope);
            }
            
            if (in_array('member', $scope)) {
                $household->householdMembers;
            }
            
            $accountCustodian = Accounts::leftJoin('custodians', 'custodians.id', '=', 'accounts.id')->where('household_id', $id)->where('status_id', '<>', 16)->first();
            
            $household->household_custodian = $accountCustodian;
            $household->accountsSum         = $household->accountsSum->first();
            
            if ($household->progressive) {
                $household->fee = 'progressive';
            } elseif ($household->no_bill) {
                $household->fee = 'no_bill';
            } else {
                $fees   = [];
                $fees[] = '1.0';
                $fees[] = '0.9';
                $fees[] = '0.8';
                
                $household->fee = (string) floatval($household->fee);
                
                if (!in_array($household->fee, $fees)) {
                    $household->other_fee = $household->fee;
                    $household->fee       = 'other';
                }
            }
            
            $household->upcoming_meeting = $upcoming_meeting;
            return $household;
        } else {
            return false;
        }
    }

    public function getRecentHouseholdConv($id)
    {
        $conversations = \DB::select("select c.replied, c.created_at, l.name from conversations c
                        join conversation_library l on c.conversation_library_id = l.id
                        join households h on h.id = c.household_id
                        where h.id = :household_id
                        and c.employee_id is null
                        order by c.created_at desc
                        limit 3", ['household_id' => $id]);

        $conversations = array_map(function ($row) {
            $row->human_date = Carbon::parse($row->created_at)->diffForHumans();
            return $row;
        }, $conversations);

        return $conversations;
    }

    public function getConversationsList($id, $isUpcoming = 0)
    {
        $conversations = Conversation::from('conversations AS c')
            ->with(['details'])
            ->join('households AS h', 'c.household_id', '=', 'h.id')
            ->leftjoin('conversation_library AS cl', 'c.conversation_library_id', '=', 'cl.id')
            ->leftJoin('conversations_details AS cd', function($join){
                $join->on('c.chat_queue_id', '=', 'cd.chat_queue_id')
                    ->where('cd.sent', '=', 0)
                    ->whereNull('c.employee_id')
                    ->whereNull('cd.read');
            })
            ->where('c.household_id', $id);

        $conversations = $conversations->select('c.*', 'cl.name', 'h.name AS household_name', 'cd.read', 'cd.message as unread_message');

        if($isUpcoming == 1){
            $date  = Carbon::now()->toDateTimeString();

            $conversations = $conversations->join('conversations_households AS ch', 'c.subscription_id', '=', 'ch.id')->addSelect(['ch.cadence', 'ch.number_of_reminders', 'ch.send AS ch_send']);

            /*$conversations = $conversations->where(function ($query) use ($date) {
                $query->where('c.send', '>=', $date);
                $query->whereNull('c.sent');
            });*/
        }

        $conversations = $conversations->get()->toArray();

        return $conversations;
    }

    public function getCountConversation($id)
    {
        $conversationsSent = Conversation::from('conversations AS c')
             ->where('c.household_id', $id)
             ->whereNull('c.employee_id')
             ->count();

        /*$conversationsUnread = Conversation::from('conversations AS c')
            ->where('c.household_id', $id)
            ->whereIn('c.replied', [ 0, null ])
            ->whereNotNull('c.sent')->count();

        $conversationsFollowup = Conversation::from('conversations AS c')
             ->where('c.sent', null)
             ->where('c.household_id', $id)->count();*/

       $conversationsScheduled = Conversation::from('conversations AS c')
             ->where('c.sent', null)
             ->where('c.household_id', $id)
             ->whereNull('c.employee_id')
             ->whereRaw('c.send > CURDATE()')->count();

        $total  = $conversationsSent +  $conversationsScheduled;
        $data = [
                 'conversations_sent'      => $conversationsSent,
                 'conversations_scheduled' => $conversationsScheduled,
                 'total'                   => $total
        ];

        return $data;
    }

    public function getAssignedGroup($id)
    {
        $groupHousehold = GroupsHousehold::from('groups_households AS gh')
            ->join('groups AS g', 'gh.group_id', '=', 'g.id')
        // ->join('households AS h', 'gh.household_id', '=', 'h.id')
            ->where('gh.household_id', $id)
            ->select('gh.*', 'g.name', 'g.dynamic')
            ->get()
            ->toArray();

        return $groupHousehold;
    }

    public function deleteGroupsHousehold($id)
    {
        GroupsHousehold::where(['id' => $id])->delete();
        return true;
    }

    public function getSubscribedConversations($id)
    {

        $convo_household = \DB::select("select CH.*, CL.name, '0' as type from conversations_households CH JOIN conversation_library CL ON CL.id = CH.conversation_library_id where CH.household_id =" . $id);

        $convo_groups = \DB::select("select CG.*, CL.name, '1' as type from groups_households d join conversations_groups CG on d.group_id = CG.group_id join conversation_library CL ON CL.id = CG.conversation_library_id where d.household_id =" . $id);

        $convo_data = array_merge($convo_household, $convo_groups);

        usort($convo_data, function ($a, $b) {
            return $a->created_at <=> $b->created_at;
        });

        return $convo_data;
    }

    public function getNewConversations($id)
    {
        $Conversation_list = ConversationLibrary::where('org_id', $id)->where('active', 1)->get()->toArray();
        return $Conversation_list;
    }

    public function removeConversations($id, $conversation_lib_id, $type)
    {
        // $groupIds = GroupsHousehold::where('household_id', $id)->pluck('group_id')->toArray();
        if ($type) {
            return false;
        }

        ConversationsHouseholds::where(['household_id' => $id, 'conversation_library_id' => $conversation_lib_id])->delete();
        return true;
    }

    public function historyConversations($id, $conversation_id)
    {
        $Conversation_list = Conversation::with(['conversationsdetails', 'households', 'conversation_library'])->where(['household_id' => $id, 'conversation_library_id' => $conversation_id])->get()->toArray();
        return $Conversation_list;
    }

    public function addNewConversations($id, $userId, $input)
    {
        $insertedIds = [];

        foreach ($input['conv'] as $key => $value) {
            $tempData = ConversationsHouseholds::create([
                'household_id'            => $id,
                'conversation_library_id' => $value['id'],
                'user_id'                 => $userId,
                'cadence'                 => $value['is_event_driven'] ? 'event' : $value['cadence'],
            ]);

            $insertedIds[] = $tempData->id;
        }

        $data = \DB::select("select CH.*, CL.name, '0' as type from conversations_households CH JOIN conversation_library CL ON CL.id = CH.conversation_library_id where CH.id in (" . implode(',', $insertedIds) . ")");

        return $data;
    }

    public function historicalBalances($id)
    {
        $historicalBalances = HistoricalBalances::get()->toArray();
        return $historicalBalances;
    }

    public function getHouseholdInfo($id)
    {
        try {
            $household_info = Households::with(['householdMembers'])->find($id);
            return [
                'code'   => 200,
                'result' => $household_info,
            ];
        } catch (Exception $e) {
            return [
                'code'   => 500,
                'result' => false,
            ];
        }
    }
    public function getAccounts($id)
    {

        $accounts = Accounts::with(['accountTypes'])->where('household_id', $id)->where('status_id', '<>', 16)->get();

        $accounts = $accounts->map(function ($item, $key) {
            $fee       = null;
            $other_fee = null;

            if ($item['progressive']) {
                $fee = 'progressive';
            } elseif ($item['no_bill']) {
                $fee = 'no_bill';
            } else {
                $fees   = [];
                $fees[] = '1.0';
                $fees[] = '0.9';
                $fees[] = '0.8';

                $fee = (string) floatval($item['fee']);

                if (!in_array($fee, $fees)) {
                    $other_fee = $item['fee'];
                    $fee       = 'other';
                }
            }

            return [
                "id"               => $item['id'],
                "first_trade_date" => Carbon::parse($item['first_trade_date'])->format('Y-m-d'),
                "fee"              => $fee,
                "other_fee"        => $other_fee,
                "estimate"         => $item['estimate'],
                "source_id"        => $item['source_id'],
                "status_id"        => $item['status_id'],
                "closed_reason_id" => $item['closed_reason_id'],
                "special"          => $item['special'],
                "direct_bill"      => $item['direct_bill'],
                "advisor_overide"  => $item['advisor_overide'],
                "account_number"   => $item['account_number'],
                "name_account"     => $item['name'],
                "account_type"     => $item['accountTypes']['name'],
                "starting_balance" => $item['starting_balance'],
                "current_balance"  => $item['balance'],
                "open_date"        => Carbon::parse($item['established_date'])->format('Y-m-d'),
            ];
        })->toArray();

        foreach ($accounts as $key => &$value) {
            $value['primary_beneficiary']    = HouseholdMemberBeneficiaries::where('account_id', $value['id'])->where('type', 'primary')->get()->toArray();
            $value['contingent_beneficiary'] = HouseholdMemberBeneficiaries::where('account_id', $value['id'])->where('type', 'contingent')->get()->toArray();
        }

        return $accounts;
    }

    public function getTradeAccounts($household_id)
    {

        $accounts = DB::select("select a.*, t.name 'type' from accounts a
                            join households h on a.household_id = h.id
                            left join account_types t on t.id = a.type_id
                            where h.id = ?", [$household_id]);

        if($accounts && !empty($accounts)) {

            if ($accounts[0]->progressive) {
                $accounts[0]->fee = 'progressive';
            } elseif ($accounts[0]->no_bill) {
                $accounts[0]->fee = 'no_bill';
            } else {

                $fees   = [];
                $fees[] = '1.0';
                $fees[] = '0.9';
                $fees[] = '0.8';

                $accounts[0]->fee = (string) floatval($accounts[0]->fee);
                if (!in_array($accounts[0]->fee, $fees)) {
                    $accounts[0]->other_fee = $accounts[0]->fee;
                    $accounts[0]->fee       = 'other';
                }
            }
        }

        return $accounts;
    }

    public function accountBalancesFilter($request)
    {

        $min = $request['min_balance'];
        $max = $request['max_balance'];

        $historical_balances = HistoricalBalances::whereBetween('balance', [$min, $max])->get()->toArray();
        return [
            'code'     => 200,
            'response' => $historical_balances,
        ];
    }
    public function getHouseholds($user, $id)
    {
        $orgId = $user->organization_id;

        $with = ['householdMembers', 'groupHouseholds', 'accounts.custodian', 'totalNetWorth', 'primaryMember'];

        $query = Households::with($with)->where('org_id', $orgId)->has('accounts');
        if ($id && $id != 'all') {
            $query->whereHas('groupHouseholds', function ($q) use ($id) {
                $q->where('group_id', $id);
            });
        }

        $res = $query->get();
        return $res;
    }

    public function paginateHousehold($requestData, $user)
    {
        $orgId      = $user->organization_id;
        $advisorId  = Advisors::where('user_id', $user->id)->pluck('id')->first();
        $employeeIds = Employee::where('user_id', $user->id)->where('selectable',1)->pluck('id');

        $with  = ['householdMembers', 'groupHouseholds', 'accounts.custodian', 'totalNetWorth', 'primaryMember','householdEmployees'];
        $id    = $requestData['group_id'];

        $query = Households::with($with)
                ->leftJoin('accounts', function ($join) {
                    $join->on('accounts.household_id', '=', 'households.id');
                })
                ->groupBy(DB::raw('IFNULL(accounts.household_id, households.id)'));

        $households = [];

        if($user->IsInRole(['partial-user'])){
            $householdAdvisors  = [];
            $householdEmployees = [];

            if(!empty($advisorId)) {
                $householdAdvisors = Households::where('advisor_1_id', $advisorId)->orWhere('advisor_2_id', $advisorId)->get()->toArray();
                $householdAdvisors = array_column($householdAdvisors, 'id');
            }

            if(!empty($employeeIds)) {
                $householdEmployees = HouseholdEmployees::whereIn('employee_id', $employeeIds)->get()->toArray();
                $householdEmployees = array_column($householdEmployees, 'household_id');
            }

            $households = array_merge($householdAdvisors, $householdEmployees);
            $households = array_map("unserialize", array_unique(array_map("serialize", $households)));
        }

        if ($id && $id != 'all') {
            $query->whereHas('groupHouseholds', function ($q) use ($id) {
                $q->where('group_id', $id);
            });

            $groupType = Group::find($id)->type;
            if ($groupType == 'meeting') {
                $query->with(['meetings' => function ($q) {
                    $q->where('meeting', '>=', Carbon::parse(date('Y-m-d')));
                }]);
            }
        }

        if (!empty($requestData['searchListText'])) {
            $searchListText = $requestData['searchListText'];
            $searchLikeText = '%' . $searchListText . '%';
            $query->where(function ($q) use ($searchLikeText) {
                $q->where('households.name', 'LIKE', $searchLikeText)
                    ->orWhereHas('householdMembers', function ($s) use ($searchLikeText) {
                        $s->where(function ($q) use ($searchLikeText) {
                            $q->where('email', 'LIKE', $searchLikeText)
                                ->orWhere(DB::raw('replace(IFNULL(cell_phone, home_phone), "-", "")'), 'LIKE', str_replace(['-'], '', $searchLikeText))->orWhere('address_1', 'LIKE', $searchLikeText)->orWhere('address_2', 'LIKE', $searchLikeText)->orWhere('city', 'LIKE', $searchLikeText)->orWhere('state', 'LIKE', $searchLikeText)->orWhere('zip', 'LIKE',$searchLikeText);
                        });
                    });
            });
        }

        if (!empty($requestData['orderByColumn'])) {
            $orderBy = !empty($requestData['orderBy']) ? $requestData['orderBy'] : 'asc';
            $query->orderBy($requestData['orderByColumn'], $orderBy);
        }

        $query = $query->select([
            'households.*',
            \DB::Raw('IFNULL(sum(accounts.balance), 0) as total_balance')
        ]);

        $res = $query->paginate($requestData['paginationPerPage'], ['*'], 'page', $requestData['pageNo']);
        $result = $res->toArray();

        $collections = collect($result['data'])->map(function ($item, $key) use ($households, $user){
            if(!$user->IsInRole(['partial-user'])){
                $item['partial_assign'] = 1;
            }else{
                if (in_array($item['id'], $households) ) {
                    $item['partial_assign'] = 1;
                } else {
                    $item['partial_assign'] = 0;
                }
            }
            return $item;
        });

        $res = collect($res)->map(function ($item, $key) use ($collections){
            if($key == 'data'){
                $item = $collections;
            }
            return $item;
        });

        return $res;
    }

    public function salesHousehold($requestData, $user)
    {

        $orgId      = $user->organization_id;
        $advisorId  = Advisors::where('user_id', $user->id)->pluck('id')->first();
        $employeeIds = Employee::where('user_id', $user->id)->where('selectable',1)->pluck('id');

        $with       = ['householdMembers', 'groupHouseholds', 'accounts.custodian', 'totalNetWorth', 'primaryMember', 'householdEmployees'];
        $id         = $requestData['group_id'];

        $query = Households::with($with)
            ->leftJoin('accounts', function ($join) {
                $join->on('accounts.household_id', '=', 'households.id');
            })
            ->join('company_info', function ($join) {
                $join->on('company_info.household_id', '=', 'households.id');
            })
            ->whereNotNull('company_info.household_id')
            ->groupBy(DB::raw('IFNULL(accounts.household_id, households.id)'))
            ->where('households.org_id', $orgId);

        $households = [];

        if($user->IsInRole(['partial-user'])){
            $householdAdvisors  = [];
            $householdEmployees = [];

            if(!empty($advisorId)) {
                $householdAdvisors = Households::where('advisor_1_id', $advisorId)->orWhere('advisor_2_id', $advisorId)->get()->toArray();
                $householdAdvisors = array_column($householdAdvisors, 'id');
            }

            if(!empty($employeeIds)) {
                $householdEmployees = HouseholdEmployees::whereIn('employee_id', $employeeIds)->get()->toArray();
                $householdEmployees = array_column($householdEmployees, 'household_id');
            }

            $households = array_merge($householdAdvisors, $householdEmployees);
            $households = array_map("unserialize", array_unique(array_map("serialize", $households)));
        }

        if ($id && $id != 'all') {
            $query->whereHas('groupHouseholds', function ($q) use ($id) {
                $q->where('group_id', $id);
            });

            $groupType = Group::find($id)->type;
            if ($groupType == 'meeting') {
                $query->with(['meetings' => function ($q) {
                    $q->where('meeting', '>=', Carbon::parse(date('Y-m-d')));
                }]);
            }
        }

        if (!empty($requestData['searchListText'])) {
            $searchListText = $requestData['searchListText'];
            $searchLikeText = '%' . $searchListText . '%';
            $query->where(function ($q) use ($searchLikeText) {
                $q->where('households.name', 'LIKE', '%' . $searchLikeText . '%')
                    ->orWhereHas('householdMembers', function ($s) use ($searchLikeText) {
                        $s->where(function ($q) use ($searchLikeText) {
                            $q->where('email', 'LIKE', '%' . $searchLikeText . '%')
                               ->orWhere(DB::raw('replace(IFNULL(cell_phone, home_phone), "-", "")'), 'LIKE', str_replace(['-'], '', $searchLikeText))->orWhere('address_1', 'LIKE', $searchLikeText)->orWhere('address_2', 'LIKE', $searchLikeText)->orWhere('city', 'LIKE', $searchLikeText)->orWhere('state', 'LIKE', $searchLikeText)->orWhere('zip', 'LIKE',$searchLikeText);

                        });
                    });
            });
        }

        if (!empty($requestData['orderByColumn'])) {
            $orderBy = !empty($requestData['orderBy']) ? $requestData['orderBy'] : 'asc';
            $query->orderBy($requestData['orderByColumn'], $orderBy);
        }

        $query = $query->select([
            'households.*',
            \DB::Raw('IFNULL(sum(accounts.balance), 0) as total_balance')
        ]);

        $res = $query->paginate($requestData['paginationPerPage'], ['*'], 'page', $requestData['pageNo']);
        $result = $res->toArray();

        $collections = collect($result['data'])->map(function ($item, $key) use ($households, $user){
            if(!$user->IsInRole(['partial-user'])){
                $item['partial_assign'] = 1;
            }else{
                if (in_array($item['id'], $households) ) {
                    $item['partial_assign'] = 1;
                } else {
                    $item['partial_assign'] = 0;
                }
            }
            return $item;
        });

        $res = collect($res)->map(function ($item, $key) use ($collections){
            if($key == 'data'){
                $item = $collections;
            }
            return $item;
        });

        return $res;
    }

    public function insuranceHousehold($requestData, $user)
    {
        $orgId = $user->organization_id;
        $advisorId  = Advisors::where('user_id', $user->id)->pluck('id')->first();
        $employeeIds = Employee::where('user_id', $user->id)->where('selectable',1)->pluck('id');

        $with  = ['householdMembers.insurance', 'groupHouseholds', 'primaryMember', 'partner', 'status', 'householdEmployees'];
        $id    = $requestData['group_id'];

        $query = Households::with($with)
        ->leftJoin('household_status', function ($join) {
            $join->on('household_status.id', '=', 'households.status_id');
        })
        ->leftJoin('household_members', function ($join) {
            $join->on('household_members.id', '=', 'households.primary_member_id');
        })
        ->leftJoin('household_members_insurance', function ($join) {
            $join->on('household_members_insurance.household_member_id', '=', 'household_members.id');
        })
        ->leftJoin('partners', function ($join) {
            $join->on('partners.id', '=', 'households.partner_id');
        })
        ->where('households.org_id', $orgId);

        $households = [];

        if($user->IsInRole(['partial-user'])){
            $householdAdvisors  = [];
            $householdEmployees = [];

            if(!empty($advisorId)) {
                $householdAdvisors = Households::where('advisor_1_id', $advisorId)->orWhere('advisor_2_id', $advisorId)->get()->toArray();
                $householdAdvisors = array_column($householdAdvisors, 'id');
            }

            if(!empty($employeeIds)) {
                $householdEmployees = HouseholdEmployees::whereIn('employee_id', $employeeIds)->get()->toArray();
                $householdEmployees = array_column($householdEmployees, 'household_id');
            }

            $households = array_merge($householdAdvisors, $householdEmployees);
            $households = array_map("unserialize", array_unique(array_map("serialize", $households)));
        }

        if (is_numeric($id)) {
            $group = Group::where('id', $id)->first();

            switch ($group->name) {
                case 'Unassigned':
                    $cancelled_status_id = HouseholdStatus::where(['type' => 'insurance', 'value' => 'Cancelled'])->first()->id;
                    $query               = $query->whereNull('households.user_id')->where('households.status_id', '<>', $cancelled_status_id);
                    $query->whereHas('householdMembers', function ($q) use ($id) {
                        $q->whereNotIn('state', ['NY', 'HI', 'AK']);
                    });
                    break;

                case 'Recently Viewed':

                    $query->whereHas('groupHouseholds', function ($q) use ($id) {
                        $q->where('group_id', $id);
                    });

                    $query->orderBy('households.updated_at', 'desc');
                    break;

                case 'My Active':
                    $now = Carbon::now();
                    $now->subHours(72);

                    $engagedStatus = HouseholdStatus::where('type', 'insurance')
                        ->whereIn('value', ['Engaged (Text)', 'Engaged (Online)'])
                        ->pluck('id')
                        ->toArray();

                    $query->whereIn('status_id', $engagedStatus)->where('status_date', '>=', $now)->where('households.user_id', $user->id);
                    break;

                case 'Completed - Won':
                    $wonStatus = HouseholdStatus::where('type', 'insurance')
                        ->where('value', 'Closed - Won')
                        ->pluck('id')
                        ->first();

                    $query->where('status_id', $wonStatus);
                    break;

                case 'Completed - Lost':
                    $lostStatus = HouseholdStatus::where('type', 'insurance')
                        ->where('value', 'Closed - Lost')
                        ->pluck('id')
                        ->first();

                    $query->where('status_id', $lostStatus);
                    break;

                default:
                    $query->whereHas('groupHouseholds', function ($q) use ($id) {
                        $q->where('group_id', $id);
                    });
                    break;
            }

            $groupType = $group->type;
            if ($groupType == 'meeting') {
                $query->with(['meetings' => function ($q) {
                    $q->where('meeting', '>=', Carbon::parse(date('Y-m-d')));
                }]);
            }
        }

        if (!empty($requestData['searchListText'])) {
            $searchListText = $requestData['searchListText'];
            $searchLikeText = '%' . $searchListText . '%';
            $query->where(function ($q) use ($searchLikeText) {
                $q->where('households.name', 'LIKE', $searchLikeText)
                    ->orWhere('household_members_insurance.address_1', 'LIKE',$searchLikeText)
                    ->orWhereHas('householdMembers', function ($s) use ($searchLikeText) {
                        $s->where(function ($q) use ($searchLikeText) {
                            $q->where('email', 'LIKE', $searchLikeText)
                                ->orWhere(DB::raw('replace(IFNULL(cell_phone, home_phone), "-", "")'), 'LIKE', str_replace(['-'], '', $searchLikeText))->orWhere('address_1', 'LIKE', $searchLikeText)->orWhere('address_2', 'LIKE', $searchLikeText)->orWhere('city', 'LIKE', $searchLikeText)->orWhere('state', 'LIKE', $searchLikeText)->orWhere('zip', 'LIKE',$searchLikeText);
                        });
                    });
            });
        }

        $query = $query->select([
            'households.*',
            'partners.name as partner_name',
            'household_members_insurance.state as insurance_state',
            'household_status.value as status_name',
            'household_members_insurance.closing_date as closing_date',
            DB::raw('GREATEST(households.updated_at,household_members.updated_at, household_members_insurance.updated_at) as last_modified_date')
        ]);

        if (!empty($requestData['searchColumn'])) {
            foreach ($requestData['searchColumn'] as $key => $search) {
                switch ($key) {
                    case 'name':
                        $query->where('households.name', 'LIKE', '%' . $search . '%');
                        break;
                    case 'email':
                        $query->where('household_members.email', 'LIKE', '%' . $search . '%');
                        break;
                    case 'home_phone':
                        $query->where(DB::raw('IFNULL(household_members.cell_phone, household_members.home_phone)'), 'LIKE', '%' . $search . '%');
                        break;
                    case 'partner_name':
                        $query->where('partners.name', 'LIKE', '%' . $search . '%');
                        break;
                    case 'status_name':
                        $query->where('household_status.value', 'LIKE', '%' . $search . '%');
                        break;
                    case 'insurance_state':
                        $query->where('household_members_insurance.state', 'LIKE', '%' . $search . '%');
                        break;
                }
            }
        }

        if (!empty($requestData['dateFilter'])) {
            foreach ($requestData['dateFilter'] as $key => $search) {
                if (!$search['isAllSelect']) {
                    $map = array_filter(array_map(function($date)
                    {
                        if ($date['checked']) {
                            return $date['name'];
                        }
                    },$search['list']));
                    switch ($key) {
                        case 'created_at':
                            $query->whereIn(DB::raw("DATE_FORMAT(households.created_at, '%m-%d-%Y')"), $map);
                            break;

                        case 'closing_date':
                            $query->whereIn(DB::raw("DATE_FORMAT(household_members_insurance.closing_date, '%m-%d-%Y')"), $map);
                            break;

                        case 'last_modified_date':
                            $str = implode("','", $map);
                            $query->whereRaw("DATE_FORMAT(GREATEST(households.updated_at,household_members.updated_at, household_members_insurance.updated_at), '%m-%d-%Y') in ('$str')");
                            break;
                    }
                }
            }
        }

        if (!empty($requestData['orderByColumn'])) {
            $orderBy = isset($requestData['orderBy'][$requestData['orderByColumn']]) ? $requestData['orderBy'][$requestData['orderByColumn']] : 'asc';
            $query->orderBy($requestData['orderByColumn'], $orderBy);

        } else if (isset($group->name) && $group->name !== 'Recently Viewed') {
            $query->orderBy('households.created_at', 'desc');
        } else {}

        $date['last_modified_date'] = $query->pluck('last_modified_date')->toArray();
        $date['created_at'] = $query->pluck('households.created_at')->toArray();
        $date['closing_date'] = $query->pluck('household_members_insurance.closing_date')->toArray();

        foreach ($date as $key => &$value) {
            foreach ($value as $subKey => &$subValue) {
                $subValue = Carbon::parse($subValue)->format('m-d-Y');
            }
            $value = array_values(array_filter(array_unique($value)));
        }

        /*print_r($query->get()->toArray());
        exit();*/
        $res = $query->paginate($requestData['paginationPerPage'], ['*'], 'page', $requestData['pageNo']);

        $result = $res->toArray();

        $collections = collect($result['data'])->map(function ($item, $key) use ($households, $user){
            if(!$user->IsInRole(['partial-user'])){
                $item['partial_assign'] = 1;
            }else{
                if (in_array($item['id'], $households) ) {
                    $item['partial_assign'] = 1;
                } else {
                    $item['partial_assign'] = 0;
                }
            }
            return $item;
        });

        $res = collect($res)->map(function ($item, $key) use ($collections){
            if($key == 'data'){
                $item = $collections;
            }
            return $item;
        });
        $res['filter'] = $date;
        return $res;
    }

    public function paginateFund($requestData, $user)
    {
        $unsetArray    = ($requestData['orderBy'] != '') ? $requestData['orderBy'] : [];
        $orderBYsort   = end($unsetArray);
        $orderByColumn = key($unsetArray);
        $keyword       = isset($requestData['searchKeyword']) ? $requestData['searchKeyword'] : '';
        $lastVal       = $keyword != '' ? count($keyword) - 1 : '';
        $index         = 0;
        $filter        = '';
        $id            = $requestData['group_id'];

        if (!empty($orderByColumn) && !empty($orderBYsort)) {
            $orderBY = ' ORDER BY ' . $orderByColumn . ' ' . $orderBYsort;
        } else {
            $orderBY = '';
        }

        if ($id && $id != 'all') {
            $groupData = Group::where('id', $id)->get()->toArray();
            foreach ($groupData as $key => $value) {
                $fundIds = [];
                $groupId = $value['id'];

                $fundId = GroupsFund::where('group_id', $groupId)->get(['fund_id'])->toArray();
                $fundIds = array_column($fundId, 'fund_id');

            }
            if ($fundIds) {
                $fundIds = implode($fundIds, ',');
                $fundIds = isset($fundIds) ? $fundIds : '';
                if ($fundIds != '') {
                    $filter .= ' where f.id in (' . $fundIds . ')';
                }
            }
        }

        $filter .= ' group by fid ';

        if (isset($keyword) && $keyword != '') {
            foreach ($keyword as $column => $string) {
                if ($index == 0) {
                    $filter .= ' having ';
                }
                if ($index == $lastVal) {
                    $filter .= $column . ' like   "%' . $string . '%"';
                } else {
                    $filter .= $column . ' like  "%' . $string . '%" AND ';
                }
                $index++;
            }
        }

        $final_array = DB::select('
        select f.id as fid,
        f.uuid,
        fm.uuid as fmuuid,
        f.aum,
        f.name as fund_name,
        f.annual_management_fee as management_fee,
        f.gp_carry as carry_fee,
        f.gp_commitment as gp_commitment,
        ddwaterfall.display_value as waterfall,
        irr_target.display_value as irr_target,
        f.status,
        f.lp_preferred_return,
        fm.name as fund_manager_name,
        fm.num_of_funds,
        ac.name as asset_classes,
        (select GROUP_CONCAT(fss.name SEPARATOR", ") as name from `funds`  left join funds_strategies as fs on funds.id = fs.fund_id join  fund_strategy fss on fs.strategies_id = fss.id where fs.fund_id = f.id) as fund_strategy
        from funds as f
        join fund_managers as fm on f.fund_manager_id = fm.id
        join asset_classes as ac on ac.id = f.primary_asset_class_id
        left join fund_benchmarks as fb on fb.id = f.benchmark_id
        left join fund_dropdowns as ddwaterfall on f.waterfall = ddwaterfall.id
        left join fund_dropdowns as irr_target on f.irr_target = irr_target.id'
            . $filter . ' ' . $orderBY . ';'
        );

        $total      = count($final_array); //total items in array
        $limit      = $requestData['paginationPerPage']; //per page
        $totalPages = ceil($total / $limit); //calculate total pages
        $page       = max($requestData['pageNo'], 1);
        $page       = min($page, $totalPages);
        $offset     = ($page - 1) * $limit;
        if ($offset < 0) {
            $offset = 0;
        }

        $final_array = array_slice($final_array, $offset, $limit);
        return ['data' => $final_array, 'total' => $total];
    }

    public function paginateHouseholdFields($instance_type, $request = [])
    {
        // $fields = ['Name', 'Email', 'Phone Number', 'Total Net Worth', 'Custodian', 'Partner Firm', 'Status'];

        if (strtolower($instance_type) == 'insurance') {
            $fields = ['Name', 'Email', 'Phone Number', 'Partner Firm', 'Status', 'Created Date', 'Estimated Closing Date', 'State', 'Last Modified Date'];
        } else if (strtolower($instance_type) == 'fund') {
            $fields = ['Name', 'Email', 'Phone Number'];
        } else {
            $fields = ['Name', 'Email', 'Phone Number', 'Total Net Worth', 'Custodian'];

            $id = !empty($request['group_id']) ? $request['group_id'] : null;
            if ($id && $id != 'all') {
                $groupType = Group::find($id)->type;
                if ($groupType == 'meeting') {
                    $fields = ['Name', 'Email', 'Phone Number', 'Meeting Date', 'Source'];
                }
            }
        }
        return $fields;
    }

    public function getIncome($id)
    {
        $income_data  = [rand(1000, 100000), rand(1000, 100000), rand(1000, 100000)];
        $income_label = [];
        for ($i = 3; $i >= 1; $i--) {
            $income_label[] = date('F', strtotime('-' . $i . ' months'));
        }

        return [
            'data'  => $income_data,
            'label' => $income_label,
        ];
    }

    public function getIncomeWithdrawal($id)
    {
        $income_data     = [rand(1000, 100000), rand(1000, 100000), rand(1000, 100000)];
        $withdrawal_data = [rand(1000, 100000), rand(1000, 100000), rand(1000, 100000)];
        $income_label    = [];
        for ($i = 3; $i >= 1; $i--) {
            $income_label[] = date('F', strtotime('-' . $i . ' months'));
        }

        return [
            'incomeData'     => $income_data,
            'withdrawalData' => $withdrawal_data,
            'label'          => $income_label,
        ];
    }

    public function getTags()
    {
        $tags = ['Information', 'Popular', 'Recent'];
        return $tags;
    }

    public function createAnalysis($analysisData)
    {
        $data = $analysisData;

        if (array_key_exists('household', $data)) {
            if (array_key_exists('debt', $data['household'])) {
                $debt_data = $data['household']['debt'];
                unset($data['household']['debt']);
                $save_analysis = HouseholdAnalysis::updateOrCreate(['household_id' => $data['householdId']], $data['household']);
                if ($save_analysis) {
                    if (!empty($debt_data)) {
                        foreach ($debt_data as $key => $value) {
                            $create = HouseholdAnalysisDebts::updateOrCreate(['id' => $value['debt_id']], [
                                'household_analysis_id' => $save_analysis['id'],
                                'household_id'          => $data['householdId'],
                                'balance'               => $value['balance'],
                                'interest_rate'         => $value['interest_rate'],
                                'year_remaining'        => $value['year_remaining'],
                                'monthly_pmt'           => $value['monthly_pmt'],
                            ]);
                        }
                    }
                }
                return $save_analysis['id'];
            }
        }
    }

    public function runAnalysis($analysisData)
    {
        $analysis                  = [];
        $analysis['allocation']    = ["234111.86", "88967.85", "16108.9918", "1015791.0909"];
        $analysis['current_value'] = ["balance" => 1273031.53];

        $analysis['total_contribution'] = ["data" => ["id" => 2529, "account_id" => "3830", "transaction_id" => "0", "type" => "contribution", "amount" => "23045.790", "description" => "Deposit", "date" => "2018-03-07 00=>00=>00", "created_at" => "", "updated_at" => "", "transfer" => ""], "total" => 1323226.31];

        $analysis['total_withdrawal'] = ["data" => ["id" => 2529, "account_id" => "3830", "transaction_id" => "0", "type" => "contribution", "amount" => "23045.790", "description" => "Deposit", "date" => "2018-03-07 00:00:00", "created_at" => "", "updated_at" => "", "transfer" => ""], "total" => 127837.57];

        $analysis['total_income'] = ["totalIncome" => 6231, "MonthlyAvg" => 519, "IncomeData" => [["data" => [65, 59, 80, 81, 56, 55, 40], "lable" => "Series A"], ["data" => [28, 48, 40, 19, 86, 27, 90], "lable" => "Series B"], ["data" => [18, 48, 77, 9, 100, 27, 40], "lable" => "Series C"]], "IncomeLable" => ["January", "February", "March", "April", "May", "June", "July"]];

        return $analysis;
    }

    public function getAnalysis($household_id)
    {
        $data = HouseholdAnalysis::where('household_analysis.household_id', $household_id)
            ->with('householdAnalysisDebts')
            ->first();
        return $data;
    }

    public function removeDebtAnalysis($household_debt_analysis)
    {

        $data = HouseholdAnalysisDebts::where('id', $household_debt_analysis)
            ->delete();
        return true;
    }


    public function getConversations()
    {
        $conversations = Conversation::get()->toArray();
        return $conversations;
    }

    public function getDocuments($householdId, $userId)
    {
        $documents = Document::with('documentHousehold', 'user')->select('documents.*', 'users.email')
            ->leftjoin('users', 'documents.user_id', '=', 'users.id')
            ->where(['user_id' => $userId, 'household_id' => $householdId])
            ->orderBy('documents.id', 'desc')
            ->get();
        return $documents;
    }

    public function createNote($input, $userId)
    {
        $data = HouseholdNotes::firstOrCreate([
            'user_id'      => $userId,
            'household_id' => $input['household_id'],
            'title'        => $input['title'],
            'note'         => $input['note'],
        ]);


        try{
            $this->addNotesToIntegration($input['household_id'], $input['note']);
        }catch(\Exception $e){
            throw new \Exception($e->getMessage());
        }

        if ($input['question'] == 'yes') {

            if (strtolower($this->instance_type) == 'sales') {
                $task_type_id          = TaskTypes::where('name', 'Sales')->value('id');
                $status_id             = TaskStatus::where('name', 'Pending')->value('id');
                $accounts              = Accounts::where('household_id', $input['household_id'])->first();
                $due_date              = $this->getDate($input['select_time'], $input['date_time']);
                $defaultBenjaminUserId = User::where('name', 'Benjamin')->value('id');
                $employee_id           = Employee::where('name', 'Benjamin')->value('id');

                $task = Tasks::create([
                    'title'           => $input['title'],
                    'description'     => $input['note'],
                    'user_id'         => $defaultBenjaminUserId,
                    'household_id'    => $input['household_id'],
                    'conversation_id' => $input['conversation_id'],
                    'type_id'         => $task_type_id,
                    'status_id'       => $status_id,
                    'importance'      => 'active',
                    'due_date'        => $due_date,
                    'account_id'      => isset($accounts) ? $accounts['id'] : null,
                ]);

                $taskAssign = TasksAssignment::updateOrCreate([
                    'task_id'     => $task->id,
                    'employee_id' => $employee_id,
                ]);
            }
        }

        return $data;
    }

    public function getDate($date, $other_date)
    {
        $due_date = '';

        switch ($date) {
            case 'now':
                $due_date = date("Y-m-d");
                break;
            case 'today':
                $due_date = date("Y-m-d");
                break;
            case 'tomorrow':
                $due_date = date('Y-m-d', strtotime("+1 day"));
                break;
            case 'custom':
                $due_date = date('Y-m-d', strtotime($other_date));
            case 'other':
                $due_date = date('Y-m-d', strtotime($other_date));
                break;
        }

        return $due_date;
    }

    public function createReminder($reminderData, $orgId, $userId)
    {
        $dueDate  = date('Y-m-d h:i:s', strtotime($reminderData['remindDate'] . ' +1 day'));
        $response = Reminder::create(['org_id' => $orgId,
            'user_id'                              => $userId,
            'household_id'                         => $reminderData['household_id'],
            'note'                                 => $reminderData['note'],
            'due_date'                             => $dueDate]);
        return $response;
    }

    /**
     * upload document to s3 bucket
     *
     * @param document multipart form data
     * @param user id
     * @return uploaded documents url
     */
    public function uploadDocument($data, $user)
    {
        if ($data['file']) {
            // store in s3 directory
            $fileDetails = $data['file'];
            $orgGuid     = $this->org;
            if ($data['householdId'] && $data['householdId'] != "null") {
                $householdId = $data['householdId'];
            } else {
                $householdId = 'all';
            }

            $fileName     = urlencode(preg_replace('/[^A-Za-z0-9\-]/', '-', $data['documentName']) . '_' . time()) . '.' . $fileDetails->getClientOriginalExtension();
            $fileContents = file_get_contents($fileDetails);
            $s3           = \Storage::disk('s3');
            $filePath     = $orgGuid . '/' . $householdId . '/' . $fileName;
            $s3->put($filePath, $fileContents, 'private');

            $res = Document::create([
                'org_id'         => $user->organization_id,
                'user_id'        => $user->id,
                'household_id'   => $householdId,
                'document_title' => $data['documentName'],
                'document_path'  => $filePath,
                'document'       => $fileName,
                'folder_id'      => ((!empty($data['folderId']) && $data['folderId'] != 'NaN' && $data['folderId'] != '')) ? $data['folderId'] : null,
            ]);

            return $res;
        }

        return false;
    }

    /**
     * add tags to the document
     *
     * @param tags array
     * @param document id
     */
    public function documentTags($documentId, $tags)
    {
        foreach ($tags as $key => $value) {
            DocumentTag::create(['document_id' => $documentId, 'tag' => $value]);
        }
        return true;
    }

    /**
     * get presigned url for the document
     *
     *@param document id
     *@return document url

     */
    public function getDocumentUrl($docData)
    {
        $orgGuid  = $this->org;
        $document = Document::where('id', $docData['document_id'])->first();
        if ($docData['household_id']) {
            $url = $orgGuid . '/' . $docData['household_id'] . '/' . $document->document;
        } else {
            $url = $orgGuid . '/all/' . $document->document;
        }
        // get s3 presigned url from the document url
        $bucket = config('benjamin.aws_s3_uploads_bucket');
        $key    = $url;
        $s3     = \Storage::disk('s3');

        $client = $s3->getDriver()->getAdapter()->getClient();
        $expiry = "+10 minutes";

        $command = $client->getCommand('GetObject', [
            'Bucket' => $bucket,
            'Key'    => $key,
        ]);

        $request = $client->createPresignedRequest($command, $expiry);
        $docUrl  = (string) $request->getUri();

        //print_r($docUrl);die;

        //check is image

        $isImage = 0;

        $pattern = '/[\w\-]+\.(jpg|png|gif|jpeg|jpg)/';
        $result  = preg_match($pattern, $docUrl);
        if ($result) {
            $isImage = 1;
        }

        return [
            'data'          => $docUrl,
            'content'       => utf8_encode(file_get_contents($docUrl)),
            'image_content' => base64_encode(file_get_contents($docUrl)),
            'is_image'      => $isImage,
            'name'          => $document->document_title . '_' . $document->document,
        ];
    }

    /**
     * search document by name and their tag.
     *
     * @param search keyword
     * @return search result
     */
    public function searchDocuments($searchKeyword)
    {
        $searchResult = \DB::select("select d.*,u.email  from documents d  join document_tags dt on d.id = dt.document_id join users u on d.user_id = u.id where d.document LIKE '%" . $searchKeyword . "%'  OR dt.tag LIKE '%" . $searchKeyword . "%' group by d.id");
        return $searchResult;
    }

    public function advisorGetAll()
    {

        $data['advisors'] = Advisors::orderBy('name')->get();

        return [
            'code'   => 200,
            'result' => $data,
        ];
    }

    public function advisorGet($id)
    {
        $household = Households::find($id);

        $array_advisor = [$household->advisor_1_id, $household->advisor_2_id];

        $data['advisors'] = Advisors::with('employee_assistant')->whereIn('id', $array_advisor)->get();

        $data['advisors'] = $data['advisors']->map(function ($value) use ($household) {
            if ($value['id'] == $household->advisor_1_id) {
                $value['advisors_index'] = 'advisor_1_id';
                $value['sort_by'] = 1;
            } else {
                $value['advisors_index'] = 'advisor_2_id';
                $value['sort_by'] = 2;
            }
            return $value;
        });

        $data['advisors'] = $data['advisors']->toArray();

        usort($data['advisors'], function($a, $b) {
            return $a['sort_by'] <=> $b['sort_by'];
        });

        return [
            'code'   => 200,
            'result' => $data,
        ];
    }

    public function updateHousehold($data)
    {

        $householdData = [
            'name'                    => $data['household_name'],
            'preferred_communication' => $data['preferred_communication'],
            'progressive'             => null,
            'no_bill'                 => null,
            'fee'                     => null,
        ];

        switch ($data['fee']) {
            case 'other':
                $householdData['fee'] = $data['feeOther'];
                break;
            case 'progressive':
                $householdData['progressive'] = 1;
                break;
            case 'no_bill':
                $householdData['no_bill'] = 1;
                break;
            default:
                $householdData['fee'] = $data['fee'];
                break;
        }

        $householdData['opt_out']     = $data['clientOptOut'];
        $householdData['client_info'] = $data['clientInfoUpdate'];

        $household = Households::updateOrCreate(['id' => $data['householdId']], $householdData);

        unset($householdData['name']);
        unset($householdData['opt_out']);
        unset($householdData['client_info']);

        //Accounts::updateOrCreate(['id' => $household->account_id], $householdData);
        return true;
    }

    public function syncJunxureSales()
    {
        $junxurePowerData = $this->junxure->powerSearchID($this->junxureUserId, $this->powerSearchID);
        if ($junxurePowerData) {
            foreach ($junxurePowerData->ListData as $key => $value) {
                $junxureData = $this->junxure->getRecordbyId($this->junxureUserId, $value->RecordID);

                if ($junxureData) {
                    $firstName = $junxureData->RecordDetail->First_Name_Person_1;
                    $lastName  = $junxureData->RecordDetail->Last_Name_Person_1;

                    //if person_1 not set, get from record name
                    if (empty($firstName)) {

                        $recordName = $junxureData->RecordDetail->Record_Name;

                        $name_split = explode('-', $recordName);

                        if (count($name_split) >= 2) {
                            $lastName = trim($name_split[0]);

                            if (strpos($name_split[1], '&') !== false) {
                                //2 contacts
                                $first_names = explode('&', $name_split[1]);

                                $firstName  = trim($first_names[0]);
                                $firstName2 = trim($first_names[1]);
                            } else {
                                $firstName = trim($name_split[1]);
                            }
                        }

                    }

                } else {
                    $firstName = "";
                    $lastName  = "";
                }

                $member = HouseholdMembers::where('email', $junxureData->RecordDetail->Email_Record)->first();
                if ($member) {
                    $household = Households::find($member->household_id);
                } else {
                    $household = new Households();
                }

                $household->name = $junxureData->RecordDetail->Record_Name;
                $household->save();

                $householdId      = $household->id;
                $HouseholdMembers = HouseholdMembers::updateOrCreate([
                    'email'        => $junxureData->RecordDetail->Email_Record,
                    'household_id' => $householdId,
                ], ['org_id' => 1,
                    'first_name' => $firstName,
                    'last_name'  => $lastName,
                    'cell_phone' => $junxureData->RecordDetail->Phone_Primary_Record,
                ]
                );
            }
        }

        return true;
    }

    public function saveProspect($input)
    {
        $statusId    =  HouseholdStatus::where('type', 'Prospect')->where('value', 'Unscheduled')->value('id');
        //if no email, set to unknown and add new household

         if (!isset($input['id']) || empty($input['id'])) {
            $input['id'] = '';
        }

        if (!isset($input['email']) || empty($input['email'])) {
            $input['email'] = 'unknown@unknown.com';
        }

        if ($input['email'] == 'unknown@unknown.com') {
            $member = null;
        } else {
            $member = HouseholdMembers::where('email', $input['email'])->first();
        }

        if ($member) {
            $household = Households::find($member->household_id);
        } else {
            $household = new Households();

            $household->name      = $input['firstName'] . ' ' . $input['lastName'];
            $household->prospect  = 1;
            $household->org_id    = 1;
            $household->status_id = $statusId;
        }

        if (!empty($input['ciaReferred'])) {
            if ($input['ciaReferred'] == 'yes') {
                $household->cia_referred = 1;
            } else {
                $household->cia_referred = 0;
            }
        }

        if (isset($input['estimateValue'])) {
            $household->estimate = $input['estimateValue'];
        }

        if (!empty($input['advisorId'])) {
            $household->advisor_1_id = $input['advisorId'];
        }

        if (!empty($input['sourceId'])) {
            $household->source_id = $input['sourceId'];
        }

        if (!empty($input['householdId'])) {
            $household->referred_household_id = $input['householdId'];
        }

        if (!empty($input['householdMemberId'])) {
            $household->referred_household_member_id = $input['householdMemberId'];
        }

        if (!empty($input['channelId'])) {
            $household->channel_id = $input['channelId'];
        }

        if (!empty($input['moneyManaged'])) {
            $household->money_managed = $input['moneyManaged'];
        }

        if (!empty($input['goal'])) {
            $household->goal = $input['goal'];
        }

        if (!empty($input['year'])) {
            $household->year = $input['year'];
        }

        $household->save();

        if ($household->wasRecentlyCreated) {
            event(new AddToGroup($household, 'Active Prospects'));
        }

        $householdId = $household->id;
        $type_id     = HouseholdMemberTypes::where('type','Primary')->value('id');

        //if unknown, always create

        if ($input['email'] == 'unknown@unknown.com') {

            $member = HouseholdMembers::updateOrCreate([
                'id'        => $input['id']
            ], [
                'email'        => $input['email'],
                'first_name'   => $input['firstName'],
                'last_name'    => $input['lastName'],
                'home_phone'   => isset($input['homePhone']) ? $input['homePhone'] : null,
                'cell_phone'   => isset($input['cellPhone']) ? $input['cellPhone'] : null,
                'work_phone'   => isset($input['workPhone']) ? $input['workPhone'] : null,
                'org_id'       => 1,
                'type_id'      => $type_id,
                'household_id' => $householdId
            ]);

        }else{

            $member = HouseholdMembers::updateOrCreate([
                'id'        => $input['id']
            ], [
                'email'        => $input['email'],
                'household_id' => $householdId,
                'first_name'   => $input['firstName'],
                'last_name'    => $input['lastName'],
                'home_phone'   => isset($input['homePhone']) ? $input['homePhone'] : null,
                'cell_phone'   => isset($input['cellPhone']) ? $input['cellPhone'] : null,
                'work_phone'   => isset($input['workPhone']) ? $input['workPhone'] : null,
                'org_id'       => 1,
                'type_id'      => $type_id
            ]);
        }

        $meeeting = HouseholdMeetings::where('household_id', $householdId)->first();
        if (!$meeeting) {
            event(new AddToGroup($household, 'Unscheduleds'));
        }

        $household = Households::find($householdId);
        $household->primary_member_id = $member->id;
        $household->save();

        $household = Households::with(['householdMembers', 'primaryMember'])->find($householdId);

        if (config('benjamin.hubspot')) {
            if ($input['email'] != 'unknown@unknown.com') {
                $this->hubspotRepository->addProspectToHubspot($household);
            }
        }

        return $household;
    }

    public function saveFormProspect($input)
    {
        $member = HouseholdMembers::where('email', $input['email'])->first();
        if ($member) {
            $household = Households::find($member->household_id);
        } else {
            $household = new Households();
        }

        $household->name     = $input['name'];
        $household->prospect = 1;

        if (!empty($input['householdId'])) {
            $household->referred_household_id = $input['householdId'];
        }

        if (!empty($input['householdMemberId'])) {
            $household->referred_household_member_id = $input['householdMemberId'];
        }

        $household->save();

        if ($household->wasRecentlyCreated) {
            event(new AddToGroup($household, 'Active Prospects'));
        }

        $householdId = $household->id;

        $input['miscellaneous_form_fields'] = $this->setMiscellaneousFormFields($input);

        $member = HouseholdMembers::updateOrCreate([
            'email'        => $input['email'],
            'household_id' => $householdId,
        ], ['org_id'                => 1,
            'first_name'                => $input['firstName'],
            'last_name'                 => $input['lastName'],
            'cell_phone'                => $input['cell_phone'],
            'miscellaneous_form_fields' => $input['miscellaneous_form_fields'],
            'type_id'                   => 1
        ]);

        $meeeting = HouseholdMeetings::where('household_id', $householdId)->first();
        if (!$meeeting) {
            event(new AddToGroup($household, 'Unscheduleds'));
        }

        $household                    = Households::find($householdId);
        $household->primary_member_id = $member->id;
        $household->save();

        $dArray = Households::with('householdMembers')->find($householdId);

        //return $dArray;

        $pdf = $this->saveFormProspectGeneratePDF($input);
        //return view('pdf/createProspectForm', $input);
        return $pdf;
    }

    public function setMiscellaneousFormFields($value)
    {
        //'children', 'client_a',client_b, insurance, investments
        $data = [];
        $key  = ['advisor', 'children', 'client_a', 'client_a_other', 'client_a_pension', 'client_a_salary', 'client_a_social_security', 'client_b', 'client_b_other', 'client_b_pension', 'client_b_salary', 'client_b_social_security', 'date', 'debt_auto', 'debt_cc_number', 'debt_home_equity', 'debt_other', 'estate_plan', 'goals_and_object', 'insurance', 'investments', 'level', 'other', 'potential_annual', 'potential_annual_gain', 'primary_resident', 'referral', 'referral_by', 'total_income', 'total_other', 'total_pension', 'total_salary', 'total_social_security', 'estimated_monthly_needs', 'monthly_retirement_income', 'shortfall_surplus'];

        $array_val = ['children', 'client_a', 'client_b', 'insurance', 'investments'];

        foreach ($key as $k => $v) {
            $data[$v] = $value[$v];
        }

        return $data;
    }

    public function saveFormProspectGeneratePDF($data)
    {

        view()->share('prospect', $data);
        $pdf = PDF::loadView('pdf/createProspectForm');

        $pdf->setPaper('A4', 'landscape');
        $pdf = $pdf->stream();

        $fileName = urlencode(preg_replace('/[^A-Za-z0-9\-]/', '-', 'prospect-form') . '.pdf');

        $household    = $data['householdId'];
        $fileContents = $pdf->getOriginalContent();
        $s3           = \Storage::disk('s3');
        $filePath     = $household . '/all/' . $fileName;

        $s3->put($filePath, $fileContents, 'public');

        $url = \Storage::disk('s3')->url($filePath);

        return [
            'file' => $fileName,
            'path' => $filePath,
            'url'  => $url,
        ];
    }

    public function addHouseholdtoGroup($groupId, $defaultGroup, $userId)
    {
        $groupName = Group::find($groupId)->name;

        switch ($groupName) {
            case 'On-boarding':
                return $this->onBoardingHousehold($defaultGroup, $userId, $groupId);
                break;
        }

        return true;
    }

    public function onBoardingHousehold($defaultGroup, $userId, $groupId)
    {
        $acccountArr = [];
        $task_id     = TaskTypes::where('name', 'Anticipated New Account')->value('id');

        $status_id = TaskStatus::where('name', 'Pending')->value('id');


        $household_id = $defaultGroup['householdId'];
        // foreach ($defaultGroup['selectAccounts'] as $key => $clients) {
        foreach ($defaultGroup['selectedPaperworks'] as $key => $accountData) {
            $res = HouseholdAnticipatedAccount::create([
                'household_id'        => $household_id,
                'household_member_id' => $accountData['member']['id'],
                'account_type_id'     => $accountData['id'],
                'source'              => isset($accountData['fund']) ? $accountData['fund'] : null,
                'account_number'      => isset($accountData['account_number']) ? $accountData['account_number'] : null,
                'amount'              => isset($accountData['amount']) ? $accountData['amount'] : '0.00',
            ]);

            $accountType      = isset($accountData['name']) ? $accountData['name'] : '';
            $household_member = HouseholdMembers::find($accountData['member']['id']);
            $source           = isset($accountData['fund']) ? $accountData['fund'] : '';
            $taskTitle        = $household_member['first_name'] . " " . $accountType . " " . $source;

            $task = Tasks::create([
                'title'                  => $taskTitle,
                'user_id'                => $userId,
                'household_id'           => $household_id,
                'conversation_id'        => 0,
                'type_id'                => $task_id,
                'status_id'              => $status_id,
                'type_id'                => $task_id,
                'anticipated_account_id' => $res->id,
                'importance'             => 'high',
                'due_date'               => date('Y-m-d', strtotime('+1 Weekday')),
            ]);

            $employee = Employee::where('user_id', $userId)->first();

            if ($employee) {
                $taskAssign = TasksAssignment::updateOrCreate([
                    'task_id'     => $task->id,
                    'employee_id' => $employee->id,
                ]);
            }

            // clone conversations set to conversation_household table
            $this->cloneConversationHousehold($groupId, $household_id);
        }

        Households::updateOrCreate([
            'id' => $household_id,
        ], [
            'custodian_id' => $defaultGroup['custodian'],
        ]);
        // }

        return $res;
    }
    public function cloneConversationHousehold($groupId, $householdId)
    {
        $groupConversations = ConversationsGroup::where('group_id', $groupId)->get();
        $household          = Households::where('id', $householdId)->first();

        if (!empty($groupConversations)) {
            foreach ($groupConversations as $key => $conversation) {
                $householdConversations = ConversationsHouseholds::updateOrCreate([
                    'household_id'            => $householdId,
                    'conversation_library_id' => $conversation['conversation_library_id'],
                ], [
                    'user_id'             => $conversation['user_id'],
                    'document_id'         => $conversation['document_id'],
                    'cadence'             => $conversation['cadence'],
                    'notify'              => $conversation['notify'],
                    'send'                => $conversation['send'],
                    'recurring'           => $conversation['recurring'],
                    'household_member_id' => $household->primary_member_id,
                ]);
            }
        }
    }

    public function getCloseStatusId()
    {
        $status = HouseholdStatus::where('type', 'Client')->where('value', 'Closed')->first();
        if ($status) {
            $statusId = $status->id;
        } else {
            $statusId = 16;
        }

        return $statusId;
    }

    public function closeProspect($input, $userId)
    {
        $statusId = $this->getCloseStatusId();

        //log entry
        HouseholdStatusLog::create([
            'household_id' => $input['id'],
            'status_id'    => $statusId,
            'user_id'      => $userId,
        ]);

        $household            = Households::find($input['id']);
        $household->status_id = $statusId;
        $household->save();
        $household->status;

        $this->hubspotRepository->updateProspectInProgress($household);


         //update any call tasks for this household
         $callTasks = Tasks::with(['type' => function($query) {
            $query->where('slug', 'call');
            }])->where('household_id', $household->id)->where('status_id', 1)->get();

        foreach($callTasks as $t){
            $t->status_id= 2;
            $t->save();
        }

        return $household;
    }

    public function getHouseholdByUUID($input)
    {

        $household    = Households::with(['primaryMember'])->where('uuid', $input['hash'])->first();
        $statusOnline = HouseholdStatus::where(['type' => 'insurance', 'value' => 'Engaged (Online)'])->first();
        $statusText   = HouseholdStatus::where(['type' => 'insurance', 'value' => 'Engaged (Text)'])->first();

        if (!isset($input['method'])) {
            $input['method'] = 'widget';
        }

        if ($input['method'] == 'widget') {
            $status = $statusOnline;
        } else {
            $status = $statusText;
        }

        if ($household) {

            $household->status_id = $status->id;

            HouseholdStatusLog::create([
                'household_id' => $household->id,
                'status_id'    => $status->id,
                'user_id'      => 0,
            ]);

            $household->save();

            event(new AddToGroup($household, 'Engaged Prospects', null, true));

            return $household;

        } else {

            $fullName  = $input['name'];
            $partnerId = ($input['partner_id']) ?? null;

            $fullName = str_ireplace(['/', '#'], '', $fullName);
            $fullName = str_ireplace('-', ' ', $fullName);

            $household = Households::create([
                'name'       => $fullName,
                'org_id'     => 1,
                'status_id'  => $status->id,
                'partner_id' => $partnerId,
                'prospect'   => 1,
            ]);

            if ($household->wasRecentlyCreated) {
                event(new AddToGroup($household, 'Active Prospects'));
            }

            HouseholdStatusLog::create([
                'household_id' => $household->id,
                'status_id'    => $status->id,
                'user_id'      => 0,
            ]);

            $householdId = $household->id;
            $splitName   = explode(' ', $fullName, 2);
            $firstName   = $splitName[0];
            $lastName    = !empty($splitName[1]) ? $splitName[1] : '';

            $householdMember = HouseholdMembers::updateOrCreate([
                'household_id' => $householdId,
            ], [
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'type_id'    => 1,
                'org_id'     => 1,
            ]);

            $household->primary_member_id = $householdMember->id;
            $household->save();

            return Households::with('primaryMember')->where('id', $household->id)->first();

        }

    }

    public function getHouseholdByIntegrationId($input)
    {

        $integration = HouseholdIntegration::where('intergration', 'widget')->where('intergration_id', $input['data']['name'])->first();

        if ($integration) {
            return Households::with('primaryMember')->where('id', $integration->household_id)->first();
        } else {


            //#/matt-reiner/
            $fullName = $input['data']['name'];

            $fullName = str_ireplace(['/', '#'], '', $fullName);
            $fullName = str_ireplace('-', ' ', $fullName);

            $household = Households::create([
                'name'      => $fullName,
                'org_id'    => 1,
                'status_id' => 1,
            ]);

            $householdId = $household->id;

            $splitName = explode(' ', $fullName, 2);
            $firstName = $splitName[0];
            $lastName  = !empty($splitName[1]) ? $splitName[1] : '';

            $householdMember = HouseholdMembers::updateOrCreate([
                'household_id' => $householdId,
            ], [
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'type_id'    => 1,
                'org_id'     => 1,
            ]);

            $household->primary_member_id = $householdMember->id;

            $defaultAdvisorUserId  = $this->settingsRepository->getInstanceSetting('default_advisor_user_id');

            if ($defaultAdvisorUserId){
                $advisor = Advisors::where('user_id', $defaultAdvisorUserId)->first();
                    if($advisor){
                        $household->advisor_1_id = $advisor->id;
                    }
             }else{
                $household->advisor_1_id  = 1;
             }


             $defaultTeamMembers = explode(',', $this->settingsRepository->getInstanceSetting('default_team_members_user_ids'));

             if (count($defaultTeamMembers) >= 1){
                 //lookup employeeIds
                 $employees = Employee::whereIn('user_id', $defaultTeamMembers)->get();


                     foreach($employees as $employee){
                         HouseholdEmployees::updateOrCreate([
                             'household_id' => $householdId,
                             'employee_id' => $employee->id
                         ], [
                             'role_id' => 9
                         ]);

                     }


             }


            $household->prospect          = 1;
            $household->save();

            $integration = HouseholdIntegration::firstOrCreate([
                'household_id' => $householdId,
                'intergration' => 'widget',
            ], [
                'intergration_id' => $input['data']['name'],
            ]);

            return Households::with('primaryMember')->where('id', $integration->household_id)->first();

        }

    }

    public function getHouseholdByName($input)
    {
        //#/matt-reiner/
        if (array_key_exists('name', $input)){
            $fullName = $input['name'];
        } else {
            $fullName = $input['data']['name'];
        }

        $fullName = str_ireplace(['/', '#'], '', $fullName);
        $fullName = str_ireplace('-', ' ', $fullName);

        $household = Households::where('name', $fullName)->first();

        if ($household) {
            $household =  Households::with('primaryMember')->where('id', $household->id)->first();


            $defaultTeamMembers = explode(',', $this->settingsRepository->getInstanceSetting('default_team_members_user_ids'));

            if (count($defaultTeamMembers) >= 1){
                //lookup employeeIds
                $employees = Employee::whereIn('user_id', $defaultTeamMembers)->get();

                    foreach($employees as $employee){

                        HouseholdEmployees::updateOrCreate([
                            'household_id' => $household->id,
                            'employee_id' => $employee->id
                        ], [
                            'role_id' => 9
                        ]);

                    }



            }

            return $household;

        } else {

            $household = Households::create([
                'name'      => $fullName,
                'org_id'    => 1,
                'status_id' => 1,
            ]);

            $householdId = $household->id;

            $splitName = explode(' ', $fullName, 2);
            $firstName = $splitName[0];
            $lastName  = !empty($splitName[1]) ? $splitName[1] : '';

            $householdMember = HouseholdMembers::updateOrCreate([
                'household_id' => $householdId,
            ], [
                'first_name' => $firstName,
                'last_name'  => $lastName,
                'type_id'    => 1,
                'org_id'     => 1,
            ]);

            $household->primary_member_id = $householdMember->id;

            $defaultAdvisorUserId  = $this->settingsRepository->getInstanceSetting('default_advisor_user_id');

            if ($defaultAdvisorUserId){
                $advisor = Advisors::where('user_id', $defaultAdvisorUserId)->first();
                    if($advisor){
                        $household->advisor_1_id = $advisor->id;
                    }
             }else{
                $household->advisor_1_id  = 1;
             }


             $defaultTeamMembers = explode(',', $this->settingsRepository->getInstanceSetting('default_team_members_user_ids'));

             if (count($defaultTeamMembers) >= 1){
                 //lookup employeeIds
                 $employees = Employee::whereIn('user_id', $defaultTeamMembers)->get();

                     foreach($employees as $employee){
                        foreach($employees as $employee){

                            HouseholdEmployees::updateOrCreate([
                                'household_id' => $household->id,
                                'employee_id' => $employee->id
                            ], [
                                'role_id' => 9
                            ]);

                        }

                     }



             }



            $household->prospect          = 1;
            $household->save();

            return Households::with('primaryMember')->where('id', $household->id)->first();

        }

    }

    public function ezlynxUpload($householdDetail)
    {
        $household_member_id = $householdDetail['householdMemberId'];
        $members             = HouseholdMembers::where('id', $household_member_id)->first();

        $xmlArray    = [];
        $rootElement = [
            'rootElementName' => 'EZHOME',
            '_attributes'     => [
                'xmlns:xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
                'xmlns:xsd' => 'http://www.w3.org/2001/XMLSchema',
                'xmlns'     => 'http://www.ezlynx.com/XMLSchema/Home/V200',
            ],
        ];

        $xmlArray = array_filter_recursive($members->xmlRequest);

        $newxmlArray = ArrayToXml::convert($xmlArray, $rootElement);
        $url         = $this->createXmlUrlByArray($newxmlArray);

        return $this->uploadFile($household_member_id, $url);
    }

    public function createXmlUrlByArray($input)
    {
        $s3       = \Storage::disk('s3');
        $filePath = 'infi/ezlynx-xml/' . date('YmdHis') . '.xml';
        $s3->put($filePath, $input, 'public');
        return \Storage::disk('s3')->url($filePath);
    }

    public function uploadFile($household_member_id, $url)
    {
        $fileContent = base64_encode(file_get_contents($url));
        $response    = $this->ezlynx->uploadFile($household_member_id, $fileContent);
        return $response;
    }

    public function createInsuranceEzlynx($household_id, $household_member_id, $json)
    {
        try {
            InsuranceEzlynx::updateOrCreate(['household_id' => $household_id, 'household_member_id' => $household_member_id], ['json' => $json]);
        } catch (Exception $e) {
            \Log::debug($e->getMessage());
        }
    }

    public function getEzlynxUpload($household_id, $household_member_id)
    {
        try {
            $infiEzlynx = InsuranceEzlynx::where('household_id', $household_id)
            // ->where('household_member_id', $household_member_id)
                ->latest()
                ->first();
            if ($infiEzlynx) {
                return $infiEzlynx->json;
            }
            return true;
        } catch (Exception $e) {
            \Log::debug($e->getMessage());
        }
    }

    public function getHouseholdByStatusId($id)
    {

        $households = Households::from('households AS h')
            ->select(
                'h.id As household_id',
                'h.name As household_name',
                'h.status_id As status_id',
                'ad.name As advisor',
                'a.name As account_name',
                'a.account_number',
                'a.id As account_id',
                'a.balance',
                'hs.value As household_status'
            )
            ->leftjoin('advisors AS ad', 'ad.id', '=', 'h.advisor_1_id')
            ->join('accounts AS a', 'a.id', '=', 'h.account_id')
            ->join('household_status AS hs', 'hs.id', '=', 'h.status_id')
            ->where('h.status_id', $id);

        $accounts = Accounts::from('accounts AS a')
            ->select(
                'h.id As household_id',
                'h.name As household_name',
                'h.status_id As status_id',
                'ad.name As advisor',
                'a.name As account_name',
                'a.account_number',
                'a.id As account_id',
                'a.balance',
                'hs.value AS account_status'
            )
            ->leftjoin('advisors AS ad', 'ad.id', '=', 'a.advisor_1_id')
            ->join('households AS h', 'h.id', '=', 'a.household_id')
            ->join('household_status AS hs', 'hs.id', '=', 'a.status_id')
            ->where('a.status_id', $id)
            ->union($households)
            ->get()
            ->toArray();

        return $accounts;
    }

    public function updateOnboardingStatus($request, $userId)
    {
        $household   = Households::where('id', $request['household_id'])->first();
        $isset       = isset($request['new_status']);
        $deletegroup = ($request['new_status'] === 'delete-from-group');
        $received    = ($request['new_status'] === 'paperwork-received');

        if ($isset && $deletegroup) {
            $data = ConversationsHouseholds::where('household_id', $request['household_id'])->first();
            if ($data) {
                $data->delete();
            }
        }

        if ($isset && $received) {
            $conversation_library_id = ConversationLibrary::where('name', 'Paperwork Received')
                ->first()->id;

            Conversation::create([
                'household_id'            => $request['household_id'],
                'conversation_library_id' => $conversation_library_id,
                'account_id'              => $request['account_id'],
            ]);

            $householdStatus = HouseholdStatus::where('value', 'Accounts Opened')->first();

            $account            = Accounts::where('id', $request['account_id'])->first();
            $account->status_id = $householdStatus->id;
            $account->save();

            $household->status_id = $householdStatus->id;
            $household->save();
        }

        return $household;

    }

    public function saveHouseholdByHash($householdId, $request)
    {
        $phones = array_column($request['phones'], 'phone_number', 'type');
        $emails = array_column($request['emails'], 'email', 'type');

        if (!empty($emails['personal'])) {
            $email = $emails['personal'];
        } else if (!empty($emails['work'])) {
            $email = $emails['work'];
        } else {
            $email = '';
        }

        $households = Households::updateOrCreate([
            'id' => $householdId,
        ], [
            'name'      => $request['first_name'] . ' ' . $request['last_name'],
            'address_1' => $request['address_1'],
        ]);

        HouseholdMembers::updateOrCreate([
            'id'           => $request['household_member_id'],
            'household_id' => $householdId,
        ], [
            'org_id'               => 1,
            'email'                => $email,
            'first_name'           => $request['first_name'],
            'middle_name'          => $request['middle_name'],
            'last_name'            => $request['last_name'],
            'dob'                  => $request['dob'],
            'ssn'                  => $request['ssn'],
            'home_phone'           => (!empty($phones['home'])) ? $phones['home'] : null,
            'cell_phone'           => (!empty($phones['mobile'])) ? $phones['mobile'] : null,
            'work_phone'           => (!empty($phones['work'])) ? $phones['work'] : null,
            'occupation'           => $request['occupation_employer'],
            'address_1'            => $request['address_1'],
            'miscellaneous_fields' => [
                'annual_income' => $request['annual_income'],
                'net_worth'     => $request['net_worth'],
                'children'      => $request['children'],
                'emails'        => $request['emails'],
                'phones'        => $request['phones'],
            ],
        ]);

        return $request;
    }

    public function updateClientInformation($householdId, $clientInfo)
    {
        $household       = Households::find($householdId);
        $primaryMemberId = $household->primary_member_id;

        $mobilePhone   = '';
        $homePhone     = '';
        $workPhone     = '';
        $homeAddress   = '';
        $workAddress   = '';
        $personalEmail = '';
        $workEmail     = '';

        foreach ($clientInfo['phones'] as $key => $phones) {
            if ($phones['type'] == 'mobile') {
                $mobilePhone = $phones['phone_number'];
            }

            if ($phones['type'] == 'home') {
                $homePhone = $phones['phone_number'];
            }

            if ($phones['type'] == 'work') {
                $workPhone = $phones['phone_number'];
            }
        }

        foreach ($clientInfo['emails'] as $key => $emails) {
            if ($emails['type'] == 'personal') {
                $personalEmail = $emails['email'];
            }

            if ($emails['type'] == 'work') {
                $workEmail = $emails['email'];
            }
        }

        foreach ($clientInfo['addresses'] as $key => $addresses) {
            if ($addresses['type'] == 'home') {
                $homeAddress = $addresses['address'];
            }

            if ($addresses['type'] == 'work') {
                $workAddress = $addresses['address'];
            }
        }

        $houseHoldMember = HouseholdMembers::where('id', $primaryMemberId)->update([
            'org_id'     => 1,
            'home_phone' => $homePhone,
            'cell_phone' => $mobilePhone,
            'work_phone' => $workPhone,
            'address_1'  => $homeAddress,
            'email'      => $personalEmail,
            'work_email' => $workEmail,
        ]);

        $cpa = HouseholdCpa::updateOrCreate([
            'household_id' => $householdId,
        ], [
            'name'  => $clientInfo['cpa_name'],
            'phone' => $clientInfo['cpa_phone'],
            'email' => $clientInfo['cpa_email'],
        ]);

        $householdAddress = HouseholdAddress::updateOrCreate([
            'household_id' => $householdId,
        ], [
            'type'    => 'work',
            'address' => $workAddress,
        ]);
        $members = HouseholdMembers::where('household_id', $householdId)
            ->where('active', true)
            ->pluck('id')->all();

        HouseholdMemberBeneficiaries::whereIn('household_member_id', $members)->delete();

        foreach ($clientInfo['contingent_beneficiary'] as $key => $benificiary) {
            if ($benificiary['household_member_id']) {
                $contingentBeneficiary = HouseholdMemberBeneficiaries::updateOrCreate([
                    'household_member_id' => $benificiary['household_member_id'],
                    'type'                => 'contingent',
                ]);
            }
        }

        foreach ($clientInfo['primary_beneficiary'] as $key => $benificiary) {
            if ($benificiary['household_member_id']) {
                $primaryBeneficiary = HouseholdMemberBeneficiaries::updateOrCreate([
                    'household_member_id' => $benificiary['household_member_id'],
                    'type'                => 'primary',
                ]);
            }
        }

        return true;
    }

    /**
     * [getAvailableAppointments for schedule meeting]
     * @param  [Array] $input [schedule meeting form data]
     * @return [Array]        [time slot info]
     */
    public function getAvailableAppointments($input)
    {

        switch ($input['slotTime']) {
            case 0:
                $filename = 'schedule-meeting-30min.json';
                break;
            case 1:
                $filename = 'schedule-meeting-1hour.json';
                break;
            case 2:
                $filename = 'schedule-meeting-2hour.json';
                break;
            default:
                $filename = 'schedule-meeting.json';
                break;
        }

        //actual time slots information json
        $path        = public_path() . "/meeting_json/${filename}";
        $meetingData = json_decode(file_get_contents($path), true);

        //left of the slider time
        $filename = 'schedule-time.json';
        $path     = public_path() . "/meeting_json/${filename}";
        $fixTime  = json_decode(file_get_contents($path), true);

        return [
            'data'     => $meetingData,
            'fix_time' => $fixTime,
        ];

    }

    public function updateInsuranceHousehold($householdId, $data)
    {
        $household = Households::with(['primaryMember','partner'])->find($householdId);
        $household->update($data);
        return $household;
    }

    public function gethouseholdDocuments($householdId, $userId, $propertyId)
    {
        $documents = Document::with('tag', 'user')->where('household_id', $householdId);

        if ($propertyId) {
            $documents = $documents->where('property_id', $propertyId);
        }

        return $documents->orderBy('created_at', 'desc')->limit(5)->get();
    }

    public function getDocumentFilterUsersTagsData($query, $type, $householdId, $userId)
    {
        $documents   = Document::where('household_id', $householdId);
        $documentsId = $documents->pluck('id')->toArray();
        $userId      = $documents->pluck('user_id')->toArray();
        $return      = [];
        $filters     = explode(',', $query['keys']);
        if (in_array('tags', $filters)) {
            $return['tags'] = DocumentTag::whereIn('document_id', $documentsId)
                ->groupBy('tag')
                ->get();
        }
        if (in_array('user', $filters)) {
            $return['user'] = User::whereIn('id', $userId)->get();
        }
        return $return;
    }

    public function getDocumentsFilterData($type, $request, $householdId, $userId)
    {
        $filter = $request['filter'];

        $documents = Document::where('household_id', $householdId)
            ->with('user', 'tags')
            ->orderBy('created_at', 'desc');

        if ($filter) {
            if (!empty($filter['date'])) {
                $date = $filter['date'];
                if ($date[0]) {
                    $from = Carbon::parse(date('c', strtotime($date[0])))
                        ->addDays(1)
                        ->format('Y-m-d');
                    $documents = $documents->where('created_at', '>=', $from . ' 00:00:00');
                }
                if ($date[1]) {
                    $to = Carbon::parse(date('c', strtotime($date[1])))
                        ->addDays(1)
                        ->format('Y-m-d');
                    $documents->where('created_at', '<=', $to . ' 23:59:59');
                }
            }

            if (!empty($filter['tag'])) {
                $tagIds = DocumentTag::whereIn('tag', $filter['tag'])
                    ->pluck('document_id');
                $documents = $documents->whereIn('id', $tagIds);
            }

            if (!empty($filter['user'])) {
                $documents = $documents->whereIn('user_id', $filter['user']);
            }
        }
        return $documents->paginate($request['paginationPerPage'], ['*'], 'page', $request['pageNo']);
    }

    /**
     * Add household into group.
     * @param object $household model.
     * @return object.
     */
    public function addToGroup($household, $groupName, $userId = null, $removeFromAllOtherGroups = false)
    {

        if ($userId){
            $group = Group::where('name', $groupName)->where('user_id', $userId)->first();
        }else{
            $group = Group::where('name', $groupName)->first();
        }


        if (!$group) {
            return;
        }

        if ($removeFromAllOtherGroups){
            GroupsHousehold::where('household_id', $household->id)->delete();
        }

        $groupHousehold = GroupsHousehold::updateOrCreate([
            'group_id'     => $group->id,
            'household_id' => $household->id,
        ], [
            'updated_at' => Carbon::now(),
        ]);

        return $groupHousehold;
    }

    /**
     * Remove household from group.
     * @param object $household model.
     * @return object.
     */
    public function removeFromGroup($household, $groupName)
    {
        $group = Group::where('name', $groupName)->first();

        if (!$group) {
            return;
        }

        $where = [
            ['group_id', $group->id],
            ['household_id', $household->id],
        ];

        $is_removed = GroupsHousehold::where($where)->delete();

        return $is_removed;
    }

    public function syncProspectsFromOnestop()
    {
        //sycing walt's prospects
        $prospects = DB::select("select h.id 'household_id', h.name, h.source_id, h.channel_id, h.goal, h.money_managed, h.year, h.created_at, h.estimate,
                                hm.*,
                                hms.first_name, hms.last_name, hms.email, hms.cell_phone
                            from `benjamin-yourwealth`.household_meetings hm
                                join `benjamin-yourwealth`.household_meetings_users hmu on hm.id = hmu.household_meeting_id
                                join `benjamin-yourwealth`.households h on h.id = hm.household_id
                                join `benjamin-yourwealth`.household_members hms on hms.household_id = h.id
                            where hmu.user_id= 44
                            and hm.created_at >= '2019-04-01'");

        $advisor = Advisors::where('name', 'Walt Coury')->first();

        foreach ($prospects as $value) {

            //check to see if already synced
            $integration = HouseholdIntegration::where('intergration', 'yourwealth')->where('intergration_id', $value->household_id)->first();

            if (empty($integration)) {

                echo 'adding->' . $value->name . "\n";

                $household = Households::create([
                    'name'          => $value->name,
                    'prospect'      => 1,
                    'org_id'        => 1,
                    'source_id'     => $value->source_id,
                    'estimate'      => $value->estimate,
                    'channel_id'    => $value->channel_id,
                    'goal'          => $value->goal,
                    'money_managed' => $value->money_managed,
                    'year'          => $value->year,
                    'status_id'     => 3,
                    'advisor_1_id'  => $advisor->id,
                ]);

                if ($household->wasRecentlyCreated) {
                    event(new AddToGroup($household, 'Active Prospects'));
                }

                $householdId = $household->id;

                $householdMember = HouseholdMembers::updateOrCreate([
                    'email'        => $value->email,
                    'household_id' => $householdId,
                ], ['org_id' => 1,
                    'first_name' => $value->first_name,
                    'last_name'  => $value->last_name,
                    'cell_phone' => $value->cell_phone,
                    'type_id' => 1
                ]);

                $household->primary_member_id = $householdMember->id;
                $household->save();


                $integration = HouseholdIntegration::firstOrCreate([
                    'household_id' => $householdId,
                    'intergration' => 'yourwealth',
                ], [
                    'intergration_id' => $value->household_id,
                ]);

                $schedule = HouseholdMeetings::updateOrCreate([
                    'household_id'        => $householdId,
                    'household_member_id' => $householdMember->id,
                ], [
                    'meeting'  => $value->meeting,
                    'start'    => $value->start,
                    'end'      => $value->end,
                    'notes'    => $value->notes,
                    'location' => $value->location,
                ]);

                $schedule->users()->sync([$advisor->user_id]);


            }

        }

        return true;
    }

    public function syncMeetings()
    {

        $rows = DB::select("select hi.household_id, aa.name 'advisor', s.* from `cia_onestop`.schedules s
        join households_integrations hi on hi.intergration_id = s.prospect_id
        join `cia_onestop`.advisors aa on aa.id = s.advisor_id where hi.intergration = 'onestop'");

        foreach ($rows as $value) {

            $household = Households::where('id', $value->household_id)->first();

            $advisor  = Advisors::where('name', $value->advisor)->first();
            $schedule = HouseholdMeetings::updateOrCreate([
                'household_id'        => $household->id,
                'household_member_id' => $household->primary_member_id,
                'meeting'             => $value->date,
            ], [
                'meeting'    => $value->date,
                'start'      => $value->start,
                'end'        => $value->end,
                'notes'      => $value->notes,
                'location'   => ($value->meeting_place ? $value->meeting_place : 'atlanta'),
                'created_at' => $value->created_at,
            ]);

            $schedule->users()->sync([$advisor->user_id]);

            $household->status_id = 3;
            $household->save();
        }
    }

    public function sendReminderMessage()
    {

        $reminders = Reminder::whereDate('due_date', '=', date('Y-m-d'))->get()->toArray();

        if (!empty($reminders)) {

            foreach ($reminders as $key => $value) {

                $householdData = Households::where('id', $value['household_id'])->first();
                $user          = User::where('id', $value['user_id'])->first();

                /*
                $post = [
                    'from'    => self::FROM,
                    'message' => "Benjamin Reminder for " . $householdData['name'] . "\n " . $value['note'],
                    'tophone' => '+1' . $user->phone,
                ];

                $twilioResponse = $this->twilioRepository->createMessages($post);
                */
            }
        }

        return true;

    }

    public function updateHouseholdInfo($request, $household_id)
    {
        $household            = Households::find($household_id);
        $household->status_id = isset($request['status_id']) ? $request['status_id'] : $household->status_id;
        $household->name      = isset($request['name']) ? $request['name'] : $household->name;

        if(isset($request['advisor']) && !empty($request['advisor'])) {
            foreach ($request['advisor'] as $key => $value) {
                $household[$value['type']] = $value['advisor_id'];
            }
        }

        $household->save();
        $household->fresh();


        if(isset($request['team_member']) && !empty($request['advisor'])) {

            $teamMembers = [];

            foreach ($request['team_member'] as $key => $value) {
                $teamMembers[] = [
                    'household_id' => $household_id,
                    'employee_id'  => $value['employee_id'],
                    'role_id'      => $value['role_id']
                ];
            }

           $household->employees()->sync($teamMembers);
        }

        $householdMember = HouseholdMembers::find($household->primary_member_id);

        if($householdMember) {
            $householdMember->email      = isset($request['email']) ? $request['email'] : $householdMember->email;
            $householdMember->cell_phone = isset($request['cell_phone']) ? $request['cell_phone'] : $householdMember->cell_phone;
            $householdMember->save();
            $householdMember->fresh();
        }

        $with= ['householdMembers', 'groupHouseholds', 'accounts.custodian', 'totalNetWorth', 'primaryMember','householdEmployees'];
        $household = Households::with($with)->find($household_id);

        return $household;
    }

    public function createHouseholdMeeting($request, $household_id)
    {
        $meetingTime = $request['meetingTime'];
        $date        = date('Y-m-d', strtotime($request['meetingDate']));
        $time        = $meetingTime['hour'] . ':' . $meetingTime['minute'] . ':' . $meetingTime['second'];
        $start       = date('Y-m-d H:i:s', strtotime("$date $time"));

        $meeting = HouseholdMeetings::updateOrCreate([
            'household_id'        => $request['householdId'],
            'household_member_id' => $request['household_member_id'],
        ], [
            'meeting' => $date,
            'start'   => $start,
            'notes'   => $request['note'],
        ]);

        return $meeting;
    }

    /*
     * convert lead to prospect
     */
    public function convertToProspect($request, $household_id)
    {
        $household           = Households::find($household_id);
        $household->prospect = 1;
        $household->lead     = 0;
        $household->save();

        event(new RemoveFromGroup($household, 'leads'));
        event(new AddToGroup($household, 'prospects'));
        event(new AddToGroup($household, 'Active Prospects'));

        if ($household) {
            return true;
        }

        return false;
    }

    public function addHouseholdtoGroupByEvent($data, $household_id)
    {
        $household = Households::find($household_id);
        foreach ($data['group_name'] as $group) {
            event(new AddToGroup($household, $group));
        }
        return $data;
    }

    public function assignUser($household_id, $userId)
    {
        $household          = Households::find($household_id);
        //$household->user_id = $userId;
        //$household->save();


        event(new AddToGroup($household, 'my assigned', $userId));




        return $household;

    }

    public function updateAnuualMeeting()
    {
        $householdIntegration = HouseholdIntegration::where('intergration', 'junxure')->get();

        if (!empty($householdIntegration)) {
            foreach ($householdIntegration as $key => $value) {
                $junxureData = $this->junxure->getActionsByRecordId($this->junxureUserId, $value->intergration_id);
                if ($junxureData && $junxureData->ListData) {
                    foreach ($junxureData->ListData as $action_key => $action_val) {
                        if (!empty($action_val->ActionID)) {
                            $actionData = $this->junxure->getAction($this->junxureUserId, $action_val->ActionID);
                            if (!empty($actionData)) {
                                if (!empty($actionData->Action_Subject) && $actionData->Action_Subject == 'Annual Meeting') {
                                    $updateRecord = [
                                        'action_id' => $actionData->ActionID,
                                        'meeting'   => date('Y-m-d H:i:s', strtotime($actionData->Entered_Date)),
                                        'notes'     => $actionData->Action_Note,
                                    ];
                                    $updateMeetingData = HouseholdMeetings::updateOrCreate([
                                        'action_id'    => $actionData->ActionID,
                                        'household_id' => $value->household_id], $updateRecord);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    public function getHouseholdInfoByUuid($uuid)
    {
        $with      = ['primaryMember', 'custodianSales', 'source', 'accounts', 'householdJunxureIntegration','householdHubspotIntegration', 'householdOrionIntegration'];
        $household = Households::with($with)->where('uuid', $uuid)->first();
        return $household;
    }

    public function createSalesProspect($request)
    {
        $rep = CustodianSalesRep::where('id', $request['rep_id'])->first();

        $request['custodian_sales_rep'] = $rep->id;

        $sales = Sales::with('user')->find($request['sales_1_id']);

        $request['assigned_id'] = $sales->user->employee->id;

        $ids = $this->createProspectFromJunxure($request);

        //send to either jeff or greg
        $household = Households::with(['primaryMember', 'source'])->where('id', $ids['household_id'])->first();
        $this->sendTextToCIASalesRep($household);

        return $ids;
    }

    public function sendTextToCIASalesRep($household)
    {


        $message = "NEW PROSPECT: " . strtoupper($household->source->name) . "/" . $household->custodianSales->branch . "\n";

        $message .= "NAME:  " . $household->primaryMember->first_name  . " " . $household->primaryMember->last_name . " " . $household->primaryMember->cell_phone .  "\n";
        $message .= "REP:  " .  ucwords(strtolower($household->custodianSales->fname)) . ' ' . ucwords(strtolower($household->custodianSales->lname)) . " " . $household->custodianSales->phone;


        $sales_rep = Sales::where('id', $household->sales_1_id)->first();
        $employee  = Employee::where('user_id', $sales_rep->user_id)->first();

        if ($employee) {

            $custom_library_id = ConversationLibrary::where('name', 'Custom')->where('type_id', 1)->first()->id;
            $benjaminUserId    = User::where('name', 'Benjamin')->value('id');

            $params                            = [];
            $params['household_id']            = null;
            $params['household_member_id']     = null;
            $params['partner_contact_id']      = null;
            $params['conversation_library_id'] = $custom_library_id;
            $params['custom_message']          = $message;
            $params['user_id']                 = $benjaminUserId;
            $params['employee_id']             = $employee->id;


            $this->conversationRepository->sendConversation($params);
        }

    }

    public function createProspectFromJunxure($prospectsData)
    {
        // get the prospect detail from junxure id
        $junxureData = $this->junxure->getRecordbyId($this->junxureUserId, $prospectsData['prospect_junxure_id']);

        $statusId    =  HouseholdStatus::where('type', 'Prospect')->where('value', 'Unscheduled')->value('id');

        if ($junxureData) {
            $firstName = $junxureData->RecordDetail->First_Name_Person_1;
            $lastName  = $junxureData->RecordDetail->Last_Name_Person_1;

            //if person_1 not set, get from record name
            if (empty($firstName) || empty($lastName)) {
                $recordName = $junxureData->RecordDetail->Record_Name;

                $name_split = explode('-', $recordName);

                if (count($name_split) >= 2) {
                    $lastName = trim($name_split[0]);

                    if (strpos($name_split[1], '&') !== false) {
                        //2 contacts
                        $first_names = explode('&', $name_split[1]);

                        $firstName  = trim($first_names[0]);
                        $firstName2 = trim($first_names[1]);
                    } else {
                        $firstName = trim($name_split[1]);
                    }
                }

            }

            $household                      = new Households();
            $household->name                = $prospectsData['household_name'];
            $household->prospect            = 1;
            $household->org_id              = 1;
            $household->source_id           = isset($prospectsData['prospect_source']) ? $prospectsData['prospect_source'] : null;
            $household->user_id             = isset($prospectsData['user_id']) ? $prospectsData['user_id'] : null;
            $household->estimate            = isset($prospectsData['estimate']) ? $prospectsData['estimate'] : null;
            $household->custodian_sales_rep = isset($prospectsData['custodian_sales_rep']) ? $prospectsData['custodian_sales_rep'] : null;
            $household->sales_1_id          = isset($prospectsData['sales_1_id']) ? $prospectsData['sales_1_id'] : null;
            $household->status_id           = $statusId;
            $household->save();


            $householdId = $household->id;

            HouseholdIntegration::firstOrCreate([
                'household_id' => $householdId,
                'intergration' => 'junxure',
            ], [
                'intergration_id' => $prospectsData['prospect_junxure_id'],
            ]);

            $member = HouseholdMembers::updateOrCreate([
                'email'        => $prospectsData['prospect_email'],
                'household_id' => $householdId,
                'first_name'   => $firstName,
                'last_name'    => $lastName,
                'cell_phone'   => $prospectsData['prosect_phone'],
                'org_id'       => 1,
                'type_id'      => 1,
            ]);

            //saving primary member
            $household->primary_member_id = $member->id;
            $household->save();

            if (isset($prospectsData['task_action'])) {
                $prospectsData['householdId'] = $householdId;
                $this->saveTask($prospectsData);
            }
        } else {
            // $household = [];
            $householdId = null;
        }

        return [
            'household_id' => $householdId,
            'junxure_id'   => $prospectsData['prospect_junxure_id'],
        ];

    }

    public function sendSalesEmail($request)
    {
        $ids = $this->createProspectFromJunxure($request);
        $this->hubspotRepository->sendSalesEmail($request, $ids);
    }

    public function saveTask($taskData)
    {
        if ($taskData['task_action'] == 'yes') {
            $taskType = 'Call Prospect';
        } else {
            $taskType = 'Call Rep';
        }

        $taskType = TaskTypes::where('name', $taskType)
            ->whereNotNull('parent_id')
            ->first()
            ->toArray();

        $household = Households::where('id', $taskData['householdId'])
            ->with('custodianSales')
            ->first();

        $name = '';

        if (isset($household['custodianSales'])) {
            $name = $household['custodianSales']['fname'] . ' ' . $household['custodianSales']['lname'];
        }

        $userId      = User::where('name', 'Benjamin')->value('id');
        $employee_id = $taskData['assigned_id'];

        $data = [
            'household_id' => $taskData['householdId'],
            'user_id'      => $userId,
            'status_id'    => 1,
            'type_id'      => $taskType['id'],
            'title'        => ($taskType['name'] == 'Call Rep') ? $name : $taskType['name'],
            'importance'   => 'high',
            'due_date'     => date('Y-m-d', strtotime('+1 Weekday')),
        ];
        $task = Tasks::create($data);

        TasksAssignment::updateOrCreate([
            'task_id'     => $task->id,
            'employee_id' => $employee_id,
        ]);

        //assign to whitney also
        TasksAssignment::updateOrCreate([
            'task_id'     => $task->id,
            'employee_id' => 46,
        ]);

    }

    public function getTaskCount($userId, $householdId = '')
    {

        $pending = Tasks::where('household_id', $householdId)->where('status_id', 1)->count();

        $complete = Tasks::where('household_id', $householdId)->where('status_id', 2)->count();

        $due_date = Tasks::where('household_id', $householdId)->whereRaw('Date(due_date) <= CURDATE()')->where('status_id', 1)->count();
        $total    = $pending + $complete + $due_date;
        $res      = ['pending' => $pending, 'complete' => $complete, 'due_date' => $due_date, 'total' => $total];
        return $res;
    }


    //for WELA sales only
    function subscribeHouseholdToMailchimp($household) {
        $mailChimp = new MailChimp('275a28b2bf5b3fa810441e02303b133e-us3');
        $listId = '3e6cf9ad24';

        // "Schema describes object"? Not a problem
        $data = new \stdClass();
        // all the batch operations will be stored in this array
        $data->operations = array();


        // a single batch operation object for each user
        $batch =  new \stdClass();
        $batch->method = 'POST';

        $batch->path = 'lists/' . $listId . '/members';

        $batch->body = json_encode( array(
            'email_address' => $household->primaryMember->email,
            'status'        => 'subscribed',
            'merge_fields'  => array(
                'FNAME' => $household->primaryMember->first_name,
                'LNAME' => $household->primaryMember->last_name,
                'PHONE' => $household->primaryMember->cell_phone,
                'EMAIL' => $household->primaryMember->email
            )
        ) );

        $data->operations[] = $batch;


       $response = $mailChimp->post('batches/', $data);



       $integration = HouseholdIntegration::firstOrCreate([
        'household_id' => $household->id,
        'intergration' => 'mailchimp',
        ], [
            'intergration_id' => $household->primaryMember->email,
        ]);


       return true;

    }

    public function updateJunxure($householdId, $request)
    {
        $integration = HouseholdIntegration::updateOrCreate([
            'household_id' => $householdId,
            'intergration' => 'junxure',
        ], [
            'intergration_id' => $request['intergration_id'],
        ]);
        return $integration;
    }

    public function updateHubspot($householdId, $request)
    {
        $integration = HouseholdIntegration::updateOrCreate([
            'household_id' => $householdId,
            'intergration' => 'hubspot',
        ], [
            'intergration_id' => $request['intergration_id'],
        ]);
        return $integration;
    }


    public function addNotesToIntegration($householdId, $note, $conversation = false) {

        $householdIntegrations = HouseholdIntegration::where('household_id', $householdId)
                                ->whereIn('intergration', ['junxure', 'salesforce', 'wealthbox', 'redtail', 'zoho', 'hubspot'])
                                ->get();

        foreach ($householdIntegrations as $householdIntegration) {
            $integrations = Integration::where('type', $householdIntegration->intergration)->get();

            $repo = $householdIntegration->intergration.'Repository';

            if($householdIntegration->intergration != 'wealthbox'){
                foreach($integrations as $integration){
                    $this->$repo->refreshAccessToken($integration->id);
                }
            } else {
                foreach($integrations as $integration){
                    $this->$repo->refreshAccessToken($integration);
                }
            }

            //add third parameter in addNotes to send anonymous information in all Repo
            if(in_array($householdIntegration->intergration, ['wealthbox']) && $conversation){
                $response = $this->$repo->addNotes($householdIntegration->intergration_id, $note, [
                    'chat_queue_id' => $conversation->chat_queue_id
                ]);
            } else {
                $response = $this->$repo->addNotes($householdIntegration->intergration_id, $note);
            }
          
            if ($response && $conversation) {
                $logData = [];
                $log['chat_queue_id']   = $conversation->chat_queue_id;
                $log['integration']     = $householdIntegration->intergration;
                $log['intergration_id'] = $householdIntegration->intergration_id;

                if(in_array($householdIntegration->intergration, ['wealthbox'])){
                    $logData['note_integration_id'] = $response->id;
                }

                ConversationsNotesLog::updateOrCreate($log, $logData);
            }
        }

        return $note;
    }

    public function insertConversation($chat_queue_id) {

        $conversationDetail = $this->conversationRepository->getConversationDetails($chat_queue_id);

        $note = '';
        foreach ($conversationDetail as $detail) {
            $sentBy = ($detail->sent == 1) ? 'Benjamin' : $detail->name;
            $note .= $sentBy .": ". $detail->message."\n ";
        }

        return $note;
    }

    public function cancelMeeting($household_id, $meeting_id, $userId)
    {
        $meeting = HouseholdMeetings::where([ 'id' => $meeting_id, 'household_id' => $household_id])->first();

        if (isset($meeting->meeting_id) && !empty($meeting->meeting_id)) {
            $this->scheduleRepository->deleteEvent($meeting, $userId);
        }

        $cancelled_status = MeetingStatus::where('name', 'Cancelled')->first();

        $meeting->status_id = $cancelled_status->id;
        $meeting->save();

        $is_delete = true;

        if ($is_delete) {
            $tasks = Tasks::whereNotNull('action')
                    ->where(DB::raw("JSON_EXTRACT(`action`, '$.payload.meeting_id')"), (int)$meeting_id)
                    ->delete();
        }
        // $meeting->meetingUser()->delete();
        $meeting->calendarMeeting()->delete();

        return HouseholdMeetings::with(['meetingAdvisor', 'household', 'status'])
            ->where('id', $meeting_id)->first();
        
    }

    public function getConversationForTask()
    {
        //we have to update the wealthbox notes as per conversation_note_delay hours
        $convDelayHours = InstanceSettings::where('name', 'crm:wealthbox:conversation_note_delay')->value('value');
        if(empty($convDelayHours)){
            $convDelayHours = 24;
        }

        $conversations = DB::select("select c.*, cl.name from conversations AS c
                            join conversation_library AS cl ON c.conversation_library_id = cl.id
                            left join conversations_notes_logs AS cnl on c.chat_queue_id = cnl.chat_queue_id and cnl.integration = 'wealthbox'
                            where (c.sent AND c.send AND c.chat_queue_id IS NOT NULL)
                            and (case when cnl.integration = 'wealthbox' THEN c.sent <= DATE_SUB(NOW(), INTERVAL :conv_delay_hours HOUR) ELSE c.sent <= DATE_SUB(NOW(), INTERVAL 1 DAY) END)
                            and c.chat_queue_id NOT IN (select chat_queue_id FROM conversations_notes_logs where integration NOT IN ('wealthbox'))", ['conv_delay_hours' => $convDelayHours]);
        $note = [];

        if (count($conversations) > 0) {
            foreach ($conversations as $conversation) {

                $noteDetail = $this->insertConversation($conversation->chat_queue_id);

                if ($noteDetail && !empty($noteDetail)) {
                    $note['title'] = 'Benjamin Task - '.$conversation->name;
                    $note['note']  = $noteDetail;
                    $this->addNotesToIntegration($conversation->household_id, $note, $conversation);
                }

            }
        }
    }

    public function setToUnsubscribe($householdUUID)
    {
        return Households::where('uuid', $householdUUID)->update([
            'unsubscribed' => Carbon::now()
        ]);
    }

    public function fundFields($request)
    {
        $fields = ['Fund Name', 'Fund Manager', 'Asset Class', 'Strategy', 'AUM', 'Preferred return hurdle', 'Target IRR', 'Waterfall Type'];
        $bindFields = ['fund_name', 'fund_manager_name', 'asset_classes', 'fund_strategy', 'aum', 'preferred_return', 'irr_target', 'waterfall'];

        $tempFields = [];
        $tempdisplayFields = [];
        if ($request['OriginalColumns']) {
            foreach ($request['OriginalColumns'] as $key => $column) {
                if (!in_array($column, $tempdisplayFields) ) {
                    $res = QueryField::where('field_display', $column)->first();
                    if (isset($res->field)) {
                        array_push($tempFields, $res->field);
                        array_push($tempdisplayFields, $res->field_display);
                    }else{
                        $index = array_search($column, $fields);
                        array_push($tempFields, $bindFields[$index]);
                        array_push($tempdisplayFields, $column);
                    }
                }
            }
        }else{
            $tempFields = $bindFields;
            $tempdisplayFields = $fields;
        }
        $return['fields'] = $tempFields;
        $return['display_fields'] = $tempdisplayFields;

        return $return;
    }

    public function meetings($household_id)
    {
        $previous =  Carbon::now()->yesterday();

        return HouseholdMeetings::with(['meetingAdvisor', 'household', 'status'])
            ->where([['household_id','=', $household_id],['meeting','>',$previous]])
            ->orderBy('meeting')->get();
    }

    public function testNotesToCRM()
    {
        $userId = User::where('name','Benjamin')->value('id');
        $household = Households::with('primaryMember')->where('name', 'testNotesToCRM')->first();
        if(empty($household)){
            $household = new Households();
            $household->name     = 'testNotesToCRM';
            $household->prospect = 1;
            $household->org_id   = 1;
            $household->save();

            //create household member
            $member = new HouseholdMembers();
            $member->household_id = $household->id;
            $member->org_id       = 1;
            $member->type_id      = 1;
            $member->first_name   = 'testNotesToCRM';
            $member->last_name    = 'test';
            $member->save();

            $household->primary_member_id = $member->id;
            $household->save();
        
            $household->load('primaryMember');
        }

        $householdId = $household->id;
        $memberId = $household->primaryMember->id;

        //create contact with 'testNotesToCRM' name in all integrations
        //['junxure', 'salesforce', 'wealthbox', 'redtail', 'zoho', 'hubspot'];
        $integrations = ['redtail'];
        foreach ($integrations as $intergration) {
            $householdIntegration = HouseholdIntegration::where('household_id', $householdId)
                                ->where('intergration', $intergration)
                                ->first();

            if(empty($householdIntegrations)){
                $repo = $intergration.'Repository';
                $this->$repo->refreshAccessToken();
                $response = $this->$repo->testConactToCRM($household, $userId);
            } 
        }

        //if it will create note then add note to all CRM by HouseholdNotes modal boot method
        //currently we have call "CreateNote" job on create HouseholdNotes
        $householdNotes = HouseholdNotes::firstOrCreate([
            'user_id'      => $userId,
            'household_id' => $householdId,
            'title'        => 'test note',
            'note'         => 'testNotesToCRM',
        ]);

        try{
            //if it will exist note then add note to all CRM by HouseholdNotes modal boot method
            if ($householdNotes->exists) {
                $this->addNotesToIntegration($householdId, $householdNotes);
            }
        }catch(\Exception $e){
            throw new \Exception($e->getMessage());
        }
    }
}
