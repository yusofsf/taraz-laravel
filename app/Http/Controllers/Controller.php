<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Never call parent::destroy() from overrides — it exists only for
     * legacy routes that still point at the base controller.
     */
    public function destroy(Request $request)
    {
        $record = $request->route('product') ?? $request->route('user');
        if ($record instanceof User && ($record->is_admin || $record->id === $request->session()->get('user_id'))) {
            return response()->json(['message' => 'حذف مدیر اصلی یا کاربر فعلی مجاز نیست.'], 422);
        }
        $record->delete();

        return response()->noContent();
    }
}
