<?php

namespace App\Support;

use App\Models\Subject;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Schema;

class ScheduleOptions
{
    public const COURSES = ['BSIT' => 'BSIT', 'BSCS' => 'BSCS'];

    public const YEAR_LEVELS = [1, 2, 3, 4];

    public const SECTION_LETTERS = ['A', 'B', 'C', 'D', 'E', 'F'];

    public static function subjectsFor(string $section, ?string $semester = null): array
    {
        preg_match('/^(BSIT|BSCS) ([1-4])([A-F])$/', $section, $match);
        if (! isset($match[2]) || ! Schema::hasTable('subjects')) {
            return [];
        }

        $subjects = Subject::query()->active()
            ->whereIn('course_scope', [$match[1], 'BOTH'])
            ->where('year_level', (int) $match[2])
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
        foreach (array_keys(self::COURSES) as $course) {
            foreach (self::YEAR_LEVELS as $year) {
                foreach (self::SECTION_LETTERS as $letter) {
                    $sections[] = "{$course} {$year}{$letter}";
                }
            }
        }

        return $sections;
    }

    public static function all(?string $selectedSemester = null): array
    {
        $semester = $selectedSemester ?? SystemSetting::academicTerm()['semester'];
        $names = Schema::hasTable('subjects') ? Subject::query()->pluck('name', 'code')->all() : [];

        return ['subjectsBySection' => array_combine(self::sections(), array_map(fn ($section) => self::subjectsFor($section, $semester), self::sections())),
            'subjectNames' => $names, 'semester' => $semester, 'sections' => self::sections(), 'courses' => self::COURSES,
            'yearLevels' => self::YEAR_LEVELS, 'sectionLetters' => self::SECTION_LETTERS,
            'classModes' => self::CLASS_MODES, 'lectureOnlySubjects' => self::lectureOnlySubjects()];
    }
}
