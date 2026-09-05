<?php
namespace App\Http\Middleware;
use Closure; use Illuminate\Http\Request;
class WebAuth { public function handle(Request $request, Closure $next){ return $request->session()->has('user_id') ? $next($request) : response()->json(['message'=>'ابتدا وارد شوید.'],401); } }
