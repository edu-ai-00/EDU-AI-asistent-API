<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Services\CourseStorageService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class DemoCourseSeeder extends Seeder
{
    public function run(): void
    {
        $demoPath = base_path('demo/demo_zlomky.json');

        if (!file_exists($demoPath)) {
            $this->command->error('Demo course file not found at: ' . $demoPath);
            return;
        }

        $jsonContent = file_get_contents($demoPath);
        $courseData = json_decode($jsonContent, true);

        if (!$courseData) {
            $this->command->error('Failed to parse demo course JSON');
            return;
        }

        $courseId = $courseData['course_id'];
        $version = $courseData['version'] ?? 1;

        // Check if course already exists with R2 file
        $existing = Course::where('course_id', $courseId)->first();
        if ($existing && $existing->file_path) {
            $this->command->info("Course {$courseId} already exists with R2 file, skipping.");
            return;
        }

        // Extract metadata
        $lessonCount = count($courseData['lessons'] ?? []);
        $estimatedMinutes = $lessonCount * 15; // rough estimate

        // Try to upload to R2 if configured
        $filePath = $existing?->file_path;
        $fileSize = strlen($jsonContent);

        if (!$filePath) {
            try {
                $storageService = app(CourseStorageService::class);
                $uploadResult = $storageService->uploadCourse($courseData);
                $filePath = $uploadResult['path'];
                $this->command->info("Uploaded to R2: {$filePath}");
            } catch (\Exception $e) {
                $this->command->warn("R2 upload skipped: " . $e->getMessage());
            }
        }

        try {
            // Create or update course record
            if ($existing) {
                $existing->update([
                    'name' => $courseData['name'],
                    'description' => $courseData['description'] ?? null,
                    'author' => $courseData['author'] ?? null,
                    'emoji' => $courseData['emoji'] ?? '📚',
                    'version' => $version,
                    'status' => $courseData['status'] ?? 'draft',
                    'language' => $courseData['language'] ?? 'cs',
                    'lesson_count' => $lessonCount,
                    'estimated_minutes' => $estimatedMinutes,
                    'data' => $filePath ? null : $courseData,
                    'file_path' => $filePath,
                    'file_size' => $fileSize,
                    'file_uploaded_at' => $filePath ? now() : null,
                ]);
                $course = $existing;
            } else {
                $course = Course::create([
                    'course_id' => $courseId,
                    'name' => $courseData['name'],
                    'description' => $courseData['description'] ?? null,
                    'author' => $courseData['author'] ?? null,
                    'emoji' => $courseData['emoji'] ?? '📚',
                    'version' => $version,
                    'status' => $courseData['status'] ?? 'draft',
                    'language' => $courseData['language'] ?? 'cs',
                    'lesson_count' => $lessonCount,
                    'estimated_minutes' => $estimatedMinutes,
                    'data' => $filePath ? null : $courseData,
                    'file_path' => $filePath,
                    'file_size' => $fileSize,
                    'file_uploaded_at' => $filePath ? now() : null,
                ]);
            }

            $this->command->info("Course '{$course->name}' seeded successfully (ID: {$course->id})");
        } catch (\Exception $e) {
            $this->command->error("Failed to seed course: " . $e->getMessage());
        }
    }
}
