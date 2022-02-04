<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\UsersRequest;
use App\Http\Requests\UsersEditRequest;
use Illuminate\Http\Request;
use App\Models\User;
use Redirect;
use Backpack\CRUD\app\Http\Controllers\CrudController;
use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;
use Mail;

/**
 * Class UsersCrudController
 * @package App\Http\Controllers\Admin
 * @property-read \Backpack\CRUD\app\Library\CrudPanel\CrudPanel $crud
 */
class UsersCrudController extends CrudController
{
	use \Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
	use \Backpack\CRUD\app\Http\Controllers\Operations\CreateOperation;
	use \Backpack\CRUD\app\Http\Controllers\Operations\UpdateOperation;
	use \Backpack\CRUD\app\Http\Controllers\Operations\DeleteOperation;
	use \Backpack\CRUD\app\Http\Controllers\Operations\ShowOperation;
    //use \Backpack\CRUD\app\Http\Controllers\Operations\ReorderOperation;

	public function setup()
	{
		$this->crud->setModel('App\Models\User');
		$this->crud->setRoute(config('backpack.base.route_prefix') . '/users');
		$this->crud->setEntityNameStrings('Staff', 'Staff');
		$this->crud->enableExportButtons();
		$this->crud->denyAccess(['show']);
		$this->crud->custom_jquery = 'staff.js';
	}

	protected function setupListOperation()
	{

        // TODO: remove setFromDb() and manually define Columns, maybe Filters
		authorizePermissionTo('staff', $this);
		$this->crud->setFromDb();
		$this->crud->removeColumns(['password','slug','otp','profile_pic','username']);
		$this->crud->addColumns([
			[
				'label'     => 'Company',
				'type'      => 'select',
				'name'      => 'company_id',
				'entity'    => 'company',
				'attribute' => 'name',
				'model'     => "App\Models\Company",
				'attributes' => [
					'id' => 'company_id'
				]
			],
			[
				'label'     => 'Name',
				'type'      => 'text',
				'name'      => 'name',
			],
			[
				'label'     => 'Email',
				'type'      => 'text',
				'name'      => 'email',
			],
			[
				'label'     => 'Address',
				'type'      => 'textarea',
				'name'      => 'address',
			],
			[
				'label'     => 'Phone',
				'type'      => 'text',
				'name'      => 'phone',
			],
			[
				'label'     => 'Notes',
				'type'      => 'textarea',
				'name'      => 'notes',
			],
			[
				'label'     => 'City',
				'type'      => 'text',
				'name'      => 'city',
			],
			[
				'label'     => 'State',
				'type'      => 'text',
				'name'      => 'state',
			],
            /*[
                'label'     => 'Pictures',
                'type'      => 'image',
                'name'      => 'profile_pic',
            ],*/
            [
            	'label'     => 'Country',
            	'type'      => 'text',
            	'name'      => 'country',
            ],
            [
            	'label'     => 'Zip Code',
            	'type'      => 'text',
            	'name'      => 'zip_code',
            ],
            [
            	'label'     => 'PAY',
            	'type'      => 'text',
            	'name'      => 'pay',
            ],
            [
            	'label'       => "Status",
            	'name'        => 'status',
                'type'        => 'text',//select2_from_array
                /*'options'     => ['active' => 'Active', 'inactive' => 'InActive'],
                'allows_null' => false,*/
            ],
        ]);
	}

