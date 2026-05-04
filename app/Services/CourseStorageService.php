<?php

namespace App\Services;

use App\Models\Course;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CourseStorageService
{
    /**
     * The S3/R2 disk name from filesystems config.
     */
    protected string $disk = 's3';

    /**
     * Default URL expiration in minutes.
     */
    protected int $defaultExpiration = 60;

    /**
     * Upload course JSON to R2 storage.
     *
     * @param array $courseData The full course JSON data
     * @param Course|null $existingCourse Existing course record (for updates)
     * @return array Upload result with path, size, and URL
     */
    public function uploadCourse(array $courseData, ?Course $existingCourse = null): array
    {
        $courseId = $courseData['course_id'];
        $version = $courseData['version'];
        $path = Course::generateFilePath($courseId, $version);

        $jsonContent = json_encode($courseData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $size = strlen($jsonContent);

        Storage::disk($this->disk)->put($path, $jsonContent, [
            'ContentType' => 'application/json',
        ]);

        return [
            'path' => $path,
            'size' => $size,
            'url' => $this->getSignedUrl($path),
            'expires_at' => now()->addMinutes($this->defaultExpiration)->toIso8601String(),
        ];
    }

    /**
     * Generate a signed URL for downloading a course file.
     *
     * @param string $path R2 file path
     * @param int $minutes URL expiration in minutes
     * @return string Signed download URL
     */
    public function getSignedUrl(string $path, int $minutes = 60): string
    {
        return Storage::disk($this->disk)->temporaryUrl(
            $path,
            now()->addMinutes($minutes)
        );
    }

    /**
     * Check if a file exists in R2 storage.
     *
     * @param string $path R2 file path
     * @return bool
     */
    public function fileExists(string $path): bool
    {
        return Storage::disk($this->disk)->exists($path);
    }

    /**
     * Delete a course file from R2 storage.
     *
     * @param string $path R2 file path
     * @return bool
     */
    public function deleteFile(string $path): bool
    {
        return Storage::disk($this->disk)->delete($path);
    }

    /**
     * Get file size from R2 storage.
     *
     * @param string $path R2 file path
     * @return int|null File size in bytes or null if not found
     */
    public function getFileSize(string $path): ?int
    {
        if (!$this->fileExists($path)) {
            return null;
        }

        return Storage::disk($this->disk)->size($path);
    }

    /**
     * Extract metadata from course JSON for database storage.
     * This keeps the database record lightweight while storing full JSON in R2.
     *
     * @param array $courseData Full course JSON data
     * @return array Metadata fields for database
     */
    public function extractMetadata(array $courseData): array
    {
        $lessonCount = isset($courseData['lessons']) ? count($courseData['lessons']) : 0;

        // Estimate duration: each lesson ~15 minutes as default
        $estimatedMinutes = $lessonCount * 15;
        if (isset($courseData['estimated_minutes'])) {
            $estimatedMinutes = $courseData['estimated_minutes'];
        }

        return [
            'course_id' => $courseData['course_id'],
            'pin' => isset($courseData['pin']) && $courseData['pin'] !== ''
                ? strtoupper((string) $courseData['pin'])
                : null,
            'only_quiz' => (bool) ($courseData['only_quiz'] ?? false),
            'starts_with_quiz' => (bool) ($courseData['only_quiz'] ?? false) ? true : (bool) ($courseData['starts_with_quiz'] ?? false),
            'only_once' => (bool) ($courseData['only_once'] ?? false),
            'logged_only' => (bool) ($courseData['logged_only'] ?? false),
            'quiz_evaluate' => (bool) ($courseData['quiz_evaluate'] ?? false),
            'name' => $courseData['name'] ?? 'Untitled Course',
            'description' => $courseData['description'] ?? null,
            'author' => $courseData['author'] ?? null,
            'emoji' => $courseData['emoji'] ?? $this->detectEmoji($courseData),
            'version' => $courseData['version'] ?? 1,
            'language' => $courseData['language'] ?? 'en',
            'status' => $courseData['status'] ?? 'draft',
            'lesson_count' => $lessonCount,
            'estimated_minutes' => $estimatedMinutes,
        ];
    }

    /**
     * Attempt to detect an appropriate emoji from course content.
     *
     * @param array $courseData Course data
     * @return string|null Detected emoji or null
     */
    protected function detectEmoji(array $courseData): ?string
    {
        $name = strtolower($courseData['name'] ?? '');
        $courseId = strtolower($courseData['course_id'] ?? '');

        // Common subject mappings
        $emojiMap = [
            'mat' => '🔢',
            'math' => '🔢',
            'zlomk' => '➗',
            'fraction' => '➗',
            'čeština' => '📚',
            'czech' => '📚',
            'english' => '🇬🇧',
            'angličtina' => '🇬🇧',
            'physics' => '⚛️',
            'fyzika' => '⚛️',
            'chemistry' => '🧪',
            'chemie' => '🧪',
            'biology' => '🧬',
            'biologie' => '🧬',
            'history' => '📜',
            'dějepis' => '📜',
            'geography' => '🌍',
            'zeměpis' => '🌍',
        ];

        foreach ($emojiMap as $keyword => $emoji) {
            if (Str::contains($name, $keyword) || Str::contains($courseId, $keyword)) {
                return $emoji;
            }
        }

        return '📖'; // Default book emoji
    }
}
