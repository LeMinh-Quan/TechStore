<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class EducationDiscountService
{
    private const LEVELS = [
        'student_middle_high' => ['label' => 'Học sinh THCS/THPT', 'discount' => 5],
        'student_college' => ['label' => 'Sinh viên cao đẳng', 'discount' => 7],
        'student_university' => ['label' => 'Sinh viên đại học', 'discount' => 9],
        'student_masters' => ['label' => 'Học viên thạc sĩ', 'discount' => 11],
        'student_doctorate' => ['label' => 'Nghiên cứu sinh tiến sĩ', 'discount' => 13],
        'teacher' => ['label' => 'Giáo viên', 'discount' => 15],
        'lecturer_college' => ['label' => 'Giảng viên cao đẳng', 'discount' => 17],
        'lecturer_university' => ['label' => 'Giảng viên đại học', 'discount' => 20],
    ];

    public static function levels(): array
    {
        return self::LEVELS;
    }

    public function percentForCustomer(string $customerId): ?int
    {
        $benefit = DB::table('uu_dai_hoc_tap')
            ->where('ma_kh', $customerId)
            ->where('trang_thai', 'approved')
            ->first(['doi_tuong']);
        $audience = $benefit?->doi_tuong;

        return $audience === null ? null : (self::LEVELS[$audience]['discount'] ?? null);
    }

    public function discountForCustomer(string $customerId, int $subtotal): int
    {
        $percent = $this->percentForCustomer($customerId);

        return $percent === null ? 0 : min(2_000_000, (int) floor($subtotal * $percent / 100));
    }
}
