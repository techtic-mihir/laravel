<?php
namespace App\Api\V1\Controllers;

use App\Http\Controllers\Controller;
use App\Repositories\Conversation\ConversationRepository;
use App\Repositories\Funds\FundManagersRepository;
use App\Repositories\Households\HouseholdsRepository;
use App\Repositories\Trade\TradeRepository;
use App\Repositories\Notification\NotificationRepository;
use Auth;
use Illuminate\Http\Request;
use Response;

use App\Models\Households;
use App\Models\HouseholdMembers;

class HouseholdsController extends Controller
{
    public function __construct(
        HouseholdsRepository $householdsRepository,
        ConversationRepository $conversationRepository,
        TradeRepository $tradeRepository,
        FundManagersRepository $fundManagersRepository,
        NotificationRepository $notificationRepository
    ) {
        $this->householdsRepository   = $householdsRepository;
        $this->conversationRepository = $conversationRepository;
        $this->tradeRepository        = $tradeRepository;
        $this->fundManagersRepository = $fundManagersRepository;
        $this->notificationRepository = $notificationRepository;
    }
    public function index()
    {
        try {
            $households = $this->householdsRepository->get($with = true);
            $response   = [
                'code'   => 200,
                'result' => $households,
            ];

        } catch (Exception $e) {
            $response = [
                'code'   => 500,
                'result' => $e->getMessage(),
            ];
        }
        return response()->json($response);
    }

    public function save(Request $request)
    {
        try {
            $input    = $request->all();
            $result   = $this->householdsRepository->save($input);
            $response = [
                'code'   => 200,
                'result' => $result,
            ];

        } catch (Exception $e) {
            $response = [
                'code'   => 500,
                'result' => $e->getMessage(),
            ];
        }

        return response()->json($response);
    }

    public function cancelMeeting($household_id, $meeting_id){
        try {
            $userId   = Auth::id();
            $result   = $this->householdsRepository->cancelMeeting($household_id, $meeting_id, $userId);
            $response = [
                'code'   => 200,
                'result' => $result,
            ];
        } catch (Exception $e) {
            $response = [
                'code'   => 500,
                'result' => $e->getMessage(),
            ];
        }

        return response()->json($response);
    }

    public function update(Request $request)
    {

        try {
            $input    = $request->all();
            $result   = $this->householdsRepository->update($input);
            $response = [
                'code'   => 200,
                'result' => $result,
            ];

        } catch (Exception $e) {
            $response = [
                'code'   => 500,
                'result' => $e->getMessage(),
            ];
        }

        return response()->json($response);
    }

