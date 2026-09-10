<?php

namespace App\Models;

use App\Support\Jalali;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سایت هیچ لاگی را پاک نمی‌کند؛ تاریخچه سامانه باید همیشه کامل بماند.
 */
class ActivityLog extends Model
{
    public const ACTION_LOGIN = 'login';

    public const ACTION_LOGIN_FAILED = 'login_failed';

    public const ACTION_LOGOUT = 'logout';

    public const ACTION_PRODUCT_CREATE = 'product_create';

    public const ACTION_PRODUCT_UPDATE = 'product_update';

    public const ACTION_PRODUCT_DELETE = 'product_delete';

    public const ACTION_TRADE = 'trade';

    public const ACTION_ADJUST = 'adjust';

    public const ACTION_HISTORY_UPDATE = 'history_update';

    public const ACTION_HISTORY_DELETE = 'history_delete';

    public const ACTION_PERSON_CREATE = 'person_create';

    public const ACTION_PERSON_UPDATE = 'person_update';

    public const ACTION_PERSON_DELETE = 'person_delete';

    public const ACTION_USER_CREATE = 'user_create';

    public const ACTION_USER_UPDATE = 'user_update';

    public const ACTION_USER_DELETE = 'user_delete';

    public const ACTION_PERMISSION_UPDATE = 'permission_update';

    public const ACTION_PROFILE_UPDATE = 'profile_update';

    /** @var array<string, string> */
    public const ACTION_LABELS = [
        self::ACTION_LOGIN => 'ورود',
        self::ACTION_LOGIN_FAILED => 'ورود ناموفق',
        self::ACTION_LOGOUT => 'خروج',
        self::ACTION_PRODUCT_CREATE => 'افزودن کالا',
        self::ACTION_PRODUCT_UPDATE => 'ویرایش کالا',
        self::ACTION_PRODUCT_DELETE => 'حذف کالا',
        self::ACTION_TRADE => 'معامله',
        self::ACTION_ADJUST => 'تعدیل تراز',
        self::ACTION_HISTORY_UPDATE => 'ویرایش تاریخچه',
        self::ACTION_HISTORY_DELETE => 'حذف تاریخچه',
        self::ACTION_PERSON_CREATE => 'افزودن شخص',
        self::ACTION_PERSON_UPDATE => 'ویرایش شخص',
        self::ACTION_PERSON_DELETE => 'حذف شخص',
        self::ACTION_USER_CREATE => 'افزودن کاربر',
        self::ACTION_USER_UPDATE => 'ویرایش کاربر',
        self::ACTION_USER_DELETE => 'حذف کاربر',
        self::ACTION_PERMISSION_UPDATE => 'تغییر دسترسی',
        self::ACTION_PROFILE_UPDATE => 'ویرایش مشخصات',
    ];

    protected $fillable = [
        'user_id', 'action', 'target_type', 'target_id',
        'summary', 'details', 'ip_address',
    ];

    protected $appends = ['created_at_jalali', 'action_label'];

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getCreatedAtJalaliAttribute(): ?string
    {
        return Jalali::format($this->created_at);
    }

    public function getActionLabelAttribute(): string
    {
        return self::ACTION_LABELS[$this->action] ?? $this->action;
    }
}
