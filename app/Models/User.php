<?php

namespace App\Models;

use App\Enums\AccountStatus;
use App\Enums\NotificationCategory;
use App\Enums\UserRole;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'account_status', 'notification_preferences'];

    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    public function teacherProfile(): HasOne
    {
        return $this->hasOne(TeacherProfile::class);
    }

    public function lessonReminderDeliveries(): HasMany
    {
        return $this->hasMany(LessonReminderDelivery::class);
    }

    public function hasRole(UserRole ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function canReceiveEmailNotification(NotificationCategory $category): bool
    {
        if (! is_string($this->email) || filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $preferences = $this->notification_preferences ?? [];

        return ($preferences['email'] ?? true) && ($preferences[$category->value] ?? true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'account_status' => AccountStatus::class,
            'notification_preferences' => 'array',
        ];
    }
}
