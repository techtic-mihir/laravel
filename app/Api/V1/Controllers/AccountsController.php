<?php

namespace App\Api\V1\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Response;
use App\Models\Accounts;
use App\Repositories\Accounts\AccountsRepository;
use App\Repositories\Households\HouseholdsRepository;
use App\Repositories\Conversation\ConversationRepository;
use Auth;

class AccountsController extends Controller
{
    public function __construct(
        AccountsRepository $accountsRepository,
        HouseholdsRepository $householdsRepository,
        ConversationRepository $conversationRepository
    ) {
      $this->accountsRepository = $accountsRepository;
      $this->householdsRepository = $householdsRepository;
      $this->conversationRepository = $conversationRepository;
    }

    public function index($id){

        $response = $this->accountsRepository->get($id);
        return response::json([
              'code' => 200,
              'response' => $response
        ]);
    }

    /*public function save(Request $request){

        $data = $request->all();
        $response = $this->accountsRepository->save($data);
        return response::json([
              'code' => 200,
              'response' => $response
        ]);
    }

    public function update(Request $request){

        $data = $request->all();
        $response = $this->accountsRepository->update($data);
        return response::json([
              'code' => 200,
              'response' => $response
        ]);
    }*/

    public function getAccount($id){

        $response = $this->householdsRepository->getAccounts($id);
        return response::json([
              'code' => 200,
              'response' => $response
        ]);
    }

    public function getAccountDetails($id, $account_id){

        $response = $this->accountsRepository->getAccountDetails($id, $account_id);
        return response::json([
            'code' => 200,
            'response' => $response
        ]);
    }

    public function accountFilter($id, Request $request){

		try {
            $input = $request->all();
            $data = $this->accountsRepository->accountFilter($id, $input);
            $response = [
                'code' => 200,
                'response' => $data
            ];
        } catch (Exception $e) {
            $response =  [
                'code'    => 500,
                'response' => $e->getMessage()
            ];
        }

        return response::json($response);
    }

	/**
	* Get a chart resource for all accounts of perticular household.
	*
	* @param  \Illuminate\Http\Request  $request
	* @param  $id
	* @return \Illuminate\Http\Response
	*/
    public function getChart($id, Request $request){
        try {
            $input = $request->all();
            $data = $this->accountsRepository->getChart($id, $input);
            $response = [
                'code' => 200,
                'response' => $data
            ];
        } catch (Exception $e) {
            $response =  [
                'code'    => 500,
                'response' => $e->getMessage()
            ];
        }

        return response::json($response);
    }

    public function getConversationList(){

        $response = $this->accountsRepository->getConversationList();
        return response::json([
            'code' => 200,
            'response' => $response
        ]);
    }

    /*public function saveConversation(Request $request)
    {
        try {
            $input = $request->all();
            $userId = Auth::id();
            $data = $this->conversationRepository->saveConversation($input, $userId);
            $response = [
                'code' => 200,
                'response' => $data
            ];
        } catch (Exception $e) {
            $response =  [
                'code'    => 500,
                'response'  => $e->getMessage(),
            ];
        }
        return response::json($response);
    }*/


    /**
    * Get portfolio performance chart for perticular account.
    *
    * @param  $id [household id]
    * @param  $account_id
    * @return \Illuminate\Http\Response
    */
    public function getPortfolioPerformanceChart($id, $account_id){
        try {
            $data = $this->accountsRepository->getPortfolioPerformanceChart($id, $account_id);
            $response = [
                'code' => 200,
                'response' => $data
            ];
        } catch (Exception $e) {
            $response =  [
                'code'    => 500,
                'response' => $e->getMessage()
            ];
        }

        return response::json($response);
    }

    /**
    * Get portfolio performance chart for perticular account using from and to date.
    *
    * @param  \Illuminate\Http\Request $request [from date and to date]
    * @param  $id [household id]
    * @param  $account_id
    * @return \Illuminate\Http\Response
    */
    public function getPortfolioPerformanceChartFilter($id, $account_id, Request $request){
        try {
            $input = $request->all();
            $data = $this->accountsRepository->getPortfolioPerformanceChartFilter($id, $account_id, $input);
            $response = [
                'code' => 200,
                'response' => $data
            ];
        } catch (Exception $e) {
            $response =  [
                'code'    => 500,
                'response' => $e->getMessage()
            ];
        }

        return response::json($response);
    }

    public function getPortfolioChartGrowth($id, $account_id){

        $response = $this->accountsRepository->getPortfolioChartGrowth($id, $account_id);

        return response::json([
            'code' => 200,
            'response' => $response
        ]);
    }

