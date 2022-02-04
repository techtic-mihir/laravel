<?php
use Laravel\Telescope\Http\Controllers\HomeController;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Carbon;
// use App\User;
// use Auth;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/




Auth::routes(['verify' => false]);
Route::get('profile', function () {
    // Only verified users may enter...
})->middleware('verified');
Route::prefix('admin')->group(function() {
    Route::get('login', 'Auth\LoginController@showAdminLoginForm');
});


Route::get('register/phone', 'Auth\RegisterController@phoneRegisterForm');
Route::get('register/{token}', 'Auth\RegisterController@hiddenRegister');
Route::get('login/otp', 'Auth\LoginController@otpForm');
Route::post('register/phone', 'Auth\RegisterController@phoneRegister');
Route::get('log', 'LogController@index');
Route::get('password/set/{token}', 'Auth\ResetPasswordController@showSetForm');
Route::post('password/set', 'Auth\ResetPasswordController@set');
// Route::prefix('admin')->group(function () {
// Users
Route::get('users', 'UserController@index');
// Profile page
Route::get('all-solutions-list', 'PublicController@all_solutions');
// Route::get('/view-solution-detail/{solution}', 'PublicController@view_solution_detail');

Route::get('company/{company}/solution/{solution}', 'PublicController@showSolution');

Route::group(['prefix' => 'admin'], function() {
    Route::get('generate-registration-link/{id}', 'InterestEntryController@generateRegistrationLink');
    Route::get('interest-entry/generate_email/{id}', 'InterestEntryController@generate_email');
    Route::post('interest-entry/send-email', 'InterestEntryController@sendEmail');
    Route::resource('interest-form-entries', 'InterestEntryController');
});

Route::get('marketplaceregister', 'HomeController@interestForm');

Route::group(['prefix' => 'admin', 'middleware' => ['role:admin']],function () {
    Route::group(['prefix' => 'solution-provider'], function() {
        Route::get('', 'Admin\SolutionProviderController@index');
        Route::get('solutions/{id}', 'Admin\SolutionProviderController@solutions');
        Route::get('members/{id}', 'Admin\SolutionProviderController@members');
        Route::post('update-status/{id}', 'Admin\SolutionProviderController@updateStatus');
        Route::post('update-published/{id}', 'Admin\SolutionProviderController@updateSolutionPublished');
        Route::post('update-user-active/{id}', 'Admin\SolutionProviderController@updateUserIsActive');
    });

    //Route::get('solution-provider/send-email/{id}', 'Admin\SolutionProviderController@sendEmail');

    Route::get('/', 'AdminController@index');
    Route::get('company', 'CompanyController@company');
    Route::get('add_company', 'CompanyController@add');
    Route::get('admin-profile', 'ProfileController@admin_profile');
    Route::get('team/create', 'UserController@adminteamCreate');
    // Users
    // Route::get('users', 'UserController@index');
    // // Profile page
    // Route::get('profile', 'ProfileController@index');
    Route::get('welcome', 'UserController@sa_login');
    Route::get('solutions', 'SolutionController@solutions');
    Route::get('all-solutions', 'SolutionController@all_solutions');
    Route::get('change-solution-status/{id}', 'SolutionController@toggleSolutionStatus');
    Route::get('company-solutions/{companies}', 'SolutionController@company_solutions');
    Route::post('company-solutions/{companies}', 'SolutionController@company_solutions');

    Route::get('companysolutions/{companies}', 'SolutionController@companysolutions');

    Route::post('view-company-solutions/{solution}', 'SolutionController@view_company_solutions');
    Route::post('delete-company-solutions/{solution}', 'SolutionController@delete_company_solutions');
    Route::post('delete-a-company-solutions/{solution}', 'SolutionController@delete_a_company_solutions');
    Route::get('all-companies', 'CompanyController@all_companies');

    Route::get('allcompanies', 'CompanyController@allcompanies_data');

    Route::get('all-members', 'UserController@all_members');
    // Route::post('/addmember','UserController@admin_add_member');
    Route::post('addmember','UserController@createbyadmin');
    Route::post('memberdelete/{team}','UserController@deletebyadmin');
    Route::get('memberedit/{team}','UserController@member_edit');
    Route::get('activemember/{id}','UserController@activemember');
    Route::post('member-update/{user}','UserController@member_update');

    Route::get('add-categories', 'AdminController@add_cat_subcat');
    Route::post('add-category', 'AdminController@add_category');
    Route::get('all-categories', 'AdminController@all_categories');
    Route::post('update-category/{category}', 'AdminController@update_category');
    Route::get('edit-category/{category}', 'AdminController@edit_category');
    Route::get('view-sub-category/{category}', 'AdminController@view_sub_category');
    Route::post('delete-category/{category}', 'AdminController@delete_category');
    Route::post('change-status-category/{category}', 'AdminController@change_status_category');
    // }
});

Route::post('profile/change-email', 'UserController@changeEmail');
/*Route::get('/role', function() {
    Role::create(['name' => 'admin']);
    Role::create(['name' => 'solution_architect']);
    Role::create(['name' => 'solution_provider']);
    Role::create(['name' => 'team_member']);

    // $user = App\User::find(1);
    // $user->assignRole('admin');
});*/

