<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerMasterProcess;
use App\Models\CustomerProcessAnswer;
use App\Models\CustomerProcessPayment;
use App\Models\MasterProcess;
use App\Models\MasterProcessAnswer;
use App\Models\MasterProcessUser;
use App\Models\ProcessManagement;
use App\Models\ProcessManagementResponse;
use App\Models\User;
use App\Notifications\EmailNotification;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Ramsey\Uuid\Uuid;
use Stripe\Token;

class MasterProcessService
{

    public static function createMasterProcess($data)
    {
        try {
            $subscription = CommonService::getActiveSubscriptionDetails();
            $record = [
                'title' => $data->title,
                'description' => $data->description,
                'user_id' => Auth::user()->id,
                'subscription_id' => $subscription->id ?? 0,
            ];
            DB::beginTransaction();
            $masterProcess = MasterProcess::create($record);
            if (!empty($data->process_management_ids)) {
                $ids = [];
                $order = 1;
                foreach ($data->process_management_ids as $id) {
                    $ids[$id] = ['order' => $order];
                    $order++;
                }
                $masterProcess->processManagements()->sync($ids);
            }
            DB::commit();
            return $masterProcess;
        } catch (Exception $e) {
            DB::rollback();
            throw new Exception($e->getMessage());
        }
    }

    public static function updateMasterProcess($uuid, $data)
    {
        try {
            DB::beginTransaction();
            $masterProcess = MasterProcess::where('uuid', $uuid)
                ->where('user_id', Auth::user()->id)
                ->first();
            if (!empty($masterProcess)) {
                $masterProcess->title = $data->title;
                $masterProcess->description = $data->description;
                $masterProcess->save();
                if (!empty($data->process_management_ids)) {
                    $ids = [];
                    $order = 1;
                    foreach ($data->process_management_ids as $id) {
                        $ids[$id] = ['order' => $order];
                        $order++;
                    }
                    $masterProcess->processManagements()->sync($ids);
                    self::clearFormAndNotifyUser($masterProcess);
                }
                DB::commit();
                return $masterProcess;
            }
            DB::rollback();
            throw new Exception('No master process found!');
        } catch (Exception $e) {
            DB::rollback();
            throw new Exception($e->getMessage());
        }
    }

