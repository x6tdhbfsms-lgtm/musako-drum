<?php

namespace App\Enums;

enum ContractChangeType: string
{
    case AdministrativeEdit = 'administrative_edit';
    case Schedule = 'schedule';
    case MonthlyLessons = 'monthly_lessons';
    case LessonMinutes = 'lesson_minutes';
    case CourseChange = 'course_change';
    case CourseAdd = 'course_add';
    case VenueChange = 'venue_change';
    case LessonType = 'lesson_type';
    case PricingCategory = 'pricing_category';

    public function label(): string
    {
        return match ($this) {
            self::AdministrativeEdit => '管理者による契約情報変更',
            self::Schedule => '時間割／曜日／時間変更',
            self::MonthlyLessons => '月のレッスン回数変更',
            self::LessonMinutes => '1回のレッスン時間変更',
            self::CourseChange => 'コース変更',
            self::CourseAdd => 'コース追加',
            self::VenueChange => '会場変更',
            self::LessonType => 'レギュラー／フレックス変更',
            self::PricingCategory => '一般／ジュニア区分変更',
        };
    }
}
