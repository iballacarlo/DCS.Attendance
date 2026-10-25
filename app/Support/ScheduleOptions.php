<?php

namespace App\Support;

use App\Models\SystemSetting;

class ScheduleOptions
{
    public const CATALOG = [
        1 => [
            'First Semester' => ['COSC 50A' => 'DISCRETE STRUCTURE', 'DCIT 21A' => 'INTRODUCTION TO COMPUTING', 'DCIT 22A' => 'COMPUTER PROGRAMMING 1', 'NSTP 1' => 'NATIONAL SERVICE TRAINING PROGRAM 1', 'CVSU 101' => 'INSTITUTIONAL ORIENTATION'],
            'Second Semester' => ['DCIT 23A' => 'COMPUTER PROGRAMMING 2', 'ITEC 50A' => 'WEB SYSTEM AND TECHNOLOGIES 1', 'NSTP 2' => 'NATIONAL SERVICE TRAINING PROGRAM 2'],
        ],
        2 => [
            'First Semester' => ['ITEC 55' => 'PLATFORM TECHNOLOGIES', 'DCIT 24' => 'INFORMATION MANAGEMENT', 'DCIT50' => 'OBJECT ORIENTED PROGRAMMING'],
            'Second Semester' => ['DCIT 25A' => 'DATA STRUCTURES AND ALGORITHMS', 'ITEC 60A' => 'INTEGRATED PROGRAMMING AND TECHNOLOGIES 1', 'ITEC 65A' => 'OPEN SOURCE TECHNOLOGY', 'DCIT 55A' => 'ADVANCE DATABASE MANAGEMENT SYSTEM', 'ITEC 70A' => 'MULTIMEDIA SYSTEMS'],
            'Midyear Term' => ['ITEC 75A' => 'SYSTEM INTEGRATION AND ARCHITECTURE 1'],
        ],
        3 => [
            'First Semester' => ['ITEC 80' => 'INTRODUCTION TO HUMAN COMPUTER INTERACTION', 'ITEC 85A' => 'INFORMATION ASSURANCE AND SECURITY 1', 'ITEC 90' => 'NETWORK FUNDAMENTALS', 'INSY 55' => 'SYSTEM ANALYSIS AND DESIGN', 'DCIT 26' => 'APPLICATION DEVELOPMENT AND EMERGING TECHNOLOGIES', 'DCIT 60A' => 'METHODS OF RESEARCH'],
            'Second Semester' => ['ITEC 95' => 'QUANTITATIVE METHODS (MODELING & SIMULATION)', 'ITEC 101A' => 'IT ELECTIVE 1 (HUMAN COMPUTER INTERACTION 2)', 'ITEC 106A' => 'IT ELECTIVE 2 (WEB SYSTEM AND TECHNOLOGIES 2)', 'ITEC 100' => 'INFORMATION ASSURANCE AND SECURITY 2', 'ITEC 105' => 'NETWORK MANAGEMENT', 'ITEC 200-A' => 'CAPSTONE PROJECT AND RESEARCH 1'],
        ],
        4 => [
            'First Semester' => ['DCIT 65' => 'SOCIAL AND PROFESSIONAL ISSUES', 'ITEC 111' => 'IT ELECTIVE 3 (INTEGRATED PROGRAMMING AND TECHNOLOGIES 2)', 'ITEC 116' => 'IT ELECTIVE 4 (SYSTEMS INTEGRATION AND ARCHITECTURE 2)', 'ITEC 110' => 'SYSTEMS ADMINISTRATION AND MAINTENANCE', 'ITEC 200-B' => 'CAPSTONE PROJECT AND RESEARCH 2'],
            'Second Semester' => ['ITEC 199' => 'PRACTICUM'],
        ],
    ];

    public static function subjectsFor(string $section, ?string $semester = null): array
    {
        preg_match('/^BSIT ([1-4])[A-F]$/', $section, $match);
        $subjects = array_keys(self::CATALOG[$match[1] ?? 0][$semester ?? SystemSetting::academicTerm()['semester']] ?? []);
        sort($subjects, SORT_NATURAL);

        return $subjects;
    }

    public const CLASS_MODES = ['lecture' => 'Lecture', 'laboratory' => 'Laboratory'];

    public const LECTURE_ONLY_SUBJECTS = ['NSTP 1', 'CVSU 101', 'NSTP 2'];

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

    public static function all(): array
    {
        $semester = SystemSetting::academicTerm()['semester'];
        $names = [];
        foreach (self::CATALOG as $terms) {
            foreach ($terms as $subjects) {
                $names = array_merge($names, $subjects);
            }
        }

        return ['subjectsBySection' => array_combine(self::sections(), array_map(fn ($section) => self::subjectsFor($section, $semester), self::sections())), 'subjectNames' => $names, 'semester' => $semester, 'sections' => self::sections(), 'classModes' => self::CLASS_MODES, 'lectureOnlySubjects' => self::LECTURE_ONLY_SUBJECTS];
    }
}