    public function getPortfolioChartIncome($id, $account_id){

        $response = $this->accountsRepository->getPortfolioChartIncome($id, $account_id);

        return response::json([
            'code' => 200,
            'response' => $response
        ]);
    }

    public function getCashFlowChart($id, $account_id){

        $response = $this->accountsRepository->getCashFlowChart($id, $account_id);

        return response::json([
            'code' => 200,
            'response' => $response
        ]);
    }

    public function getCashFlowChartFilter($id, $account_id, Request $request){

        $response = $this->accountsRepository->getCashFlowChartFilter($id, $account_id, $request->all());

        return response::json([
            'code' => 200,
            'response' => $response
        ]);
    }

    public function getHoldingsList($id, $account_id){

        $response = $this->accountsRepository->getHoldingsList($id, $account_id);
        return response::json([
            'code' => 200,
            'response' => $response
        ]);
    }

    public function getHoldingsListByAllocation($id, $account_id, Request $request){

        $response = $this->accountsRepository->getHoldingsListByAllocation($id, $account_id, $request->all());
        return response::json([
            'code' => 200,
            'response' => $response
        ]);
    }

    public function getHoldingsListByFilter($id, $account_id, Request $request){

        $response = $this->accountsRepository->getHoldingsListByFilter($id, $account_id, $request->all());

        return response::json([
            'code' => 200,
            'response' => $response
        ]);
    }

    public function getTransactionsList($id, $account_id){

        $response = $this->accountsRepository->getTransactionsList($id, $account_id);

        return response::json([
            'code' => 200,
            'response' => $response['data'],
            'tagFilterValue' => $response['tagFilterValue'],
        ]);
    }

    public function getTransactionsFilterList(Request $request, $id, $account_id){

       $input = $request->all();
       $response = $this->accountsRepository->getTransactionsFilterList($input, $id, $account_id);
       return response::json([
           'code' => 200,
           'response' => $response
       ]);
    }

    public function createAnalysis(Request $request, $household_id){

        $input = $request->all();
        $response = $this->accountsRepository->createAnalysis($household_id, $input);

        return response::json([
           'code' => 200,
           'response' => $response
        ]);
    }

    public function createShortNotes(Request $request, $household_id){

        $data = $request->all();
        $response = $this->accountsRepository->createShortNotes($data,$household_id);

        return response::json([
           'code' => 200,
           'response' => $response
        ]);
    }

    public function getHouseHoldAnalysysNotes($householdId,$accountId){

        $response = $this->accountsRepository->getHouseHoldAnalysysNotes($householdId,$accountId);

        return response::json([
           'code' => 200,
           'response' => $response
        ]);
    }

    public function getHouseHoldNotes($id){

        $response = $this->accountsRepository->getHouseHoldNotes($id);

        return response::json([
           'code' => 200,
           'response' => $response
        ]);
    }

    public function getHouseHoldRecentNotes($id, $propertyId = null){
        
        $response = $this->accountsRepository->getHouseHoldRecentNotes($id, $propertyId);

        return response::json([
           'code' => 200,
           'response' => $response
        ]);
    }

    public function runAnalysisAllocation(Request $request,$household_id){

        //$data = $request->only(['accounts', 'household_id','startDate','endDate']);
        $accounts = '1';
        $startDate = '';
        $endDate = '';

        $response['allocation'] = $this->accountsRepository->getAllocations($household_id, $accounts);
        $response['current_value'] = $this->accountsRepository->getCurrentValue($household_id,$accounts);
        $response['total_contribution'] = $this->accountsRepository->getInOutFlows($household_id,$accounts,'contribution',$startDate,$endDate);
        $response['total_withdrawal'] = $this->accountsRepository->getInOutFlowsW($household_id,$accounts,'withdrawal',$startDate,$endDate);
        $response['total_income'] = $this->accountsRepository->getTotalIncome($household_id,$accounts,$startDate,$endDate);

        return Response::json([
            'code' => 200,
            'response' => $response
        ]);
    }

    public function getHouseHoldList(){

        $response = $this->accountsRepository->getHouseHoldList();

        return Response::json([
            'code' => 200,
            'response' => $response
        ]);
    }

    public function all(){
        try {
            $data = $this->accountsRepository->allAccounts(Auth::user());
            $response = [
                'code' => 200,
                'response' => $data
            ];
        } catch (Exception $e) {
            $response =  [
                'code'    => 500,
                'response' => $e->getMessage()
            ];
        }

        return response::json($response);
    }

