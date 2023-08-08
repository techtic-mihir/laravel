<?php

namespace App\Api\Controllers;

use App\Api\Requests\CustomerRequest;
use App\Exports\ExportCustomer;
use App\Http\Controllers\Controller;
// use App\Models\Customer;
use App\Models\User;
use App\Services\CommonService;
use App\Services\CustomerService;
use App\Services\HubspotService;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use \Mpdf\Mpdf as PDF;

class CustomerController extends Controller
{
    protected $loginUserRole;
    protected $loginUserId;
    protected $client, $hubspot;

    public function __construct(
        HubspotService $hubspot
    )
    {
        $this->hubspot = $hubspot;
        $this->middleware(function ($request, $next) {
            $this->loginUserRole = Auth::user()->roles()->first(); // get login user role.
            $this->loginUserId = Auth::user()->id;
            $this->client = new Client(['base_uri' => HUBSPOT_BASE_URL]);
            return $next($request);
        });
    }

    /**
     * update Customer By Id
     *
     * @return Json response
     */
    public function createCustomers(CustomerRequest $request)
    {
        try {
            DB::beginTransaction();
            $data = $request->all();
            $validcount = Auth::user()->subscriptions()->where('stripe_status', 'active')->value('number_of_users');
            $count = User::where('parent_id', Auth::user()->id)->whereHas("roles", function ($q) {
                $q->where("id", '=', CUSTOMER_ROLE);
            })->count();
            if($count < $validcount) {
                // Store Customer on hubspot side
                $saveCustomer = CustomerService::createCustomer($data);
                $hubSpotToken = Auth::user()->integrationSettings()->where('integration_type', 'hubspot')->first();
                if(!empty($hubSpotToken)) {
                    $hubData = [
                        'properties' => [
                            'firstname' => $saveCustomer->first_name,
                            'lastname' => $saveCustomer->last_name,
                            'email' => $saveCustomer->email,
                            'phone' => $saveCustomer->phone_no,
                            'company' => Auth::user()->name,
                            'website' => null,
                            'lifecyclestage' => null
                        ]
                    ];
                    $this->hubspot->setToken($hubSpotToken->access_token, $hubSpotToken->refresh_token);
                    $hubData = $this->hubspot->createContact($hubData);
                    // \Log::info('hubdata');
                    // \Log::info(print_r($hubData));
                    // $saveCustomer->update(['hubspot_contact_id' => $hubData->id]);
                }
                DB::commit();
                return response()->json(['status_code' => 200, 'message' => 'Customer created successfully.','data' => $saveCustomer], 200);
                // return response()->json(['status_code' => 500, 'message' => "Customer creation failed. since we couldn't find a HubSpot integration."], 500);
            }
            DB::rollBack();
            return response()->json(['status_code' => 500, 'message' => 'Customer Create limit Exceeded!'], 500);
        } catch (Exception $th) {
            DB::rollback();
            return response()->json(['status_code' => 500, 'message' => $th->getMessage()], 500);
        }
    }



    /**
     * update Customers By Id
     *
     * @param  mixed $request
     * @param  string $uuid
     * @return void
     */
    public function updateCustomersById(CustomerRequest $request, $uuid)
    {
        try {
            $allRequestKeyParm = $request->all();
            $customerData = CustomerService::updateCustomer($uuid, $allRequestKeyParm);
            return response()->json(['status_code' => 200, 'data' => $customerData, 'message' => 'Customer Data updated successfully'], 200);
        } catch (\Throwable $th) {
            return response()->json(['status_code' => 500, 'message' => $th->getMessage()], 500);
        }
    }



    /**
     * get Customers By Id
     *
     * @param  string $uuid
     * @return json response
     */
    public function getCustomersById($uuid)
    {
        try {
            $customerData = CustomerService::getCustomerByUuid($uuid, with: ['customerDetail', 'company']);
            return response()->json(['status_code' => 200, 'message' => 'success','data' => ['customerData' => $customerData]], 200);
        } catch (\Throwable $th) {
            return response()->json(['status_code' => 500, 'message' => $th->getMessage()], 500);
        }
    }