	protected function setupCreateOperation()
	{
		$this->crud->setValidation(UsersRequest::class);
		$this->crud->setFromDb();
		$this->crud->removeFields(['name','company_id','email','role','address','phone','profile_pic','notes','city','state','country','zip_code','pay','status','password','slug','otp','username']);
		$this->crud->addFields([
			[
				'label'     => 'Company',
				'type'      => 'select',
				'name'      => 'company_id',
				'entity'    => 'company',
				'attribute' => 'name',
				'model'     => "App\Models\Company",
				'attributes' => [
					'id' => 'company_id'
				]
			],
			[
				'label'     => 'Name',
				'type'      => 'text',
				'name'      => 'name',
			],
			[
				'label'     => 'Email',
				'type'      => 'text',
				'name'      => 'email',
			],
			[
				'name'      => 'role',
				'label'     => "Role",
				'type'      => 'select2',
				'model'     => config('permission.models.role'),
				'entity'    => 'roles',
				'attribute' => 'name',
				'pivot'     => true,
				'attributes' => [
					'id' => 'role_id'
				]
			],
			[
				'label'     => 'Address',
				'type'      => 'textarea',
				'name'      => 'address',
			],
			[
				'label'     => 'Phone',
				'type'      => 'text',
				'name'      => 'phone',
			],
           /* [
                'label'  => "Picture",
                'name'   => "profile_pic",
                'type'   => 'upload',
                'upload' => true,
            ],*/
            [
            	'label'  => "Picture",
            	'name'   => "profile_pic",
            	'type'   => 'image',
            	'upload' => true,
                'attributes' => [
                    'id' => 'profile_pic'
                ]
            ],
            [
            	'label'     => 'Notes',
            	'type'      => 'textarea',
            	'name'      => 'notes',
            ],
            [
            	'label'     => 'City',
            	'type'      => 'text',
            	'name'      => 'city',
            ],
            [
            	'label'     => 'State',
            	'type'      => 'text',
            	'name'      => 'state',
            ],
            [
            	'label'     => 'Country',
            	'type'      => 'text',
            	'name'      => 'country',
            ],
            [
            	'label'     => 'Zip Code',
            	'type'      => 'text',
            	'name'      => 'zip_code',
            ],
            [
            	'label'  => 'Pay',
            	'type'   => 'text',
            	'name'   => 'pay',
            	'prefix' => '$'
            ],
            [
            	'label'       => "Status",
            	'name'        => 'status',
            	'type'        => 'select2_from_array',
            	'options'     => ['active' => 'Active', 'inactive' => 'InActive'],
            	'allows_null' => false,
            ],
            
        ]);


	}


	public function store(UsersRequest $request)
	{
		$user = new User();
		$user->name        = $request->name;
		$user->company_id  = $request->company_id;
		$role              = $request->role;
		$user->username    = $request->username;
		$user->slug        = strtolower($request->name);
		$user->email       = $request->email;
		$user->address     = $request->address;
		$user->profile_pic = $request->profile_pic;
		$user->phone       = $request->phone;
		$user->notes       = $request->notes;
		$user->city        = $request->city;
		$user->state       = $request->state;
		$user->country     = $request->country;
		$user->zip_code    = $request->zip_code;
		$user->pay         = $request->pay;

		$user->save();
        $user->roles()->sync($role); // staff member

        \Alert::success(trans('backpack::crud.insert_success'))->flash();

        // save the redirect choice for next time
        $this->crud->setSaveAction();

        $this->send_mail($user->id);
        return redirect(backpack_url('users'));
        
        

    }

