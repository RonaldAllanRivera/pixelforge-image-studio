<?php

namespace App\Http\Requests;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The form as the user is filling it in. The prompt may still be empty, and only the
 * number of reference images matters for the price, so the URLs aren't checked here.
 */
class QuoteRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['nullable', 'string', 'max:'.StoreGenerationRequest::MAX_PROMPT_CHARACTERS],
            'size' => ['required', Rule::enum(ImageSize::class)],
            'quality' => ['required', Rule::enum(ImageQuality::class)],
            'reference_images' => ['nullable', 'array', 'max:'.StoreGenerationRequest::MAX_REFERENCE_IMAGES],
        ];
    }

    public function prompt(): string
    {
        return (string) $this->string('prompt');
    }

    public function imageSize(): ImageSize
    {
        return ImageSize::from($this->string('size')->value());
    }

    public function imageQuality(): ImageQuality
    {
        return ImageQuality::from($this->string('quality')->value());
    }

    public function referenceImageCount(): int
    {
        return collect($this->array('reference_images'))
            ->filter(fn (mixed $url) => is_string($url) && trim($url) !== '')
            ->count();
    }
}
