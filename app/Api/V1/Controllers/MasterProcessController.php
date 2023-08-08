<?php

namespace App\Api\Controllers;

use App\Api\Requests\MasterProcessRequest;
use App\Api\Requests\Request;
use App\Exports\ExportCustomerProcess;
use App\Http\Controllers\Controller;
use App\Models\MasterProcess;
use App\Models\MasterProcessSetting;
use App\Services\CommonService;
use App\Services\CompanyService;
use App\Services\CustomerService;
use App\Services\MasterProcessService;
use App\Services\PaypalService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Validator;

class MasterProcessController extends Controller
{
    /**
     * get Master Process
     *
     * @param  mixed $uuid
     * @return json Response.
     */
    public function getAllMasterProcess()
    {
        try {
            $masterProcess = MasterProcessService::getAllMasterProcess();
            return response()->json(['status_code' => 200, 'data' => $masterProcess], 200);
        } catch (Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }
    
    /**
     * Create Master Process
     *
     * @param  mixed $request
     * @return json Response.
     */
    public function createMasterProcess(Request $request)
    {
        try {
            $messages = [
                'title.unique' => 'The Title Has Already Been Taken.',
                'title.required' => 'The Title Field Is Required.',
                'description.required' => 'The Description Field Is Required.',
            ];
            $subscriptionIds = CommonService::getSubscriptionIdsForMasterProcess();
            $validator = Validator::make($request->all(), [
                'title' => ['required', Rule::unique('master_processes', 'title')->whereNull('deleted_at')->whereIn('subscription_id', $subscriptionIds)],
                // 'title' => 'required|max:255',
                'description' => 'required',
                'process_management_ids' => 'required',
            ], $messages);
            if ($validator->fails()) {
                return response()->json(['status_code' => 500, 'message' => $validator->errors()->first()], 500);    
            }
            MasterProcessService::createMasterProcess($request);
            return response()->json(['status_code' => 200, 'message' => "Master Process Created Successfully."], 200);
        } catch (Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * update Master Process
     *
     * @param  mixed $request
     * @return json Response.
     */
    public function updateMasterProcess($uuid, Request $request)
    {
        try {
            $masterProcess = MasterProcessService::getMasterProcessById($uuid);
            $messages = [
                'title.unique' => 'The Title Has Already Been Taken.',
                'title.required' => 'The Title Field Is Required.',
                'description.required' => 'The Description Field Is Required.',
            ];
            $validator = Validator::make($request->all(), [
                'title' => ['required', Rule::unique('master_processes', 'title')->whereNull('deleted_at')->where('user_id', Auth::user()->id)->ignore($masterProcess->id)],
                // 'title' => 'required|max:255',
                'description' => 'required',
                'process_management_ids' => 'required',
            ], $messages);
            if ($validator->fails()) {
                return response()->json(['status_code' => 500, 'message' => $validator->errors()->first()], 500);    
            }
            MasterProcessService::updateMasterProcess($uuid, $request);
            return response()->json(['status_code' => 200, 'message' => "Master Process Updated Successfully."], 200);
        } catch (Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * get Master Process by ID
     *
     * @param  mixed $uuid
     * @return json Response.
     */
    public function getMasterProcessById($uuid)
    {
        try {
            $masterProcess = MasterProcessService::getMasterProcessById($uuid);
            return response()->json(['status_code' => 200, 'data' => $masterProcess], 200);
        } catch (Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * delete Master Process by ID
     *
     * @param  mixed $uuid
     * @return json Response.
     */
    public function deleteMasterProcess($uuid)
    {
        try {
            MasterProcessService::deleteMasterProcessByUuid($uuid);
            return response()->json(['status_code' => 200, 'message' => 'Master Process Deleted Successfully.'], 200);
        } catch (Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    
    /**
     * Store Master Process Ans
     *
     * @param  mixed $uuid
     * @return json Response.
     */
    public function saveMasterProcessAnswers(Request $request){
        try {
            MasterProcessService::saveMasterProcessAnswers($request->all());
            return response()->json(['status_code' => 200, 'message' => 'Master Process Form Submitted Successfully.'], 200);
        } catch(Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

        
    /**
     * Get Master Process Answer By ID
     *
     * @param  mixed $uuid
     * @return json Response.
     */
    public function getMasterProcessAnswerById($uuid){
        try {
            $answers = MasterProcessService::getMasterProcessAnswerById($uuid);
            return response()->json(['status_code' => 200, 'data' => $answers], 200);
        } catch(Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }
        
    /**
     * Duplicate master process
     *
     * @param  mixed $request
     * @return json Response.
     */
    public function cloneMasterProcess(Request $request){
        try {
            $messages = [
                'title.unique' => 'The Title Has Already Been Taken.',
                'title.required' => 'The Title Field Is Required.',
                'description.required' => 'The Description Field Is Required.',
                'uuid.required' => 'The Uuid Field Is Required.',
            ];
            $validator = Validator::make($request->all(), [
                'title' => ['required', Rule::unique('master_processes', 'title')->whereNull('deleted_at')],
                // 'title' => 'required|unique:master_processes,title,deleted_at,NULL',
                'description' => 'required',
                'uuid' => 'required',
            ], $messages);
            if ($validator->fails()) {
                return response()->json(['status_code' => 500, 'message' => $validator->errors()->first()], 500);    
            }
            $uuid = $request->uuid;
            $subscription = CommonService::getActiveSubscriptionDetails();
            $data = ['title' => $request->title, 'description' => $request->description, 'subscription_id' => $subscription->id];
            $masterProcess = MasterProcessService::cloneMasterProcess($uuid, $data);
            return response()->json(['status_code' => 200,'message' => 'Master Process Was Cloned Successfully.' ,'data' => $masterProcess], 200);
        } catch(Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Duplicate master process
     *
     * @param  mixed $request
     * @return json Response.
     */
    public function cancelMasterProcess(Request $request){
        try {
            if(!empty($request->process_ids)) {
                MasterProcessService::cancelMasterProcess($request->process_ids);
            } 
            return response()->json(['status_code' => 200,'message' => 'Master Process Cancelled Successfully.' ,'data' => []], 200);
        } catch(Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Preview Master Process
     *
     * @param  mixed $request
     * @return json Response.
     */
    public function previewMasterProcess($uuid){
        try {
            $masterProcess = MasterProcessService::getMasterProcessById($uuid);
            return response()->json(['status_code' => 200, 'data' => $masterProcess], 200);
        } catch(Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Preview Master Process
     *
     * @param  mixed $request
     * @return json Response.
     */
    public function assignMasterProcess(Request $request){
        try {
            MasterProcessService::assignMasterProcess($request->all(), $request->masterprocessId);
            return response()->json(['status_code' => 200, 'message' => 'Master process sent successfully.', 'data' => []], 200);
        } catch(Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    
    
    /**
     * 
     *
     * @param  mixed $request
     * @return json Response.
     */
    public function getMasterProcessFormForUser($companyId, $user, $processId, $uuid){
        try {
            $processData = MasterProcessService::getProcessFormForCustomer($companyId, $user, $processId, $uuid);
            return response()->json(['status_code' => 200, 'data' => $processData], 200);
        } catch(Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }
    
    
    /**
     * Get Customer Processes By Company.
     *
     * @param  mixed $request
     * @return json Response.
     */
    public function getAllCustomerProcess(Request $request){
        try {
            $processData        = [];
            $currentRole        = CommonService::getCurrentRole();
            $search             = $request->search ?? null;
            $sortTitle          = $request->sort ?? 'created_at';
            $sortOrder          = $request->order ?? 'DESC';
            $companyUuid        = $request->companyId ?? null;
            $masterProcessUuid  = $request->masterProcessId ?? null;
            $customerUuid       = $request->customerId ?? null;
            $paginate           = true;
            $companyId          = null;
            $companyData          = null;
            $masterProcessId    = null; 
            $masterProcessData  = null;
            $customerId         = null; 
            $customerData       = null;
            if(!empty($companyUuid)) {
                $company            = CompanyService::getCompanyByUuid($companyUuid);
                $companyData       = ['uuid' => $company->uuid, 'name' => $company->first_name .' '. $company->last_name];
                $companyId          = $company->id;
            }
            if(!empty($masterProcessUuid)) {
                $masterProcess      = MasterProcessService::getMasterProcessById($masterProcessUuid);
                $masterProcessData  = ['uuid' => $masterProcess->uuid, 'name' => $masterProcess->title];
                $masterProcessId        = $masterProcess->id;
            }
            if(!empty($customerUuid)) {
                $customer           = CustomerService::getCustomerByUuid($customerUuid);
                $customerData       = ['uuid' => $customer->uuid, 'name' => $customer->first_name.' '.$customer->last_name];
                $customerId         = $customer->id;
            }
            if ($currentRole->id == SUPER_ADMIN_ROLE || $currentRole->id == SUB_ADMIN_ROLE) { 
                $processData        = MasterProcessService::getCustomerProcess($search, $sortTitle, $sortOrder, $companyId, $paginate, $masterProcessId, $customerId);
            } else if ($currentRole->id == COMPANY_ROLE || $currentRole->id == TEAM_ROLE) { // login user is company.
                $companyId          = ($currentRole->id == TEAM_ROLE) ? Auth::user()->parent_id : null;
                $processData        = MasterProcessService::getCustomerProcess($search, $sortTitle, $sortOrder, $companyId, $paginate, $masterProcessId, $customerId);
            }
            return response()->json([
                'status_code' => 200, 
                'data' => [
                    'customerProcessData'   => $processData, 
                    'masterProcessData'     => $masterProcessData, 
                    'customerData'          => $customerData,
                    'companyData'          => $companyData
                ]], 200);
        } catch(Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }


    /**
     * Get Customer Processes By UUID.
     *
     * @param  mixed $uuid
     * @return json Response.
     */
    public function getCustomerProcessById($uuid) {
        try {
            $customerProcess    = MasterProcessService::getCustomerProcessByUuid($uuid);
            $masterProcessData  = MasterProcess::whereId($customerProcess->masterprocess_id)->first();
            $masterProcess      = MasterProcessService::getMasterProcessById($masterProcessData->uuid, true, $customerProcess->uuid);
            return response()->json(['status_code' => 200, 'data' => ['customerProcess' => $customerProcess, 'masterProcess' => $masterProcess]], 200);
        } catch(Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Save Customer Processes By UUID.
     *
     * @param  mixed $uuid
     * @return json Response.
     */
    public function saveCustomerProcessForm(Request $request, $uuid) {
        try {
            $data = MasterProcessService::saveCustomerProcess($request->all(), $uuid);
            return response()->json(['status_code' => 200, 'message' => $data['lastUpdatedProcess'].' Saved Successfully.', 'data' => ['is_link_expired' => $data['is_link_expired']]], 200);
        } catch(Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    public function sendCustomerProcessRemainder($customerProcessId) 
    {
        try {
            MasterProcessService::sendRemainder($customerProcessId);
            return response()->json(['status_code' => 200, 'data' => 'Customer process remainder sent successfully.'], 200);
        } catch(Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    public function sendProcessRemainders() 
    {
        try {
            MasterProcessService::sendRemainders();
            return response()->json(['status_code' => 200, 'data' => 'Customer process remainder sent successfully.'], 200);
        } catch(Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    public function initiatePaypalPayment(Request $request) 
    {
        try {
            $company = CompanyService::getCompanyByUuid($request->companyId);
            $paypalConfiguration = $company->paypalConfiguration()->first();
            if(empty($paypalConfiguration)) {
                throw new Exception("No paypal configuration found for this tenant.");
            }
            $paypal = new PaypalService($paypalConfiguration->client_id, $paypalConfiguration->client_secret);
            $paypalUrl = $paypal->processTransaction($request);
            return response()->json(['status_code' => 200, 'message' => 'success', 'data' => ['redirectUrl' => $paypalUrl]], 200);
        } catch(Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    public function exportCustomerProcessCsv(Request $request) 
    {
        try {
            $search = $request->search ?? null;
            $sortTitle = $request->sort ?? 'id';
            $sortOrder = $request->order ?? 'desc';
            $companyId = $request->companyId ?? null;
            $masterProcessUuid = $request->masterProcessId ?? null;
            $customerId = $request->customerId ?? null;
            $masterProcessId = null;
            if(!empty($masterProcessUuid)) {
                $masterProcess      = MasterProcessService::getMasterProcessById($masterProcessUuid);
                $masterProcessId    = $masterProcess->id;
            }

            /*============= store company data csv file in aws s3 buckets-start===========*/
            $fileName = 'customer-process-' . time() . '.csv';
            $path = 'export/customer-process/' . $fileName;
            Excel::store(new ExportCustomerProcess($search, $sortTitle, $sortOrder, $masterProcessId, $customerId, $companyId), $path, 's3');
            $filePath = Storage::disk('s3')->url($path);
            /*============= store company data csv file in aws s3 buckets-end===========*/
            return response()->json(['status_code' => 200, 'message' => 'success', 'data' => ['url' => $filePath]], 200);
        } catch(Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    public function getProcessCustomerCount($masterProcessId)
    {
        try {
            $masterProcess = MasterProcessService::getMasterProcessById($masterProcessId);
            if(empty($masterProcess)) {
                throw new Exception("Invalid master process id.");
            }
            $customerCount = MasterProcessService::getCustomerProcess(masterProcessId: $masterProcess->id);
            $string = ($customerCount->count() > '1') ? 'customers' : 'customer';
            return response()->json([
                'status_code' => 200, 
                'message' => 'success', 
                'data' => [
                    'customerCount'     => $customerCount->count() ?? 0,
                    'editMessage'       => 'If you change this process, all of the previous responses will be deleted because it is allocated to '.$customerCount->count().' '.$string.'. Are you sure that you want to update this process?',
                    'deleteMessage'     => 'If you delete this process, all of the previous responses will be deleted because it is allocated to '.$customerCount->count().' '.$string.'. Are you sure that you want to delete this process?',
                ]
            ], 200);
        } catch(Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    
    public function getSettingsByMasterProcess($masterProcessId)
    {
        try {
            $masterProcess = MasterProcessService::getMasterProcessById($masterProcessId);
            if(empty($masterProcess)) {
                throw new Exception("Invalid master process id.");
            }
            return response()->json([
                'status_code' => 200, 
                'message' => 'success', 
                'data' => $masterProcess->settings ?? []
            ]);
        } catch(Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }
    
    public function saveSettingsByMasterProcess($masterProcessId, Request $request)
    {
        try {
            $masterProcess = MasterProcessService::getMasterProcessById($masterProcessId);
            if(empty($masterProcess)) {
                throw new Exception("Invalid master process id.");
            }
            $settings = $masterProcess->settings()->first();
            if(empty($settings)) {
                $settings = new MasterProcessSetting();
                $settings->master_process_id = $masterProcess->id;
            }
            $settings->remainder_days = $request->remainder_days ?? null;
            $settings->save();
            return response()->json([
                'status_code' => 200, 
                'message' => 'Master process settings saved successfully.', 
                'data' => $settings ?? []
            ]);
        } catch(Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }
}
