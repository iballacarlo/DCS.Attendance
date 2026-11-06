<?php

namespace App\Support;

use App\Models\Subject;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Schema;

class ScheduleOptions
{
    public static function subjectsFor(string $section, ?string $semester = null): array
    {
        preg_match('/^BSIT ([1-4])[A-F]$/', $section, $match);
        if (! isset($match[1]) || ! Schema::hasTable('subjects')) {
            return [];
        }

        $subjects = Subject::query()->active()
            ->where('year_level', (int) $match[1])
            ->where('semester', $semester ?? SystemSetting::academicTerm()['semester'])
            ->pluck('code')->all();
        sort($subjects, SORT_NATURAL | SORT_FLAG_CASE);

        return $subjects;
    }

    public const CLASS_MODES = ['lecture' => 'Lecture', 'laboratory' => 'Laboratory'];

    public static function lectureOnlySubjects(): array
    {
        return Schema::hasTable('subjects')
            ? Subject::query()->where('lecture_only', true)->pluck('code')->all()
            : [];
    }

    public static function sections(): array
    {
        $sections = [];
        foreach (range(1, 4) as $year) {
            foreach (range('A', 'F') as $letter) {
                $sections[] = "BSIT {$year}{$letter}";
            }
        }

        return $sections;
    }

    public static function all(?string $selectedSemester = null): array
    {
        $semester = $selectedSemester ?? SystemSetting::academicTerm()['semester'];
        $names = Schema::hasTable('subjects') ? Subject::query()->pluck('name', 'code')->all() : [];

        return ['subjectsBySection' => array_combine(self::sections(), array_map(fn ($section) => self::subjectsFor($section, $semester), self::sections())), 'subjectNames' => $names, 'semester' => $semester, 'sections' => self::sections(), 'classModes' => self::CLASS_MODES, 'lectureOnlySubjects' => self::lectureOnlySubjects()];
    }
}
