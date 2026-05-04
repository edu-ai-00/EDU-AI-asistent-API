<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadCourseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Auth handled by middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Core required fields
            'export_type' => 'required|in:course_v2',
            'course_id' => 'required|string|max:255',
            'version' => 'required|integer|min:1',
            'name' => 'required|string|max:255',

            // Optional metadata
            'description' => 'nullable|string|max:500',
            'language' => 'sometimes|string|max:10',
            'author' => 'nullable|string|max:255',
            'emoji' => 'nullable|string|max:10',
            'status' => 'sometimes|in:draft,locked,approved,published,private',
            'pin' => 'nullable|string|size:6|alpha_num',
            'updated' => 'nullable|date',
            'estimated_minutes' => 'nullable|integer|min:0',

            // Course content
            'lessons' => 'sometimes|array',
            'lessons.*' => 'array',
            'blocks' => 'sometimes|array',
            'blocks.*' => 'array',
        ];
    }

    /**
     * Get custom messages for validation errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'export_type.required' => 'Missing export_type field. Must be "course_v2".',
            'export_type.in' => 'Invalid export_type. Only "course_v2" is supported.',
            'course_id.required' => 'Missing course_id field.',
            'version.required' => 'Missing version field.',
            'version.integer' => 'Version must be an integer.',
            'version.min' => 'Version must be at least 1.',
            'name.required' => 'Missing name field.',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Default language to Czech if not provided
        if (!$this->has('language')) {
            $this->merge(['language' => 'cs']);
        }

        // Default status to draft if not provided
        if (!$this->has('status')) {
            $this->merge(['status' => 'draft']);
        }
    }
}
