<?php
namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerMasterProcess;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CustomerService
{
    public static function getCompanyCustomers($search = null, $sort = 'id', $orderBy = 'DESC', $status = null, $limit = null, $companyId = null, $trashedOnly = null) {
        try { 
            $currentRole = CommonService::getCurrentRole();
            if (!empty($currentRole) && ($currentRole->id == SUPER_ADMIN_ROLE || $currentRole->id == SUB_ADMIN_ROLE)) { // if login user is super admin then get all customer data.
                $company = User::where("uuid", $companyId)->first(); // get user data according to id.
                $disabled = ['0', '1'];
            } else if(!empty($currentRole) && ($currentRole->id == TEAM_ROLE)) {
                $company = User::whereId(Auth::user()->parent_id)->first(); // get user data according to id.
                $disabled = ['0'];
            } else {
                $company = User::find(Auth::user()->id); // get user data according to id.
                $disabled = ['0'];
            }
            
            if(!empty($company)) {

                $customers = User::with(['customerDetail', 'company' => function ($q) {
                    $q->select('id', 'first_name', 'last_name');
                }])
                ->whereHas("roles", function ($q) {
                    $q->where("id", '=', CUSTOMER_ROLE);
                })
                ->whereIn('is_disabled', $disabled)
                ->where('parent_id', $company->id);

                $childColumns = ['registration_date'];
                
                if (in_array($sort, $childColumns)) {
                    $customers = $customers->orderBy(function ($query) use ($sort, $orderBy) {
                        $query->select($sort)
                            ->from('customer_details')
                            ->whereColumn('users.id', 'customer_details.user_id')
                            ->orderBy($sort, $orderBy);
                    }, $orderBy);
                } else {
                    $customers = $customers->orderBy($sort, $orderBy);
                }
                
                if(isset($status)) {
                    $customers = $customers->where("status", $status);
                }

                if(!empty($search)) {
                    $customers = $customers->where(function ($q) use ($search) {
                        $status         = null;
                        $customerType   = null;
                        /*==== search active or inactive customer - start====*/
                        if (trim(strtolower($search)) == "active" || trim(strtolower($search)) == "inactive") {
                            $status = (trim(strtolower($search)) == "active") ? '0' : '1';
                        }
                        /*==== search active or inactive customer - end====*/
                        /*==== search customer or team customer - start====*/
                        if (trim(strtolower($search)) == "customer" || trim(strtolower($search)) == "team") {
                            $customerType = (trim(strtolower($search)) == "customer") ? '0' : '1';
                        }
                        /*==== search active or inactive customer - end====*/
                        $q->where("first_name", 'like', '%' . trim($search) . '%')
                            ->orWhere("last_name", 'like', '%' . trim($search) . '%')
                            ->orWhere("email", 'like', '%' . trim($search) . '%')
                            ->orWhere("phone_no", 'like', '%' . trim($search) . '%')
                            ->orWhere("status", $status)
                            ->orWhereHas('customerDetail', function($q) use($search, $customerType){
                                $q->where("registration_date", 'like', '%' . date("Y-m-d", strtotime($search)) . '%')
                                ->orWhere("city", 'like', '%' . trim($search) . '%')
                                ->orWhere("state", 'like', '%' . trim($search) . '%')
                                ->orWhere("country", 'like', '%' . trim($search) . '%')
                                ->orWhere("uuid", 'like', '%' . trim($search) . '%')
                                ->orWhere("address", 'like', '%' . trim($search) . '%')
                                ->orWhere("zip_code", 'like', '%' . trim($search) . '%')
                                ->orWhere("customer_type", $customerType);
                            });
                            
                    });
                }

                if(!empty($trashedOnly)) {
                    $customers->onlyTrashed();
                }

                if(!empty($limit)) {
                    return $customers->paginate($limit);
                }

                return $customers->get();
            }
            throw new Exception('No company found!');
        } catch(Exception $e) {
            throw $e;
        }
    }  

    public static function getCustomerByUuid($uuid, $companyId = null, $with = null, $onlyTrashed = null, $withTrashed = null) {
        $customer = User::where('uuid', $uuid);
        if(!empty($with)) {
            $customer = $customer->with($with);
        }
        if(!empty($companyId)) {
            $customer = $customer->where('parent_id', $companyId);
        }
        if(!empty($onlyTrashed)) {
            $customer = $customer->onlyTrashed();     
        }
        if(!empty($withTrashed)) {
            $customer = $customer->withTrashed();     
        }
        $customer = $customer->first();
        if(empty($customer)) {
            throw new Exception('No customer found!');
        }
        return $customer;
    }

    public static function createCustomer($customerData) {
        try {
            // Store Customer
            $data = [
                'parent_id' => $customerData['parent_id'],
                'first_name' => $customerData['first_name'],
                'last_name' => $customerData['last_name'],
                'email' => $customerData['email'],
                'phone_no' => $customerData['phone_no'],
                'current_positions' => $customerData['position'],
                'password' => Hash::make($customerData['password']),
                'hubspot_contact_id' => $customerData['hubspot_contact_id'] ?? null,
                'is_disabled' => $customerData['is_disabled'],
                'status'    => $customerData['status'],
            ];
            // Customer Extra details
            $details = [
                'city' => $customerData['city'],
                'state' => $customerData['state'],
                'address' => $customerData['address'],
                'country' => $customerData['country'],
                'zip_code' => $customerData['zip_code'],
                'registration_date' => date("Y-m-d", strtotime($customerData['registration_date'])),
                'customer_type' => $customerData['customer_type']
            ];
            // Create a new team member under the company
            $customer = User::create($data);
            $customer->roles()->sync(CUSTOMER_ROLE);
            // Store Details
            $customer->customerDetail()->create($details);
            // Send Registration Details Mail
            NotificationService::sendRegistrationDetailMail($customer, $customerData);
            // Send email verification Mail
            CommonService::sendVerificationMail($customer);
            return $customer;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }
    
    public static function updateCustomer($uuid, $customerData) {
        try {
            // Get Customer If exist
            $customer = self::getCustomerByUuid($uuid, with: ['customerDetail'], withTrashed: true);
            // Store Customer
            $data = [
                'first_name' => $customerData['first_name'] ?? $customer->first_name,
                'last_name' => $customerData['last_name'] ?? $customer->last_name,
                'phone_no' => $customerData['phone_no'] ?? $customer->phone_no,
                'current_positions' => $customerData['position'] ?? $customer->current_positions,
                'hubspot_contact_id' => $customerData['hubspot_contact_id'] ?? null,
                'is_disabled' => $customerData['is_disabled'] ?? $customer->is_disabled,
                'status'   => $customerData['status'] ?? $customer->status
            ];
            $customerDetail = $customer->customerDetail;
            // Customer Extra details
            $regDate = $customerData['registration_date'] ?? $customer->registration_date;
            $details = [
                'city' => $customerData['city'] ?? $customerDetail->city,
                'state' => $customerData['state'] ?? $customerDetail->state,
                'address' => $customerData['address'] ?? $customerDetail->address,
                'country' => $customerData['country'] ?? $customerDetail->country,
                'zip_code' => $customerData['zip_code'] ?? $customerDetail->zip_code,
                'registration_date' => date("Y-m-d", strtotime($regDate)),
                'customer_type' => $customerData['customer_type']
            ];
            // Store Primary Details
            $customer->update($data);
            // Store Details
            $customer->customerDetail()->update($details);
            return $customer;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    public static function restoreCustomer($uuid) {
        try {
            DB::beginTransaction();
            $customer = self::getCustomerByUuid($uuid, onlyTrashed: true);
            $customer->customerDetail()->restore();
            $customer->restore();
            DB::commit();
            return $customer;
        } catch (Exception $e) {
            DB::rollback();
            throw new Exception($e->getMessage());
        }
    }

    public static function storeHubspotContactsToCompany($hubspotContacts, $company, $limit = 0) {
        try {
            $limitCount             = 0; 
            $disabledCustomers      = []; 
            DB::beginTransaction();
            if(count($hubspotContacts) > 0) {
                foreach($hubspotContacts as $contact) {
                    $customer                   =  self::getCustomerByHubspotId($contact->id, $company->id);
                    $customerData = [
                        'parent_id'             =>  $company->id,
                        'hubspot_contact_id'    =>  $contact->id,
                        'first_name'            =>  $contact->properties->firstname,
                        'last_name'             =>  $contact->properties->lastname,
                        'email'                 =>  $contact->properties->email,
                        'phone_no'              =>  null,
                        'position'              =>  null,
                        'city'                  =>  null,
                        'state'                 =>  null,
                        'address'               =>  null,
                        'country'               =>  null,
                        'zip_code'              =>  null,
                        'password'              =>  Str::random(8),
                        'customer_type'         =>  '0',
                        'registration_date'     =>  $contact->createdAt,
                    ];
                    // Disable Customer According to Plan Limit
                    if($limit > $limitCount) {
                        $customerData['is_disabled'] = '0';
                        // Status 1 == active
                        $customerData['status']      = '0';
                    } else {
                        // Status 1 == Inactive
                        $customerData['status']      = '1';   
                        $customerData['is_disabled'] = '1';
                    }
                    if(empty($customer)) {
                        $customer = CustomerService::createCustomer($customerData);
                    } else {
                        $customer = CustomerService::updateCustomer($customer->uuid, $customerData);
                    }
                    if($customer->is_disabled == '1') {
                        array_push($disabledCustomers, $customer->id);
                    }
                    $limitCount++;
                }
                self::bulkDeleteCustomerProcesses($disabledCustomers, $company->id);
            }
            DB::commit();
        } catch (Exception $e) {
            DB::rollback();
            throw new Exception($e->getMessage());
        }
    }

    public static function getCustomerByHubspotId($hubspotId, $companyId) {
        $customer = User::where('hubspot_contact_id', $hubspotId)->where('parent_id', $companyId)->withTrashed()->first();
        return $customer;
    }

    public static function bulkDeleteCustomerProcesses(Array $customerIds, $companyId) {
        $userIds = User::where('parent_id', $companyId)->whereHas('roles', function($q) {
            $q->where('id', CUSTOMER_ROLE);
        })->whereNotIn('id', $customerIds)->pluck('id')->toArray();
        //Bulk Delete Customer Processes
        CustomerMasterProcess::whereIn('user_id', $userIds)->delete();
    }
}