    public function updateAccount($id, $account_id, Request $request){
        try {
            $input = $request->all();
            $data = $this->accountsRepository->updateAccount($id, $account_id, $input);
            $response = [
                'code' => 200,
                'response' => $data
            ];
        } catch (Exception $e) {
            $response =  [
                'code'    => 500,
                'response' => $e->getMessage()
            ];
        }

        return response::json($response);
    }

    public function getFundingAccount($id, $account_id)
    {
        try {
            $data = $this->accountsRepository->getFundingAccount($id, $account_id);
            $response = [
                'code' => 200,
                'response' => $data
            ];
        } catch (Exception $e) {
            $response =  [
                'code'    => 500,
                'response' => $e->getMessage()
            ];
        }

        return response::json($response);
    }

    public function deleteFundingAccount($id, $account_id, $account_fund_id)
    {
        try {
            $data = $this->accountsRepository->deleteFundingAccount($id, $account_id, $account_fund_id);
            $response = [
                'code' => 200,
                'response' => $data
            ];
        } catch (Exception $e) {
            $response =  [
                'code'    => 500,
                'response' => $e->getMessage()
            ];
        }

        return response::json($response);
    }

    public function saveFundingAccount(Request $request)
    {
        try {
            $request = $request->only([
                'account_id',
                'amount',
                'notes',
                'comment',
                'image',
                'file'
            ]);

            $data = $this->accountsRepository->saveFundingAccount(\Auth::id(), $request);

            $response = [
                'code' => 200,
                'response' => $data
            ];
        } catch (Exception $e) {
            $response =  [
                'code'    => 500,
                'response' => $e->getMessage()
            ];
        }

        return response::json($response);
    }

    public function getContributionOptions()
    {
        try {
            $data = $this->accountsRepository->getContributionOptions();
            $response = [
                'code' => 200,
                'response' => $data
            ];
        } catch (Exception $e) {
            $response =  [
                'code'    => 500,
                'response' => $e->getMessage()
            ];
        }

        return response::json($response);
    }

    public function updateShortNotes(Request $request, $id, $note_id){
        try {
            $userId = Auth::id();
            $note = $this->accountsRepository->updateShortNotes($note_id,$request->all());
            if($note){
                $note->load('tags');
                $note->load('mentions');
                $response = [
                    'code' => 200,
                    'response' => $note
                ];
            }else{
                throw new \Exception("Not not found", 500);

            }
        } catch (\Exception $e) {
            $response =  [
                'code'    => 500,
                'response' => $e->getMessage()
            ];
        }

        return response::json($response);
    }

    public function getTagsNotes($id)
    {
        try {
            $data = $this->accountsRepository->getTagsNotes($id);
            $response = [
                'code' => 200,
                'response' => $data
            ];
        } catch (Exception $e) {
            $response =  [
                'code'    => 500,
                'response' => $e->getMessage()
            ];
        }

        return response::json($response);
    }

    public function paginateNotes(Request $request, $id)
    {
        try {
            $data = $this->accountsRepository->paginateNotes($request->all(), $id);
            $response = [
                'code' => 200,
                'response' => $data
            ];
        } catch (Exception $e) {
            $response =  [
                'code'    => 500,
                'response' => $e->getMessage()
            ];
        }

        return response::json($response);
    }

    public function getHouseHoldNoteBtId($household,$noteId)
    {
        try {
            $data = $this->accountsRepository->getHouseHoldNoteBtId($noteId);
            $response = [
                'code' => 200,
                'response' => $data
            ];
        } catch (Exception $e) {
            $response =  [
                'code'    => 500,
                'response' => $e->getMessage()
            ];
        }

        return response::json($response);
    }


    public function getSimpleAccountTypes()
    {
        try {
            $data = $this->accountsRepository->getSimpleAccountTypes();
            $response = [
                'code' => 200,
                'response' => $data
            ];
        } catch (Exception $e) {
            $response =  [
                'code'    => 500,
                'response' => $e->getMessage()
            ];
        }

        return response::json($response);
    }

    public function getGraphPoints($household,Request $request)
    {
        try {
            $data = $this->accountsRepository->getGraphPoints($household,$request->input('requestData'));
            $response = [
                'code' => 200,
                'response' => $data
            ];
        } catch (Exception $e) {
            $response =  [
                'code'    => 500,
                'response' => $e->getMessage()
            ];
        }

        return response::json($response);
    }

    public function updateAccountFields($household_id, $account_id, Request $request)
    {
        try {
            $data = $this->accountsRepository->updateAccountFields( $account_id, $request->all());
            $response = [
                'code' => 200,
                'response' => $data
            ];
        } catch (Exception $e) {
            $response =  [
                'code'    => 500,
                'response' => $e->getMessage()
            ];
        }

        return response::json($response);
    }
}
