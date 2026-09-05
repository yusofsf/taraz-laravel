<?php

namespace App\Http\Controllers;

abstract class Controller
{
    public function destroy(\Illuminate\Http\Request $request)
    {
        $record = $request->route('product') ?? $request->route('user');
        if ($record instanceof \App\Models\User && ($record->is_admin || $record->id === $request->session()->get('user_id'))) {
            return response()->json(['message' => 'حذف مدیر اصلی یا کاربر فعلی مجاز نیست.'], 422);
        }
        $record->delete();
        return response()->noContent();
    }
}
