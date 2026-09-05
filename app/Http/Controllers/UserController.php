<?php
namespace App\Http\Controllers;
use App\Models\User; use Illuminate\Http\Request; use Illuminate\Support\Facades\Hash;
class UserController extends Controller { public function index(){return User::select('id','name','mobile','is_admin','can_add_users','can_add_products','can_edit_products','can_change_balance','can_manage_permissions')->latest()->get();} public function store(Request $r){$d=$r->validate(['name'=>'required','mobile'=>'required|regex:/^09[0-9]{9}$/|unique:users','can_add_users'=>'boolean','can_add_products'=>'boolean','can_edit_products'=>'boolean','can_change_balance'=>'boolean','can_manage_permissions'=>'boolean']);$d['password']=Hash::make('123456789');return User::create($d);} }