    /**
     * delete Customers By Id
     *
     * @param  string $uuid
     * @return void
     */
    public function deleteCustomersById($uuid)
    {
        try {
            DB::beginTransaction();
            $customer = CustomerService::getCustomerByUuid($uuid);
            $customer->customerDetail()->delete();
            $customer->delete();
            DB::commit();
            return response()->json(['status_code' => 200, 'message' => 'Customer deleted successfully.'], 200);
        } catch (\Throwable $th) {
            DB::rollBack();
            return response()->json(['status_code' => 500, 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * show Customer Profile by id
     *
     * @param  string $uuid
     * @return json response
     */
    public function showCustomerProfile($uuid)
    {
        try {
            $customerData  = CustomerService::getCustomerByUuid($uuid, with:['customerDetail']);
            return response()->json(['status_code' => 200, 'message' => 'success', 'data' => $customerData], 200);
        } catch (\Throwable $th) {
            return response()->json(['status_code' => 500, 'message' => $th->getMessage()], 500);
        }
    }



    /**
     * get All Customer Of User
     *
     * @param  string $companyUuid
     * @return json response
     */
    public function getAllCustomerOfCompany()
    {
        try {
            $search      = (!empty($_REQUEST['search'])) ? $_REQUEST['search'] : '';
            $sortTitle   = (isset($_REQUEST['sort']) && $_REQUEST['sort']) ? $_REQUEST['sort'] : 'id';
            $orderBy     = (isset($_REQUEST['order']) && $_REQUEST['order']) ? $_REQUEST['order'] : 'DESC';
            $companyUuid = (isset($_REQUEST['company_uuid']) && $_REQUEST['company_uuid']) ? $_REQUEST['company_uuid'] : '';
            $currentRole = CommonService::getCurrentRole();
            if (!empty($currentRole) && ($currentRole->id == SUPER_ADMIN_ROLE || $currentRole->id == SUB_ADMIN_ROLE)) { // if login user is super admin then get all customer data.
                $company = User::where("uuid", $companyUuid)->first(); // get user data according to id.
            } else if(!empty($currentRole) && ($currentRole->id == TEAM_ROLE)) {
                $company = User::whereId(Auth::user()->parent_id)->first(); // get user data according to id.
            } else {
                $company = Auth::user(); // get user data according to id.
            }
            $customerData = CustomerService::getCompanyCustomers($search, $sortTitle, $orderBy, null, 10, $companyUuid);
            return response()->json(['status_code' => 200, 'message' => 'success', 'data' => ['userCustomersData' => $customerData, 'userData' => $company]], 200);
        } catch (\Throwable $th) {
            return response()->json(['status_code' => 500, 'message' => $th->getMessage()], 500);
        }
    }


    /**
     * active Or Inactive Customer By Id
     *
     * @param  mixed $request
     * @param  integer $id
     * @return json Response
     */
    public function activeOrInactiveCustomerById(Request $request, $uuid)
    {
        try {
            $message = ($request->status == '0') ? "Customer Is Activated" : "Customer Is In-activated";
            $customer = CustomerService::getCustomerByUuid($uuid);
            $customer->status = $request->status;
            $customer->save();
            return response()->json(['status_code' => 200, 'message' => $message, 'data' => $customer], 200);
        } catch (\Throwable $th) {
            return response()->json(['status_code' => 500, 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * customerExportCsv
     *
     * @return json Response
     */
    public function customerExportCsv()
    {
        try {
            $companyUuids = (!empty($_REQUEST['company_uuid'])) ? $_REQUEST['company_uuid'] : "";
            $search      = (!empty($_REQUEST['search'])) ? $_REQUEST['search'] : '';
            $sortTitle   = (isset($_REQUEST['sort']) && $_REQUEST['sort']) ? $_REQUEST['sort'] : 'id';
            $orderBy     = (isset($_REQUEST['order']) && $_REQUEST['order']) ? $_REQUEST['order'] : 'DESC';

            /*============= store subscription plan data csv file in aws s3 buckets-start===========*/
            $fileName =  'Customer-' . time() . '.csv';
            Excel::store(new ExportCustomer($search, $sortTitle, $orderBy, $companyUuids, $this->loginUserRole, $this->loginUserId), 'export/customer/' . $fileName, 's3');
            $filePath = \Storage::disk('s3')->url('export/customer/' . $fileName);
            /*============= store subscription plan csv file in aws s3 buckets-end===========*/

            return response()->json(['status_code' => 200, 'filePath' => $filePath], 200);
        } catch (\Throwable $th) {
            return response()->json(['status_code' => 500, 'message' => $th->getMessage()], 500);
        }
    }


    /**
     * get All Deleted Conmpay Customer
     *
     * @return json Response
     */
    public function getAllDeletedCompanyCustomer()
    {
        try {
            $search         = (!empty($_REQUEST['search'])) ? $_REQUEST['search'] : '';
            $sortTitle      = (isset($_REQUEST['sort']) && $_REQUEST['sort']) ? $_REQUEST['sort'] : 'id';
            $orderBy        = (isset($_REQUEST['order']) && $_REQUEST['order']) ? $_REQUEST['order'] : 'DESC';
            $companyUuid    = (isset($_REQUEST['company_uuid']) && $_REQUEST['company_uuid']) ? $_REQUEST['company_uuid'] : '';
            $status         = null;
            $currentRole = CommonService::getCurrentRole();
            if (!empty($currentRole) && ($currentRole->id == SUPER_ADMIN_ROLE || $currentRole->id == SUB_ADMIN_ROLE)) { // if login user is super admin then get all customer data.
                $company = User::where("uuid", $companyUuid)->first(); // get user data according to id.
            } else if(!empty($currentRole) && ($currentRole->id == TEAM_ROLE)) {
                $company = User::whereId(Auth::user()->parent_id)->first(); // get user data according to id.
            } else {
                $company = Auth::user(); // get user data according to id.
            }
            $customerData = CustomerService::getCompanyCustomers($search, $sortTitle, $orderBy, $status, 10, $companyUuid, true);
            return response()->json(['status_code' => 200, 'message' => 'success', 'data' => ['userCustomersData' => $customerData, 'userData' => $company]], 200);
        } catch (Exception $th) {
            return response()->json(['status_code' => 500, 'message' => $th->getMessage()], 500);
        }
    }

    
    /**
     * add New HubSpot Property
     *
     * @param  string $key
     * @param  string $accessToken
     * @return boolean response
     */
    public function addNewHubSpotProperty($key, $accessToken)
    {
        try {
            $data = [
                'name' => $key,
                'label' => $key,
                'type' => 'string',
                'fieldType' => 'text',
                'groupName' => 'contactinformation',
                'hidden' => 'false',
                'displayOrder' => '1',
                'hasUniqueValue' => 'false',
                'formField' => 'false'
            ];

            $path = 'crm/' . HUBSPOT_API_VERSION . '/properties/Contacts';
            $defaultOptions = [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'authorization' => 'Bearer ' . $accessToken . ''
                ],
                'body' => json_encode($data),
            ];
            $this->client->request('POST', $path, $defaultOptions);
            return true;
        } catch (\Throwable $th) {
            return response()->json(['status_code' => 500, 'message' => $th->getMessage()], 500);
        }
    }


    /**
     * restore Deleted Customer
     *
     * @param  string $uuid
     * @return json response
     */
    public function restoreDeletedCustomer($uuid)
    {
        try {
            $customer = CustomerService::restoreCustomer($uuid);
            return response()->json(['status_code' => 200, 'message' => 'Customer restored successfully.', 'data' => $customer], 200);
        } catch (\Throwable $th) {
            return response()->json(['status_code' => 500, 'message' => $th->getMessage()], 500);
        }
    }

    /* get All HubSpot Contact Properties
     *
     * @param  string $accessToken
     * @return array Response
     */
    public function getAllHubSpotContactProperties($accessToken)
    {
        try {
            $path = 'crm/' . HUBSPOT_API_VERSION . '/properties/contacts/';
            $defaultOptions = [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'authorization' => 'Bearer ' . $accessToken . ''
                ],
            ];
            $response =  $this->client->request('GET', $path, $defaultOptions);
            $response = json_decode($response->getBody()->getContents());
            $getAllProperties = array_column($response->results, 'name'); // get all properties name from hubspot.
            return $getAllProperties;
        } catch (\Throwable $th) {
            return response()->json(['status_code' => 500, 'message' => $th->getMessage()], 500);
        }
    }



    /**
     * update Contact Details
     *
     * @param  array $data
     * @param  int $hubSpotContactId
     * @param  string $accessToken
     * @return void
     */
    public function updateContactDetails($data, $hubSpotContactId, $accessToken)
    {
        try {
            $path = 'crm/' . HUBSPOT_API_VERSION . '/objects/contacts/' . $hubSpotContactId . '';
            $defaultOptions = [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'authorization' => 'Bearer ' . $accessToken . ''
                ],
                'body' => json_encode($data),
            ];
            $this->client->request('PATCH', $path, $defaultOptions);
            return true;
        } catch (\Throwable $th) {
            return response()->json(['status_code' => 500, 'message' => $th->getMessage()], 500);
        }
    }

    /**
     * Export Customer List PDF
     *
     * @param  array $data
     * @param  int $hubSpotContactId
     * @param  string $accessToken
     * @return void
     */
    public function customerExportPdf() 
    {
        try {
            $customers = CustomerService::getCompanyCustomers();
            $documentFileName = 'customers-'.time().'-'.date('d-m-Y').'.pdf';
            // $stylesheet = file_get_contents(asset('pdf/bootstrap.css'));
            $document = new PDF([
                'tempDir' => env('USE_LAMBDA_STORAGE', true) ? "/tmp" : public_path('assets/temp'),
                'mode' => 'utf-8',
                'format' => 'A4',
                'margin_top' => 8,
                'margin_left' => 8,
                'margin_right' => 8,
                'margin_bottom' => 8,
            ]);
            $html = view('pdf.customer', compact('customers'))->render();
            $document->WriteHTML($html);
            Storage::disk('s3')->put('exports/pdf/'.$documentFileName, $document->Output($documentFileName, "S"));
            $filePath = Storage::disk('s3')->url('exports/pdf/' . $documentFileName);
            return response()->json(['status_code' => 200, 'message' => 'Customer exported successfully.', 'data' => array('url' => $filePath)], 200);
        } catch(Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }

    
    /**
     * Get Company Customers
     *
     * @param  array $data
     * @return void
     */
    public function getAllCompanyCustomers() 
    {
        try {
            $customers = CustomerService::getCompanyCustomers(null, 'id', 'desc', '0');
            return response()->json(['status_code' => 200, 'message' => 'success', 'data' => $customers], 200);
        } catch(Exception $e) {
            return response()->json(['status_code' => 500, 'message' => $e->getMessage()], 500);
        }
    }
}
