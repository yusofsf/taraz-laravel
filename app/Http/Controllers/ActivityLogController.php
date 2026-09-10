<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Jalali;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ActivityLog::with('user:id,name')->latest();

        foreach (['from' => '>=', 'to' => '<='] as $field => $operator) {
            try {
                $gregorian = Jalali::parseJalaliInput($request->input($field));
            } catch (\InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }
            if ($gregorian !== null) {
                $query->whereDate('created_at', $operator, $gregorian);
            }
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }
        if ($request->filled('action')) {
            $query->where('action', (string) $request->input('action'));
        }
        if ($request->filled('target_type')) {
            $query->where('target_type', (string) $request->input('target_type'));
        }

        return response()->json($query->paginate(20));
    }

    /**
     * Filter options: users that have logs plus the distinct actions/targets.
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'users' => User::whereIn('id', ActivityLog::select('user_id'))->orderBy('name')->get(['id', 'name']),
            'actions' => array_values(array_unique(ActivityLog::query()->pluck('action')->all())),
            'target_types' => array_values(array_unique(ActivityLog::query()->whereNotNull('target_type')->pluck('target_type')->all())),
        ]);
    }
}
