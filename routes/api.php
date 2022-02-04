<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

// Show Image
Route::get('image/{id}', 'ImageController@show');

// Upload Image
Route::post('image', 'ImageController@store');

// Delete Image
Route::delete('image/delete/{id}', 'ImageController@destroy');


// Get image information inside Fineuploader
Route::get('image/db/{id}', 'ImageController@getImage');