    public static function getMasterProcessById($uuid, $withAns = null, $customerProcessId = null)
    {
        try {
            $masterProcess = MasterProcess::where('uuid', $uuid)->with(['processManagements.questions.option', 'processManagements.questions.settings', 'settings'])->first();
            if (empty($masterProcess)) {
                throw new Exception('No master process found!');
            }
            if (!empty($withAns) && !empty($customerProcessId)) {
                $processManagements = $masterProcess->processManagements;
                if (!empty($processManagements)) {
                    foreach ($processManagements as $processManagement) {
                        $reqQuestions = $processManagement->questions()->where('is_required', '1')->count();
                        $isCompleted = false;
                        $requiredAnswers = 0;
                        $nonRequiredAnswers = 0;
                        $questions = $processManagement->questions;
                        foreach ($questions as $question) {
                            $customerProcess = CustomerMasterProcess::where('uuid', $customerProcessId)->first();
                            if (!empty($customerProcess)) {
                                $answer = CustomerProcessAnswer::with([
                                    'paymentDetails' => function ($q) {
                                        $q->orderBy('created_at', 'desc');
                                    }
                                ])->where('customer_process_id', $customerProcess->id)
                                    ->where('process_management_id', $processManagement->id)
                                    ->where('question_id', $question->id)->first();
                                if (!empty($answer) && $question->is_required == '1') {
                                    $requiredAnswers++;
                                }
                                if (!empty($answer) && $question->is_required == '0') {
                                    $nonRequiredAnswers++;
                                }
                                $question->setAttribute('answer', $answer);
                            }
                        }
                        if (($reqQuestions > 0 && $requiredAnswers == $reqQuestions) || ($reqQuestions == 0 && $nonRequiredAnswers > 0)) {
                            $isCompleted = true;
                        }
                        $processManagement->setAttribute('is_completed', $isCompleted);
                    }
                }
            }
            return $masterProcess;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public static function getAllMasterProcess()
    {
        try {
            $currentRole = CommonService::getCurrentRole();
            if ($currentRole->id == SUPER_ADMIN_ROLE || $currentRole->id == SUB_ADMIN_ROLE) {
                $masterProcess = MasterProcess::with('user.roles')->withCount(['customerProcess', 'customerPendingProcess', 'customerInProgressProcess', 'customerCompletedProcess', 'customerExpiredProcess'])->get();
            }
            if ($currentRole->id == COMPANY_ROLE || $currentRole->id == TEAM_ROLE) {
                $subscriptionIds = CommonService::getSubscriptionIdsForMasterProcess();
                // Get team members and Company Ids
                $userIds = User::where(function ($q) {
                    $q->where('id', auth()->user()->id);
                    $q->orWhere('id', auth()->user()->parent_id);
                    $q->orWhere('parent_id', auth()->user()->id);
                })
                    ->whereHas('roles', function ($q) {
                        $q->where('role_id', TEAM_ROLE);
                        $q->orWhere('role_id', COMPANY_ROLE);
                    })->pluck('id')->toArray();

                $masterProcess = MasterProcess::whereIn('user_id', $userIds)
                    ->where('subscription_id', 0)
                    ->orWhere(function ($q) use ($subscriptionIds) {
                        $q->whereIn('subscription_id', $subscriptionIds);
                    })->withCount(['customerProcess', 'customerPendingProcess', 'customerInProgressProcess', 'customerCompletedProcess', 'customerExpiredProcess'])
                    ->get();
            }
            return $masterProcess;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public static function deleteMasterProcessByUuid($uuid)
    {
        try {
            DB::beginTransaction();
            $masterProcess = MasterProcess::where('uuid', $uuid)->where('user_id', Auth::user()->id)->first();
            if (!empty($masterProcess)) {
                $customerProcesses = self::getCustomerProcess(masterProcessId: $masterProcess->id);
                if (count($customerProcesses) > 0) {
                    foreach ($customerProcesses as $customerProcess) {
                        // send process termination mail to customer when master process is deleted.
                        NotificationService::sendProcessTerminationMailToCustomer($customerProcess);
                        $customerProcess->delete();
                    }
                }
                // $masterProcess->processManagements()->delete();
                $masterProcess->delete();
                DB::commit();
                return true;
            }
            DB::rollBack();
            throw new Exception('No master process found!');
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception($e->getMessage());
        }
    }

    public static function addProcessManagementToMasterProcessByUuid($uuid, $processId)
    {
        try {
            $masterProcess = MasterProcess::where('uuid', $uuid)->where('user_id', Auth::user()->id)->first();
            if (!empty($masterProcess)) {
                $data = $masterProcess->processManagements()->pluck('id')->toArray();
                array_push($data, $processId);
                if (!empty($data)) {
                    $ids = [];
                    $order = 1;
                    foreach ($data as $id) {
                        $ids[$id] = ['order' => $order];
                        $order++;
                    }
                    $masterProcess->processManagements()->sync($ids);
                }
                return true;
            }
            throw new Exception('No master process found!');
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public static function saveMasterProcessAnswers($answers)
    {
        try {
            $masterProcess = self::getMasterProcessById($answers['uuid']);
            if (!empty($masterProcess)) {
                $ans = [
                    'user_id' => Auth::user()->id,
                    'master_process_id' => $masterProcess->id
                ];
                $ansData = self::createMasterProcessAnswer($ans);
                foreach ($answers['answers'] as $processManagement) {
                    $process = $masterProcess->processManagements()->where('uuid', $processManagement['process_management'])->first();
                    if (!empty($processManagement['question_answers']) && !empty($process)) {
                        foreach ($processManagement['question_answers'] as $answer) {
                            $processQuestion = $process->questions()->where('uuid', $answer['question_uuid'])->first();
                            if (!empty($processQuestion)) {
                                $processResponse = new ProcessManagementResponse;
                                $processResponse->master_process_answer_id = $ansData->id;
                                $processResponse->process_management_id = $process->id;
                                $processResponse->question_id = $processQuestion->id;
                                $processResponse->answer = $answer['answer'];
                                $processResponse->save();
                            }
                        }
                    }
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public static function createMasterProcessAnswer($data)
    {
        $answerData = MasterProcessAnswer::create($data);
        return $answerData;
    }

    public static function getMasterProcessAnswerById($uuid)
    {
        try {
            $data = MasterProcessAnswer::where('uuid', $uuid)->first();
            if (!empty($data)) {
                $answers = self::getProcessManagementAnswerById($data->id);
                $data->setAttribute('process_management_response', $answers);
                return $data;
            }
            throw new Exception('No master process answer found!');
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public static function getProcessManagementAnswerById($answerId)
    {
        $answers = [];
        $ans = ProcessManagementResponse::select('id', 'answer', 'process_management_id', 'question_id')
            ->where('master_process_answer_id', $answerId)
            ->with('question.option')
            ->get()
            ->groupBy('process_management_id');
        foreach ($ans as $key => $a) {
            $processManagement = ProcessManagement::whereId($key)->first();
            $answers[$key]['process_id'] = $processManagement->id;
            $answers[$key]['process_management'] = $processManagement->lead_name;
            $answers[$key]['process_management_description'] = $processManagement->lead_details;
            $answers[$key]['answers'] = $a;
        }
        return $answers;
    }

    public static function cloneMasterProcess($uuid, $data)
    {
        try {
            DB::beginTransaction();
            $masterProcess = MasterProcess::where('uuid', $uuid)->first();
            if (!empty($masterProcess)) {
                $clone = $masterProcess->replicateRow($data);
                DB::commit();
                return self::getMasterProcessById($clone->uuid);
            }
            DB::rollBack();
            throw new Exception('No master process found!');
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception($e->getMessage());
        }
    }

    public static function cancelMasterProcess($processIds)
    {
        try {
            DB::beginTransaction();
            $processIds = ProcessManagement::whereIn('id', $processIds)->each(function ($p) {
                $p->questions()->each(function ($q) {
                    $q->option()->forceDelete();
                    $q->forceDelete();
                });
                $p->forceDelete();
            });
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception($e->getMessage());
        }
    }

    public static function assignMasterProcess($data, $uuid)
    {
        try {
            $userEmails = [];
            DB::beginTransaction();
            $masterProcess = self::getMasterProcessById($uuid);
            $steps = $masterProcess->processManagements()->count();
            // for existing emails
            if (!empty($data['userIds'])) {
                foreach ($data['userIds'] as $id) {
                    $userData = CustomerService::getCustomerByUuid($id);
                    if (!empty($userData)) {
                        $user = ['uuid' => $userData->uuid, 'email' => $userData->email];
                        array_push($userEmails, $user);
                    }
                }
            }
            // For new emails
            // if(!empty($data['emailIds'])) {
            //     foreach($data['emailIds'] as $e) {
            //         $user = ['uuid' => null, 'email' => $e];
            //         array_push($userEmails, $user);
            //     }
            // }
            // send emails to all users emails
            if (!empty($userEmails)) {
                foreach ($userEmails as $email) {
                    $customerData = [];
                    if ($email['uuid']) {
                        $user = CustomerService::getCustomerByUuid($email['uuid'], Auth::user()->id);
                        $customerData['user_id'] = $user->id;
                        $userId = $user->uuid;
                    }
                    $customerData['uuid'] = (string) Uuid::uuid4();
                    $customerData['assigned_by'] = Auth::user()->id;
                    $customerData['masterprocess_id'] = $masterProcess->id;
                    $customerData['email'] = $email['email'];
                    $customerData['user_uuid'] = $userId;
                    $customerData['progress'] = '0/' . $steps;
                    // create customer process 
                    $data = self::createMasterProcessForCustomer($customerData);
                    // Process Link
                    $processLink = env('FRONT_URL', 'https://hi-benjie.netlify.app') . '/master-process/' . Auth::user()->uuid . '/' . $userId . '/' . $masterProcess->uuid . '/' . $data->uuid;
                    // send email to given customer emails
                    $emailData = [
                        'title' => 'New Process Assigned',
                        'body' => 'I hope this email finds you well. I am writing to assign a new process to you as part of our ongoing initiatives. The details of the process are as follows:',
                        'action' => [
                            'title' => 'Click Here',
                            'url' => $processLink,
                        ]
                    ];
                    Notification::route('mail', $email['email'])->notify(new EmailNotification($emailData));
                    $notified = ['is_notified' => '1', 'process_link' => $processLink];
                    self::updateMasterProcessForCustomer($notified, $data->uuid);
                }
            }
            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            throw new Exception($e->getMessage());
        }
    }

    public static function createMasterProcessForCustomer($data)
    {
        try {
            $data = CustomerMasterProcess::create($data);
            return $data;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public static function updateMasterProcessForCustomer($data, $uuid)
    {
        try {
            CustomerMasterProcess::where('uuid', $uuid)->update($data);
            return true;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public static function getProcessFormForCustomer($companyId, $user, $processId, $uuid)
    {
        try {
            $masterProcess = MasterProcessService::getMasterProcessById($processId, true, $uuid);
            $company = CompanyService::getCompanyByUuid($companyId);
            $process = CustomerMasterProcess::where('assigned_by', $company->id)
                ->where('masterprocess_id', $masterProcess->id)
                ->where('user_uuid', $user)
                ->where('uuid', $uuid)
                ->withTrashed()
                ->first();
            if (empty($process)) {
                throw new Exception('Invalid process url!');
            }
            if ($process->trashed()) {
                return ['is_link_expired' => $process->is_link_expired, 'message' => 'Your process link has been expired.'];
            }
            if ($process->status == '2') {
                return ['is_link_expired' => '1', 'message' => 'Your process has already been submitted.'];
            }
            if (!empty($process->user_id)) {
                $customer = CustomerService::getCustomerByUuid($user, $company->id);
            }
            $response = [
                'company' => $company,
                'customer' => $customer ?? null,
                'masterProcess' => $masterProcess,
                'customerProcess' => $process,
            ];
            return $response;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public static function getCustomerProcess($search = null, $sortTitle = null, $sortOrder = null, $companyId = null, $paginate = null, $masterProcessId = null, $customerId = null, array $status = null)
    {
        try {
            if (empty($companyId)) {
                $companyId = Auth::user()->id;
            }
            if (empty($sortTitle) && empty($sortOrder)) {
                $sortTitle = 'created_at';
                $sortOrder = 'DESC';
            }
            if ($sortTitle == 'customer_name') {
                $sortTitle = 'user_id';
            }
            $customerProcessData = CustomerMasterProcess::where('assigned_by', $companyId)->orderBy($sortTitle, $sortOrder)->with([
                'customer' => function ($query) use ($sortTitle, $sortOrder) {
                    if ($sortTitle == 'user_id') {
                        $query->orderBy('first_name', $sortOrder);
                    }
                },
                'master_process'
            ]);
            if (!empty($status)) {
                $customerProcessData = $customerProcessData->whereIn('status', $status);
            }
            if (!empty($masterProcessId)) {
                $customerProcessData = $customerProcessData->where('masterprocess_id', $masterProcessId);
            }
            if (!empty($customerId)) {
                $customerProcessData = $customerProcessData->where('user_id', $customerId);
            }
            if (!empty($search)) {
                $customerProcessData = $customerProcessData->where(function ($q) use ($search) {
                    $q->where('email', 'LIKE', '%' . $search . '%');
                    $q->orWhere('uuid', 'LIKE', '%' . $search . '%');
                    $q->orWhere('user_uuid', 'LIKE', '%' . $search . '%');
                    if ($search == 'pending') {
                        $q->orWhere('status', 0);
                    }
                    if ($search == 'on going') {
                        $q->orWhere('status', 1);
                    }
                    if ($search == 'completed') {
                        $q->orWhere('status', 2);
                    }
                    $q->orWhereHas('assignedBy', function ($r) use ($search) {
                        $r->where('first_name', 'LIKE', '%' . $search . '%');
                        $r->orWhere('last_name', 'LIKE', '%' . $search . '%');
                    });
                    $q->orWhereHas('customer', function ($r) use ($search) {
                        $r->where('first_name', 'LIKE', '%' . $search . '%');
                        $r->orWhere('last_name', 'LIKE', '%' . $search . '%');
                    });
                });
            }
            if ($paginate) {
                return $customerProcessData->paginate(10);
            }
            return $customerProcessData->get();
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public static function getCustomerProcessByUuid($uuid)
    {
        try {
            $process = CustomerMasterProcess::where('uuid', $uuid)->with(['assignedBy', 'customer'])->first();
            if (empty($process)) {
                throw new Exception('Customer process not found!');
            }
            return $process;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public static function saveCustomerProcess($answers, $uuid)
    {
        try {
            $completedSteps = 0;
            $customerProcess = self::getCustomerProcessByUuid($uuid);
            $companyDetails = CompanyService::getCompanyByUuid($answers['companyId']);
            $customerDetails = CustomerService::getCustomerByUuid($answers['customerId']);
            $lastCompletedProcess = null;
            if (empty($customerProcess)) {
                throw new Exception("No customer process found.");
            }
            if (!empty($customerProcess)) {
                $assignedUser = CompanyService::getUserById($customerProcess->assigned_by);
                $masterProcess = $customerProcess->master_process;
                $processManagements = $masterProcess->processManagements;
                if (empty($processManagements)) {
                    throw new Exception("No process management found.");
                }
                if (!empty($processManagements) && (!empty($answers['processManagements']))) {
                    $totalSteps = $processManagements->count();
                    foreach ($processManagements as $processManagement) {
                        $reqQuestions = $processManagement->questions()->where('is_required', '1')->count();
                        $requiredAnswers = 0;
                        $nonRequiredAnswers = 0;
                        $processMgtKey = self::searchForUuid($processManagement->uuid, $answers['processManagements']);
                        $questions = $processManagement->questions;
                        end($answers['processManagements']);
                        $lastProcessKey = key($answers['processManagements']);
                        \Log::info($lastProcessKey);
                        if (
                            isset($processMgtKey) &&
                            $processManagement->uuid == $answers['processManagements'][$processMgtKey]['uuid']
                        ) {
                            if (!empty($questions) && count($questions) > 0) {
                                foreach ($questions as $question) {
                                    $charge = null;
                                    $questKey = self::searchForUuid($question->uuid, $answers['processManagements'][$processMgtKey]['questions']);
                                    $questionAns = $answers['processManagements'][$processMgtKey]['questions'][$questKey] ?? null;
                                    if (!empty($questionAns)) {
                                        $ans = $questionAns['answer'];
                                        $paymentSlugs = ['Stripe', 'paypal'];
                                        if (is_array($ans) && !in_array($question->module_slug, $paymentSlugs)) {
                                            $ans = implode(',', $ans);
                                        }
                                        if (is_array($ans) && in_array($question->module_slug, $paymentSlugs)) {
                                            // For Stripe Payment
                                            $token = $questionAns['answer']['token'] ?? null;
                                            if ($question->module_slug == 'Stripe' && !empty($token)) {
                                                $stripeDetails = $companyDetails->stripeConfiguration()->first();
                                                if (!empty($stripeDetails)) {
                                                    $stripe = new StripeService(
                                                        $stripeDetails->stripe_key,
                                                        $stripeDetails->stripe_secret,
                                                    );
                                                    // ********************* testing code ********************** //
                                                    // Token will be given by front end
                                                    $cardData = array(
                                                        "card" => array(
                                                            "number" => '4242424242424242',
                                                            "exp_month" => '05',
                                                            "exp_year" => '2025',
                                                            "cvc" => '542',
                                                            "name" => 'Dhruv Test'
                                                        )
                                                    );
                                                    $token = $stripe->getStripeToken($cardData);
                                                    // ********************* testing code ********************** //
                                                    $charge = $stripe->createCharge(20, $token->id);
                                                }
                                            }
                                            // For Paypal Payment
                                            $payerId = $questionAns['answer']['payerId'] ?? null;
                                            $paymentId = $questionAns['answer']['paymentId'] ?? null;
                                            if ($question->module_slug == 'paypal' && (!empty($payerId) && !empty($paymentId))) {
                                                $paypalConfiguration = $companyDetails->paypalConfiguration()->first();
                                                if (!empty($paypalConfiguration)) {
                                                    $paypal = new PaypalService(
                                                        $paypalConfiguration->client_id,
                                                        $paypalConfiguration->client_secret
                                                    );
                                                    $charge = $paypal->makePaypalPayment($payerId, $paymentId);
                                                }
                                            }
                                            $ans = $question->module_slug;
                                        }
                                        $answer = CustomerProcessAnswer::where('customer_process_id', $customerProcess->id)
                                            ->where('process_management_id', $processManagement->id)
                                            ->where('question_id', $question->id)
                                            ->first();
                                        $answerData = [
                                            'customer_process_id' => $customerProcess->id,
                                            'process_management_id' => $processManagement->id,
                                            'question_id' => $question->id,
                                            'answer' => (string) $ans,
                                        ];
                                        if (empty($answer)) {
                                            $answer = self::createCustomerAnswer($answerData);
                                            if (!empty($charge)) {
                                                $data = [
                                                    'customer_id' => $customerDetails->id,
                                                    'process_management_id' => $processManagement->id,
                                                    'question_id' => $question->id,
                                                    'answer_id' => $answer->id,
                                                    'type' => strtolower($ans),
                                                    'transaction_id' => (is_array($charge)) ? $charge['id'] : $charge->id,
                                                    'json' => (string) json_encode($charge)
                                                ];
                                                if (strtolower($ans) == 'stripe') {
                                                    $data['status'] = ($charge->status == 'succeeded') ? 'success' : 'fail';
                                                    $data['amount'] = $charge->amount / 100;
                                                } else if (strtolower($ans) == 'paypal') {
                                                    $data['status'] = ($charge['state'] == 'approved') ? 'success' : 'fail';
                                                    if (!empty($charge['transactions']) && !empty($charge['transactions'][0])) {
                                                        $data['amount'] = $charge['transactions'][0]['amount']['total'] ?? '0.00';
                                                    }
                                                }
                                                self::createCustomerProcessPayment($data);
                                            }
                                        } else {
                                            $payment = CustomerProcessPayment::where('answer_id', $answer->id)->first();
                                            if ((empty($payment) || (!empty($payment) && $payment->status == 'fail')) && !empty($charge)) {
                                                $data = [
                                                    'customer_id' => $customerDetails->id,
                                                    'process_management_id' => $processManagement->id,
                                                    'question_id' => $question->id,
                                                    'answer_id' => $answer->id,
                                                    'type' => strtolower($ans),
                                                    'transaction_id' => (is_array($charge)) ? $charge['id'] : $charge->id,
                                                    'json' => (string) json_encode($charge)
                                                ];
                                                if (strtolower($ans) == 'stripe') {
                                                    $data['status'] = ($charge->status == 'succeeded') ? 'success' : 'fail';
                                                    $data['amount'] = $charge->amount / 100;
                                                } else if (strtolower($ans) == 'paypal') {
                                                    $data['status'] = ($charge['state'] == 'approved') ? 'success' : 'fail';
                                                    if (!empty($charge['transactions']) && !empty($charge['transactions'][0])) {
                                                        $data['amount'] = $charge['transactions'][0]['amount']['total'] ?? '0.00';
                                                    }
                                                }
                                                self::createCustomerProcessPayment($data);
                                            }
                                            self::updateCustomerAnswer($answer->id, $answerData);

                                        }
                                        if ($question->is_required == '1') {
                                            $requiredAnswers++;
                                        }
                                        if ($question->is_required == '0') {
                                            $nonRequiredAnswers++;
                                        }
                                    }
                                }
                            }
                            $lastCompletedProcess = $processManagement->lead_name ?? 'Customer Process';
                            if ($processMgtKey == $lastProcessKey) {
                                $statusText = ($totalSteps == $lastProcessKey + 1) ? 'Completed' : 'Submitted';
                                // send email to given customer emails
                                $emailData = [
                                    'title' => 'Master Process ' . $statusText,
                                    'body' => "I hope this email finds you well. I am writing to inform you that the user has successfully " . $statusText . " the process as required. This email serves as a notification that the necessary steps have been fulfilled, and the user is now ready to proceed with the next phase or receive any further assistance if needed.",
                                    'lines' => [
                                        'Here are the details regarding the Customer and the completed process:',
                                        '**Customer Information:**',
                                        '**Id :** ' . $customerProcess->uuid,
                                        '**Process Title :** ' . $customerProcess->master_process->title,
                                        '**Customer Name :** ' . $customerProcess->customer->first_name . ' ' . $customerProcess->customer->last_name,
                                        '**Process Step Name :** ' . $lastCompletedProcess,
                                        '**Email :** ' . $customerProcess->customer->email,
                                        '**Completion Date :** ' . date('d-m-Y H:i A'),
                                        'We kindly request you to review this information and take the appropriate action, whether it involves assigning the user to the next responsible party or addressing any outstanding tasks related to the process. If there are any further steps or requirements on your end, please notify the user or contact them directly using the provided email address.'
                                    ]
                                ];
                                $assignedUser->notify(new EmailNotification($emailData));
                            }
                        }
                        if (($reqQuestions > 0 && $requiredAnswers == $reqQuestions) || ($reqQuestions == 0 && $nonRequiredAnswers > 0)) {
                            $completedSteps++;
                        }
                    }
                }
                $status = '1';
                $isCompleted = '0';
                if ($totalSteps == $completedSteps) {
                    $status = '2';
                    $isCompleted = '1';
                    $masterProcessTitle = $customerProcess->master_process->title.' Completed';
                    $notificationData = [
                        'title' => $masterProcessTitle,
                        'body' => $customerProcess->master_process->title.' has been completed by '.$customerProcess->customer->first_name .' '.$customerProcess->customer->last_name,
                        'to_user_id' =>  $customerProcess->assigned_by,
                        'from_user_id' =>  $customerProcess->user_id,
                        'url' => config('services.front_end.url').'/customers-process-list/view-process/'.$customerProcess->uuid
                    ];
                    NotificationService::create($notificationData);
                }
                $customerProcess->update([
                    'status' => $status,
                    'progress' => $completedSteps . '/' . $totalSteps,
                    'last_updated' => Carbon::now(),
                    'is_link_expired' => '0'
                ]);
                return ['customerProcess' => $customerProcess, 'lastUpdatedProcess' => $lastCompletedProcess, 'is_link_expired' => $isCompleted];
            }
            throw new Exception("No customer process found.");
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    private static function searchForUuid($uuid, $array)
    {
        foreach ($array as $key => $val) {
            if ($val['uuid'] === $uuid) {
                return $key;
            }
        }
        return null;
    }

    public static function createCustomerAnswer($data)
    {
        try {
            $customerAns = CustomerProcessAnswer::create($data);
            return $customerAns;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public static function updateCustomerAnswer($id, $data)
    {
        try {
            $customerAns = CustomerProcessAnswer::whereId($id)->update($data);
            return $customerAns;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public static function sendRemainder($customerProcessId)
    {
        try {
            $customerProcess = CustomerMasterProcess::where('uuid', $customerProcessId)->with(['master_process'])->first();
            if (empty($customerProcess)) {
                throw new Exception("No customer process found.");
            }
            $masterProcess = $customerProcess->master_process;
            if (!empty($masterProcess)) {
                $emailData = [
                    'title' => 'Form Fill Reminder',
                    'body' => 'We hope this email finds you well. This is a friendly reminder to complete the form for ' . $masterProcess->title . ' that you started on our website.',
                    'lines' => [
                        'We noticed that the form is partially filled, and we kindly request you to complete the remaining fields as soon as possible.',
                        'Please take a moment to finish the form by clicking on the following link'
                    ],
                    'action' => [
                        'title' => 'Click here',
                        'url' => $customerProcess->process_link
                    ]
                ];
                Notification::route('mail', $customerProcess->email)->notify(new EmailNotification($emailData));
            }
            return true;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public static function sendRemainders()
    {
        $past = Carbon::now()->subDays(7)->format('Y-m-d');
        $customerProcessIds = CustomerMasterProcess::where(function ($q) use ($past) {
            $q->whereDate('last_updated', $past);
            $q->where('status', '1');
        })
            ->orWhere(function ($q) use ($past) {
                $q->whereDate('created_at', $past);
                $q->where('status', '0');
            })
            ->pluck('uuid');
        if (!empty($customerProcessIds) && count($customerProcessIds) > 0) {
            foreach ($customerProcessIds as $uuid) {
                self::sendRemainder($uuid);
            }
        }
        return true;
    }

    public static function createCustomerProcessPayment($data)
    {
        try {
            $payment = CustomerProcessPayment::create($data);
            return $payment;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public static function clearFormAndNotifyUser($masterProcess)
    {
        $customerProcesses = self::getCustomerProcess(masterProcessId: $masterProcess->id);
        $customerProcessCount = $customerProcesses->count() ?? 0;
        if ($customerProcessCount > 0) {
            DB::beginTransaction();
            $totalSteps = $masterProcess->processManagements()->count();
            foreach ($customerProcesses as $customerProcess) {
                $previousStatus = $customerProcess->status;
                // $paymentSteps       = CustomerProcessAnswer::where('customer_process_id', $customerProcess->id)
                // ->whereHas('question', function($q) {
                //     $q->whereIn('module_slug', ['stripe', 'paypal']);
                // })->count();
                // Update customer process status
                $statusArray = ['1', '2'];
                if($previousStatus == '0') {
                    $customerProcess->progress = '0/'.$totalSteps;
                    $customerProcess->save();
                } else if(in_array($previousStatus, $statusArray)) {
                    $customerProcess->update([
                        'is_link_expired' => '1'
                    ]);
                    $uuid = Uuid::uuid4();
                    $customerUuid = $customerProcess->customer->uuid ?? null;
                    $processLink = env('FRONT_URL', 'https://hi-benjie.netlify.app') . '/master-process/' . Auth::user()->uuid . '/' . $customerUuid . '/' . $masterProcess->uuid . '/' . $uuid;
                    $newCustomerProcess = $customerProcess->replicate();
                    $newCustomerProcess->uuid = $uuid;
                    $newCustomerProcess->process_link = $processLink;
                    $newCustomerProcess->is_link_expired = '0';
                    $newCustomerProcess->status = '0';
                    $newCustomerProcess->progress = '0/' . $totalSteps;
                    $newCustomerProcess->last_updated = null;
                    $newCustomerProcess->push();
                    // send Notification mail to refill the master Process hence master process is updated.
                    NotificationService::sendRefillNotificationOnProcessUpdate($newCustomerProcess);
                    $customerProcess->delete();
                }
            }
            DB::commit();
        }
    }
}