    /**
     * Get the specified household.
     *
     * @param  int $id
     * @param  get $request
     * @return \Illuminate\Http\Response
     */
    public function getHousehold($id, Request $request)
    {
        try {
            $scope    = $request->only(['scope']);
            $data     = $this->householdsRepository->getHousehold($id, $scope);
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return response::json($response);
    }

    public function getRecentHouseholdConv($id)
    {
        try {
            $data     = $this->householdsRepository->getRecentHouseholdConv($id);
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return response::json($response);
    }

    public function getConversationsList($id)
    {
        try {
            $data     = $this->householdsRepository->getConversationsList($id);
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return response::json($response);
    }

    public function getUpcomingConversationsList($id)
    {
        try {
            $data     = $this->householdsRepository->getConversationsList($id, 1);
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return response::json($response);
    }

    public function getCountConversation($id)
    {
        try {
            $data     = $this->householdsRepository->getCountConversation($id, 1);
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return response::json($response);
    }

    public function getAssignedGroup($id)
    {
        try {
            $data     = $this->householdsRepository->getAssignedGroup($id);
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return response::json($response);
    }

    public function deleteGroupsHousehold($id, $groupHouseholdId)
    {
        try {
            $data     = $this->householdsRepository->deleteGroupsHousehold($groupHouseholdId);
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return response::json($response);
    }

    public function getSubscribedConversations($id)
    {
        try {
            $data     = $this->householdsRepository->getSubscribedConversations($id);
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return response::json($response);
    }

    public function getNewConversations($id)
    {
        $response = $this->householdsRepository->getNewConversations($id);
        return response()->json([
            'code'     => 200,
            'response' => $response,
        ]);
    }

    public function removeConversations($id, $conversation_id, $type)
    {
        $response = $this->householdsRepository->removeConversations($id, $conversation_id, $type);
        return response()->json([
            'code'     => 200,
            'response' => $response,
        ]);
    }

    public function historyConversations($id, $conversation_id)
    {
        $response = $this->householdsRepository->historyConversations($id, $conversation_id);
        return response()->json([
            'code'     => 200,
            'response' => $response,
        ]);
    }

    public function saveConversation(Request $request)
    {
        $data     = $request->all();
        $userId   = Auth::id();
        $response = $this->conversationRepository->saveConversation($data, $userId);
        return response()->json([
            'code'     => 200,
            'response' => $response,
        ]);
    }

    public function addNewConversations($id, Request $request)
    {
        $data     = $request->all();
        $userId   = Auth::id();
        $response = $this->householdsRepository->addNewConversations($id, $userId, $data);
        return response()->json([
            'code'     => 200,
            'response' => $response,
        ]);
    }

    public function getChart($id)
    {
        $response = $this->householdsRepository->getChart($id);
        return response::json($response);
    }

    public function getIncome($id)
    {
        $response = $this->householdsRepository->getIncome($id);
        return response::json([
            'code'     => 200,
            'response' => $response,
        ]);
    }

    public function getIncomeWithdrawal($id)
    {
        $response = $this->householdsRepository->getIncomeWithdrawal($id);
        return response::json([
            'code'     => 200,
            'response' => $response,
        ]);
    }

    public function getHouseholdInfo()
    {
        $response = $this->householdsRepository->getHouseholdInfo();
        return response::json($response);
    }

    public function historicalBalances($id)
    {
        $response = $this->householdsRepository->historicalBalances($id);
        return response::json($response);
    }

    public function accountBalancesFilter($id)
    {
        $response = $this->householdsRepository->accountBalancesFilter($id);
        return response::json($response);
    }

    public function getAccounts($id)
    {
        $response = $this->householdsRepository->getAccounts($id);
        return response::json([
            'code'   => 200,
            'result' => $response,
        ]);
    }
    /**
     * get clients of user from household
     *
     * @return clients json data
     */
    public function getHouseholds($group_id = null)
    {
        try {

            $user       = Auth::user();
            $households = $this->householdsRepository->getHouseholds($user, $group_id);
            $response   = [
                'code'   => 200,
                'result' => $households,
            ];
        } catch (Exception $e) {
            $response = [
                'code'    => 500,
                'message' => $e->getMessage(),
                'result'  => false,
            ];
        }
        return response::json($response);
    }

    /**
     * paginate household list
     *
     * @param pagination data
     * @return clients json data
     */
    public function paginateHousehold(Request $request)
    {
        try {

            $user          = Auth::user();
            $fields = [];
            $instance_type = config('benjamin.instance_type');
            if ($instance_type == 'insurance') {
                $households = $this->householdsRepository->insuranceHousehold($request->all(), $user);
            } else if (strtolower($instance_type) == 'fund') {
                $households = $this->householdsRepository->paginateFund($request->all(), $user);
                $fields = $this->householdsRepository->fundFields($request->all());
            } else if (strtolower($instance_type) == 'sales') {
                $households = $this->householdsRepository->salesHousehold($request->all(), $user);
            } else {
                $households = $this->householdsRepository->paginateHousehold($request->all(), $user);
            }

            if (strtolower($instance_type) != 'fund') {
            //get display fields in frontend
                $fields = $this->householdsRepository->paginateHouseholdFields($instance_type, $request->all());
            }

            $response = [
                'code'   => 200,
                'result' => [
                    'data'   => $households,
                    'fields' => $fields,
                ],
            ];
        } catch (Exception $e) {
            $response = [
                'code'    => 500,
                'message' => $e->getMessage(),
                'result'  => false,
            ];
        }
        return response::json($response);
    }

    public function getTags()
    {
        $tags     = $this->householdsRepository->getTags();
        $response = [
            'code'   => 200,
            'result' => $tags,
        ];
        return response::json($response);
    }

    public function createAnalysis(Request $request)
    {
        try {
            $households = $this->householdsRepository->createAnalysis($request->all());
            $response   = [
                'code'   => 200,
                'result' => $households,
            ];
        } catch (Exception $e) {
            $response = [
                'code'    => 500,
                'message' => $e->getMessage(),
                'result'  => false,
            ];
        }
        return response::json($response);
    }

    public function createNote(Request $request)
    {
        try {
            $data     = $request->all();
            $userId   = Auth::id();
            $note     = $this->householdsRepository->createNote($data, $userId);
            $response = [
                'code'   => 200,
                'result' => $note,
            ];
        } catch (Exception $e) {
            $response = [
                'code'    => 500,
                'message' => $e->getMessage(),
                'result'  => false,
            ];
        }
        return response::json($response);
    }

    public function getAnalysis($household_id)
    {

        try {
            $data     = $this->householdsRepository->getAnalysis($household_id);
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return response::json($response);
    }

    public function removeDebtAnalysis($household_debt_analysis)
    {

        try {
            $data     = $this->householdsRepository->removeDebtAnalysis($household_debt_analysis);
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return response::json($response);
    }

    public function runAnalysis(Request $request)
    {
        try {
            $households = $this->householdsRepository->runAnalysis($request->all());
            $response   = [
                'code'   => 200,
                'result' => $households,
            ];
        } catch (Exception $e) {
            $response = [
                'code'    => 500,
                'message' => $e->getMessage(),
                'result'  => false,
            ];
        }
        return response::json($response);
    }

    public function createReminder(Request $request)
    {
        try {
            $orgId    = Auth::user()->organization_id;
            $userId   = Auth::id();
            $remonder = $this->householdsRepository->createReminder($request->all(), $orgId, $userId);
            $response = [
                'code'   => 200,
                'result' => $remonder,
            ];
        } catch (Exception $e) {
            $response = [
                'code'    => 500,
                'message' => $e->getMessage(),
                'result'  => false,
            ];
        }
        return response::json($response);
    }


    public function getConversations()
    {
        try {
            $data     = $this->householdsRepository->getConversations();
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }
        return response::json($response);
    }

    public function getDocuments($household_id)
    {
        try {
            $userId   = Auth::id();
            $data     = $this->householdsRepository->getDocuments($household_id, $userId);
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }
        return response::json($response);
    }

    public function searchDocuments(Request $request)
    {
        try {
            $searchResult = $this->householdsRepository->searchDocuments($request['searchKey']);
            $response     = [
                'code'     => 200,
                'response' => $searchResult,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }
        return Response::json($response);
    }

    public function updateHousehold(Request $request)
    {
        try {
            $data     = $request->all();
            $response = $this->householdsRepository->updateHousehold($data);
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return Response::json($response);
    }

    public function saveProspect(Request $request)
    {
        try {
            $input    = $request->all();
            $data     = $this->householdsRepository->saveProspect($input);
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return Response::json($response);
    }

    public function saveFormProspect(Request $request)
    {

        try {
            $input = $request->all();

            $input['email']      = $input['client_a']['email'];
            $input['name']       = $input['client_a']['name'];
            $input['cell_phone'] = $input['client_a']['cell_phone'];

            $nameArray          = explode(' ', $input['client_a']['name']);
            $input['firstName'] = $nameArray[0];
            $input['lastName']  = (isset($nameArray[1])) ? $nameArray[1] : '';
            $data               = $this->householdsRepository->saveFormProspect($input);
            //$input['prospect'] = $input;
            //return view('pdf/createProspectForm', $input);
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return Response::json($response);
    }

    public function closeProspect(Request $request)
    {
        try {
            $input    = $request->all();
            $userId   = Auth::id();
            $data     = $this->householdsRepository->closeProspect($input, $userId);
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return Response::json($response);
    }

    public function getHouseholdByIntegrationId(Request $request)
    {
        try {
            $input    = $request->all();
            $data     = $this->householdsRepository->getHouseholdByIntegrationId($input);
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return Response::json($response);
    }

    public function getHouseholdByName(Request $request)
    {
        try {
            $input = $request->all();
            $data  = $this->householdsRepository->getHouseholdByName($input);

            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return Response::json($response);
    }

    public function getHouseholdByUUID(Request $request)
    {
        try {
            $input = $request->all();
            $data  = $this->householdsRepository->getHouseholdByUUID($input);

            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return Response::json($response);
    }

    public function addHouseholdtoGroup($id, Request $request)
    {
        try {
            $input    = $request->all();
            $userId   = Auth::id();
            $data     = $this->householdsRepository->addHouseholdtoGroup($id, $input, $userId);
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return Response::json($response);
    }

    public function getTradeAccounts($household_id)
    {
        try {

            $accounts = $this->householdsRepository->getTradeAccounts($household_id);
            return Response::json([
                'code'     => 200,
                'response' => $accounts,
            ]);
        } catch (Exception $e) {
            return Response::json([
                'code'     => 500,
                'response' => $e->getMessage(),
            ]);
        }
    }
    public function saveTrade(Request $request)
    {
        try {
            $post     = $request->all();
            $accounts = $this->tradeRepository->saveTrade($post, Auth::id());
            return Response::json([
                'code'     => 200,
                'response' => $accounts,
            ]);
        } catch (Exception $e) {
            return Response::json([
                'code'     => 500,
                'response' => $e->getMessage(),
            ]);
        }
    }

    public function getHouseholdByStatusId($status_id)
    {
        try {
            $data = $this->householdsRepository->getHouseholdByStatusId($status_id);
            return Response::json([
                'code'     => 200,
                'response' => $data]);
        } catch (Exception $e) {
            return Response::json([
                'code'     => 500,
                'response' => $e->getMessage(),
            ]);
        }
    }

    public function getEzlynxUpload($household, $householdMemberId = null)
    {
        $response = $this->householdsRepository->getEzlynxUpload($household, $householdMemberId);
        return Response::json([
            'code'     => 200,
            'response' => $response,
        ]);
    }

    public function ezlynxUpload(Request $request)
    {
        $input    = $request->all();
        $response = $this->householdsRepository->ezlynxUpload($input);

        if (!isset($response['code'])) {
            $this->householdsRepository->createInsuranceEzlynx($input['household'], $input['householdMemberId'], $response);
            return Response::json([
                'code'     => 200,
                'response' => $response,
            ]);
        } else {
            return Response::json([
                'code'     => 500,
                'response' => $response['response'],
            ]);
        }
    }

    public function updateOnboardingStatus(Request $request)
    {
        try {
            $inputs = $request->all();
            $data   = $this->householdsRepository->updateOnboardingStatus($inputs, Auth::id());
            return Response::json([
                'code'     => 200,
                'response' => $data,
            ]);
        } catch (Exception $e) {
            return Response::json([
                'code'     => 500,
                'response' => $e->getMessage(),
            ]);
        }
    }

    public function getHashByID($id)
    {
        try {
            $data = \Crypt::encrypt($id);
            return Response::json([
                'code'     => 200,
                'response' => $data,
            ]);
        } catch (Exception $e) {
            return Response::json([
                'code'     => 500,
                'response' => $e->getMessage(),
            ]);
        }
    }

    public function getHouseholdByHash($hashValue)
    {
        try {
            // print_r(urlencode(hash('md5', 11)));die;
            // print_r(\Crypt::encrypt(11));die;
            $householdId = \Crypt::decrypt($hashValue);
            $data        = $this->householdsRepository->getHousehold($householdId);
            return Response::json([
                'code'     => 200,
                'response' => $data,
            ]);
        } catch (Exception $e) {
            return Response::json([
                'code'     => 500,
                'response' => $e->getMessage(),
            ]);
        }
    }

    public function saveHouseholdByHash($hashValue, Request $request)
    {
        try {
            $inputs      = $request->all();
            $householdId = \Crypt::decrypt($hashValue);
            $data        = $this->householdsRepository->saveHouseholdByHash($householdId, $inputs);
            return Response::json([
                'code'     => 200,
                'response' => $data,
            ]);
        } catch (Exception $e) {
            return Response::json([
                'code'     => 500,
                'response' => $e->getMessage(),
            ]);
        }
    }

    public function updateClientInformation($id, Request $request)
    {
        try {
            $data = $this->householdsRepository->updateClientInformation($id, $request->all());
            return Response::json([
                'code'     => 200,
                'response' => $data,
            ]);
        } catch (Exception $e) {
            return Response::json([
                'code'     => 500,
                'response' => $e->getMessage(),
            ]);
        }
    }

    public function updateInsuranceHousehold($id, Request $request)
    {
        try {
            $data = $this->householdsRepository->updateInsuranceHousehold($id, $request->all());
            return Response::json([
                'code'     => 200,
                'response' => $data,
            ]);
        } catch (Exception $e) {
            return Response::json([
                'code'     => 500,
                'response' => $e->getMessage(),
            ]);
        }
    }

    public function gethouseholdDocuments($household_id, $propertyId = null)
    {
        try {
            $userId   = Auth::id();
            $data     = $this->householdsRepository->gethouseholdDocuments($household_id, $userId, $propertyId);
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return response::json($response);
    }

    public function getDocumentFilterUsersTagsData(Request $request, $type, $id)
    {
        try {
            $userId = Auth::id();
            $query  = $request->query();
            if (in_array($type, ['fund-manager', 'fund'])) {
                $data = $this->fundManagersRepository->getDocumentFilterUsersTagsData($query, $type, $id, $userId);
            } else {
                $data = $this->householdsRepository->getDocumentFilterUsersTagsData($query, $type, $id, $userId);
            }
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }
        return response::json($response);
    }

    public function getDocumentsFilterData(Request $request, $type, $id)
    {
        try {
            $userId = Auth::id();
            if (in_array($type, ['fund-manager', 'fund'])) {
                $data = $this->fundManagersRepository->getDocumentsFilterData($type, $request->all(), $id, $userId);
            } else {
                $data = $this->householdsRepository->getDocumentsFilterData($type, $request->all(), $id, $userId);
            }
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }
        return response::json($response);
    }

    /*
     * update household information
     */
    public function updateHouseholdInfo(Request $request, $id)
    {

        $inputs   = $request->all();
        $response = $this->householdsRepository->updateHouseholdInfo($inputs, $id);

        return Response::json([
            'code'     => 200,
            'response' => $response,
        ]);
    }

    /*
     * add household and household member meeting
     */
    public function createHouseholdMeeting(Request $request, $id)
    {

        $inputs   = $request->all();
        $response = $this->householdsRepository->createHouseholdMeeting($inputs, $id);

        return Response::json([
            'code'     => 200,
            'response' => $response,
        ]);
    }

    /*
     * convert lead to prospect
     */
    public function convertToProspect(Request $request, $id)
    {

        $inputs   = $request->only(['prospect', 'lead']);
        $response = $this->householdsRepository->convertToProspect($inputs, $id);

        return Response::json([
            'code'     => 200,
            'response' => $response,
        ]);
    }

    /*
     *  add household into groups_households when household/{id} component call
     */
    public function addHouseholdtoGroupByEvent(Request $request, $householdId)
    {
        try {

            $data     = $this->householdsRepository->addHouseholdtoGroupByEvent($request->all(), $householdId);
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }
        return Response::json($response);
    }

    public function assignUser($householdId, $userId)
    {
        try {
            $data     = $this->householdsRepository->assignUser($householdId, $userId);
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }
        return Response::json($response);
    }

    public function getHouseholdInfoByUuid($uuid)
    {
        try {
            $data     = $this->householdsRepository->getHouseholdInfoByUuid($uuid);
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }
        return Response::json($response);
    }

    /**
     * add update prospects table
     *
     * @param prospect data from junxure api
     * @return json response
     */
    public function createSalesProspect(Request $request)
    {
        try {
            $input            = $request->all();
            $input['user_id'] = Auth::id();
            $data             = $this->householdsRepository->createSalesProspect($input);
            $response         = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }
        return Response::json($response);
    }

    /**
     * add update prospects table
     *
     * @param prospect data from junxure api
     * @return json response
     */
    public function sendSalesEmail(Request $request)
    {
        try {
            $input            = $request->all();
            $input['user_id'] = Auth::id();
            $data             = $this->householdsRepository->sendSalesEmail($input);
            $response         = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }
        return Response::json($response);
    }

    public function getTaskCount($householdId = '')
    {
        try {
            $response = $this->householdsRepository->getTaskCount(Auth::id(),$householdId);
            $response = [
                'code' => 200,
                'response' => $response
            ];
        }catch (Exception $e) {
            $response =  [
                'code'    => 500,
                'response' => $e->getMessage()
            ];
        }
        return response::json($response);
    }

    public function updateJunxure($householdId, Request $request)
    {
        try {
            $input            = $request->all();
            $response = $this->householdsRepository->updateJunxure($householdId, $input);
            $response = [
                'code' => 200,
                'response' => $response
            ];
        }catch (Exception $e) {
            $response =  [
                'code'    => 500,
                'response' => $e->getMessage()
            ];
        }
        return response::json($response);
    }

    public function updateHubspot($householdId, Request $request)
    {
        try {
            $input            = $request->all();
            $response = $this->householdsRepository->updateHubspot($householdId, $input);
            $response = [
                'code' => 200,
                'response' => $response
            ];
        }catch (Exception $e) {
            $response =  [
                'code'    => 500,
                'response' => $e->getMessage()
            ];
        }
        return response::json($response);
    }

    public function getHouseholdNotification($id)
    {
        try {
            $data     = $this->notificationRepository->getHouseholdNotification($id);
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return response::json($response);
    }

    public function setHouseholdNotification($id, Request $request)
    {
        try {
            $input = $request->all();
            $data  = $this->notificationRepository->setHouseholdNotification($id, $input);
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return response::json($response);
    }

    public function setToUnsubscribe($id)
    {
        try {
            $data  = $this->householdsRepository->setToUnsubscribe($id);
            $response = [
                'code'     => 200,
                'response' => $data,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return response::json($response);
    }

    public function meetings($household_id)
    {
        try {
            $user_id = Auth::id();
            $response  = $this->householdsRepository->meetings($household_id);
            $response = [
                'code'     => 200,
                'response' => $response,
            ];
        } catch (Exception $e) {
            $response = [
                'code'     => 500,
                'response' => $e->getMessage(),
            ];
        }

        return response::json($response);
    }
}
