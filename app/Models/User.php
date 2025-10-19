<?php

namespace App\Models;

use Carbon\Carbon;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * مدل کاربر برای لاگین پنل ادمین (Filament)
 * - دسترسی پنل: canAccessPanel بر اساس is_active و is_admin
 * - اپندهای سبک: full_name, avatar_url, is_verified (بدون کوئری‌های سنگین)
 * - بقیه اپندها/روابط بعد از آماده‌شدن جداول دوباره برمی‌گردند
 */
class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable;

    // ===== Fillable / Hidden / Casts =====
    protected $fillable = [
        'name', 'email', 'password',
        'avatar', 'phone', 'timezone', 'language',
        'is_active', 'is_admin',
        'last_login_at', 'login_count',
        'preferences', 'api_settings', 'notification_settings',
        'trading_experience', 'risk_tolerance',
        'max_concurrent_bots', 'max_total_capital',
        'email_verified_at', 'phone_verified_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at'   => 'datetime',
        'phone_verified_at'   => 'datetime',
        'last_login_at'       => 'datetime',
        'password'            => 'hashed',
        'is_active'           => 'boolean',
        'is_admin'            => 'boolean',
        'login_count'         => 'integer',
        'max_concurrent_bots' => 'integer',
        'max_total_capital'   => 'decimal:2',
        'preferences'         => 'array',
        'api_settings'        => 'array',
        'notification_settings' => 'array',
    ];

    /**
     * ⚠️ موقت: فقط اپندهای سبک تا وقتی تمام جداول/روابط آماده شوند.
     * وقتی آماده بود: total_bots, active_bots, total_profit, ... را برگردان.
     */
    protected $appends = ['full_name', 'avatar_url', 'is_verified'];

    // ===== Scopes سبک =====
    public function scopeActive(Builder $q): Builder { return $q->where('is_active', true); }
    public function scopeAdmin(Builder $q): Builder  { return $q->where('is_admin', true); }
    public function scopeVerified(Builder $q): Builder { return $q->whereNotNull('email_verified_at'); }

    // ===== Accessors سبک (بدون کوئری اضافه) =====
    public function getFullNameAttribute(): string
    {
        return (string) ($this->name ?? '');
    }

    public function getAvatarUrlAttribute(): string
    {
        if (!empty($this->avatar)) {
            return asset('storage/avatars/'.$this->avatar);
        }
        $hash = md5(strtolower(trim((string) $this->email)));
        return "https://www.gravatar.com/avatar/{$hash}?d=identicon&s=200";
    }

    public function getIsVerifiedAttribute(): bool
    {
        return $this->email_verified_at !== null;
    }

    // ===== Filament access (رفع 403) =====
    public function canAccessPanel(Panel $panel): bool
    {
        // اگر می‌خواهی موقتاً همه وارد شوند، این را به return true تغییر بده.
        return (bool) ($this->is_active ?? false) && (bool) ($this->is_admin ?? false);
    }

    // ===== رویدادهای مدل =====
    protected static function booted(): void
    {
        static::creating(function (User $u) {
            $u->preferences            = $u->preferences            ?? [
                'theme' => 'light',
                'language' => 'fa',
                'timezone' => 'Asia/Tehran',
                'currency_display' => 'toman',
                'decimal_places' => 0,
                'auto_refresh' => true,
                'sound_notifications' => true,
                'email_notifications' => true,
                'dashboard_layout' => 'grid',
            ];
            $u->notification_settings  = $u->notification_settings  ?? [
                'trade_completed' => true,
                'bot_started' => true,
                'bot_stopped' => true,
                'profit_milestone' => true,
                'loss_alert' => true,
                'system_maintenance' => true,
                'weekly_report' => true,
                'monthly_report' => true,
            ];
            $u->timezone              = $u->timezone ?? 'Asia/Tehran';
            $u->language              = $u->language ?? 'fa';
            $u->max_concurrent_bots   = $u->max_concurrent_bots ?? 3;
            $u->max_total_capital     = $u->max_total_capital   ?? 10000;
            $u->is_active             = $u->is_active ?? true;
            $u->is_admin              = $u->is_admin  ?? false;
        });
    }

    // ===== Helperهای سبک =====
    public function recordLogin(): void
    {
        $this->update([
            'last_login_at' => now(),
            'login_count'   => (int) ($this->login_count ?? 0) + 1,
        ]);
    }
}