    protected function setupUpdateOperation()
    {
        //$this->setupCreateOperation();
    	$this->crud->setValidation(UsersEditRequest::class);
    	$this->crud->setFromDb();
    	$this->crud->removeFields(['name','company_id','email','role','address','phone','profile_pic','notes','city','state','country','zip_code','pay','status','password','slug','otp','username']);

    	if ($this->crud->getCurrentEntry()) {
    		$roleId = $this->crud->getCurrentEntry()->roles()->first()->id;
    	} else {
    		$roleId = null;
    	}

    	$this->crud->addFields([
    		[
    			'label'     => 'Company',
    			'type'      => 'select',
    			'name'      => 'company_id',
    			'entity'    => 'company',
    			'attribute' => 'name',
    			'model'     => "App\Models\Company",
    		],
    		[
    			'label'     => 'Name',
    			'type'      => 'text',
    			'name'      => 'name',
    		],
    		[
    			'name'      => 'role',
    			'label'     => "Role",
    			'type'      => 'select2',
    			'model'     => config('permission.models.role'),
    			'entity'    => 'roles',
    			'attribute' => 'name',
    			'pivot'     => true,
    			'default'   => $roleId,
    			'attributes' => [
    				'id' => 'role_id'
    			]

    		],
    		[
    			'label'     => 'Address',
    			'type'      => 'textarea',
    			'name'      => 'address',
    		],
    		[
    			'label'     => 'Phone',
    			'type'      => 'text',
    			'name'      => 'phone',
    		],
            /*[
                'label'  => "Picture",
                'name'   => "profile_pic",
                'type'   => 'upload',
                'upload' => true,
            ],*/
            [
            	'label' => "Picture",
            	'name' => "profile_pic",
            	'type' => 'image',
            	'upload' => true,
                'attributes' => [
                    'id' => 'profile_pic'
                ]
            ],
            [
            	'label'     => 'Notes',
            	'type'      => 'textarea',
            	'name'      => 'notes',
            ],
            [
            	'label'     => 'City',
            	'type'      => 'text',
            	'name'      => 'city',
            ],
            [
            	'label'     => 'State',
            	'type'      => 'text',
            	'name'      => 'state',
            ],
            [
            	'label'     => 'Country',
            	'type'      => 'text',
            	'name'      => 'country',
            ],
            [
            	'label'     => 'Zip Code',
            	'type'      => 'text',
            	'name'      => 'zip_code',
            ],
            [
            	'label'  => 'Pay',
            	'type'   => 'text',
            	'name'   => 'pay',
            	'prefix' => '$'

            ],
            [
            	'label'       => "Status",
            	'name'        => 'status',
            	'type'        => 'select2_from_array',
            	'options'     => ['active' => 'Active', 'inactive' => 'Inactive'],
            	'allows_null' => false,
            ],
            
        ], 'update');
    }

    public function send_mail($id)
    {
    	$usermail = User::where('id', $id)->first();
    	$rand = str_random(8);

    	$email = $usermail->email;

    	User::where('id', $id)->update(['password' => \Hash::make($rand) ]);
    	$user['email']    = $usermail->email;
    	$user['name']     = $usermail->name;
    	$user['password'] = $rand;

    	Mail::send('emails.mail_singup', ['user' => $user], function ($message) use ($email) {
    		$message->to($email)->subject("JCB Venture | Otp");
    	});

    	return redirect()->back()->with('message', 'IT WORKS!');
    }


    public function update(UsersEditRequest $request,$id)
    {
    	ini_set('max_execution_time', 3600);
    	$data = $request->only(['name', 'company_id', 'role', 'name', 'address', 'phone', 'notes', 'city', 'state', 'country', 'zip_code', 'pay','profile_pic','status']);
    	$user = User::find($id);
    	
    	$user->name        = $data['name'];
		$user->company_id  = $data['company_id'];
		$role              = $data['role'];
		$user->address     = $data['address'];

		if (starts_with($data['profile_pic'], 'data:image')) {
	    	$user->profile_pic = $data['profile_pic'];
	    }
        $user->phone       = $data['phone'];
		$user->notes       = $data['notes'];
		$user->city        = $data['city'];
		$user->state       = $data['state'];
		$user->country     = $data['country'];
		$user->zip_code    = $data['zip_code'];
		$user->pay         = $data['pay'];
		$user->status      = $data['status'];
		$user->save();
		$user->roles()->sync($role); // staff member
		\Alert::success(trans('backpack::crud.updates_success'))->flash();
        return redirect(backpack_url('users'));
    }


         public function destroy($id)
         {
         	$this->crud->hasAccessOrFail('delete');
         	return $this->crud->delete($id);
         }

     }
