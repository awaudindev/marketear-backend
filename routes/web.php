<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Mail\Markdown;
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

Route::get('/', function () {
    return view('welcome');
});
Route::get('mail', function () {
    
    return view('welcome');
    // $markdown = new Markdown(view(), config('mail.markdown'));

    // return $markdown->render('email.email-otp');
});