Route::get('/', 'HomeController@index');
Route::get('main', 'HomeController@home');
Route::get('home', 'HomeController@company');

//store a push subscriber.
Route::post('push','PushController@store');
Route::post('delete-subscription','PushController@deleteSubscription');

//make a push notification.
Route::get('push','PushController@push')->name('push');

Route::get("login/{provider}", 'Auth\LoginController@redirectToProvider');
Route::get("login/{provider}/callback", 'Auth\LoginController@handleProviderCallback');

Route::get('change-password', 'Auth\ChangePasswordController@index');
Route::post('change-password', 'Auth\ChangePasswordController@changePassword');
// Upload Routes
Route::post('upload', 'UploadController@upload');
Route::delete('upload/{id}', 'UploadController@delete');

Route::match(['get', 'post'], 'laravel-send-custom-email', 'EmailController@customEmail');
Route::get('timezone', function() {
    return date_default_timezone_get();
});

Route::get('phone', function() {
    $res = Nexmo::message()->send([
        'to'   => '919882431471',
        'from' => '918894407409',
        'text' => 'Hello, World!'
    ]);
    dd($res);
});

Route::get('nexmo/verify', function() {
    $verification = Nexmo::verify()->start([
        'number' => '918894407409',
        'brand'  => 'Nexmo',
         'code_length'  => '4']);

      echo "Verification id: " . $verification->getRequestId();
});

Route::get('nexmo/otp', function() {
    $request_id = '985e0b5419b5458fa8709c51c0b1b32d';
    $verification = new \Nexmo\Verify\Verification($request_id);
    $result = Nexmo::verify()->check($verification, '3870');
    dd($result);
});


// Notifications
Route::post('notifications', 'NotificationController@store');
Route::get('notifications', 'NotificationController@index');
Route::patch('notifications/{id}/read', 'NotificationController@markAsRead');
Route::post('notifications/mark-all-read', 'NotificationController@markAllRead');
Route::post('notifications/{id}/dismiss', 'NotificationController@dismiss');
// Profile
// Route::get('profile', 'UserController@profilePage');
Route::post('change-picture', 'UserController@changePicture');
Route::post('change-name', 'UserController@changeName');
Route::get('profile', 'ProfileController@index');
Route::get('solution-partial-preview/{id}', 'SolutionController@solutionPreview');
Route::post('delete-sol-byadmin/{id}', 'SolutionController@delete_sol_byadmin');

//Solution provider routes
Route::group(['middleware' => ['role:solution_provider|team_member','verified','prevent-back-history']],function () {

    Route::get('home', 'CompanyController@company');

    Route::get('team/create', 'UserController@teamCreate');

    Route::get('company','CompanyController@add');
    Route::post('create-company','CompanyController@create');

    Route::get('company/{company}','CompanyController@edit');
    Route::post('company/{company}','CompanyController@update');

    Route::get('all-solutions', 'SolutionController@index',function () {
        // Only verified users may enter...
    })->middleware('verified');

    // Route::get('/view-solution/{solution}', 'SolutionController@view');
    Route::get('guide-solution','SolutionController@guide');
    Route::get('solution','SolutionController@add');
    Route::post('solution','SolutionController@create');


    Route::get('solution-preview/{id}','SolutionController@preview');
    Route::get('solution-edit/{solution}','SolutionController@edit_solution');
    Route::post('edit-solution-update/{solution}','SolutionController@edit_solution_update');
    Route::post('solution-publish/{solution}','SolutionController@publish');
    Route::get('solution-published/{solution}','SolutionController@published');


    Route::get('solution/{solution}','SolutionController@edit')->where('solution', '[0-9]+');
    Route::post('solutionCopy/{solution}','SolutionController@copy');
    Route::post('solution/{solution}','SolutionController@update');

    Route::get('findSubcategory','SolutionController@findSubcategory');

    Route::get('allteam', 'UserController@team');
    Route::post('addmember','UserController@create');
    Route::post('memberdelete/{team}','UserController@delete');

    Route::get('sp/teammembers', 'UserController@teammembers');
    Route::get('send/email', 'HomeController@mail');

    Route::post('change-email-request/{id}', 'UserController@changeEmailRequest');
    Route::get('change-email-request/{id}', 'UserController@changeEmailRequestView');
    Route::get('solution/{solution_slug}', 'SolutionController@view');
//Super admin Routes
});


Route::get('mail-us', 'HomeController@email_us');
Route::post('mail', 'HomeController@usermail');
Route::get('faq', 'HomeController@faq');


Route::get('installer', function() {
    /**
     * Creating all roles
     */
    // Role::create(['name' => 'admin']);
    // Role::create(['name' => 'solution_architect']);
    // Role::create(['name' => 'solution_provider']);
    // Role::create(['name' => 'team_member']);


    // $user = App\User::find(33);
    // $user->assignRole('admin');
    // $user->removeRole('admin');

    // $user = App\User::find(26);

    // $user->assignRole('solution_provider');
});
