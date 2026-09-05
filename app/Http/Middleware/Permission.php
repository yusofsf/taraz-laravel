<?php
namespace App\Http\Middleware;
use App\Models\User; use Closure; use Illuminate\Http\Request;
class Permission { public function handle(Request $request, Closure $next, string $permission){ $u=User::find($request->session()->get('user_id')); return ($u && ($u->is_admin || $u->{$permission})) ? $next($request) : response()->json(['message'=>'دسترسی ندارید.'],403); } }
