<?php

namespace App\Services;

use App\Models\AdmissionApplication;
use App\Models\User;

class StudentLoginNameGenerator
{
    public function nextAvailable(): string
    {
        $names = User::query()
            ->where('login_name', 'like', 'student%')
            ->pluck('login_name')
            ->merge(AdmissionApplication::query()
                ->whereNotNull('pending_login_name')
                ->where('pending_login_name', 'like', 'student%')
                ->pluck('pending_login_name'));

        $highestNumber = $names->reduce(function (int $highest, ?string $name): int {
            if (preg_match('/^student([0-9]{3,})$/i', (string) $name, $matches) !== 1) {
                return $highest;
            }

            return max($highest, (int) $matches[1]);
        }, 0);

        return sprintf('student%03d', $highestNumber + 1);
    }
}
