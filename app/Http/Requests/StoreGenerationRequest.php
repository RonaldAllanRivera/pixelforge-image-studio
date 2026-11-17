<?php

namespace App\Http\Requests;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new generation. These rules copy Pixelforge's own, so we never send it a request it
 * would reject with a 422. Laravel's `max` counts characters with mb_strlen, as Pixelforge
 * does, so an emoji counts as one.
 */
class StoreGenerationRequest extends FormRequest
{
    public const MAX_PROMPT_CHARACTERS = 4000;

    public const MAX_REFERENCE_IMAGES = 4;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'max:'.self::MAX_PROMPT_CHARACTERS],
            'size' => ['required', Rule::enum(ImageSize::class)],
            'quality' => ['required', Rule::enum(ImageQuality::class)],
            'reference_images' => ['array', 'max:'.self::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['required', 'string', 'url:http,https', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reference_images.*' => 'reference image URL'];
    }

    /**
     * An empty reference row the user added but didn't fill in isn't an error: drop it.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'reference_images' => collect($this->array('reference_images'))
                ->reject(fn (mixed $url) => $url === null || $url === '')
                ->values()
                ->all(),
        ]);
    }

    public function prompt(): string
    {
        return $this->string('prompt')->value();
    }

    public function imageSize(): ImageSize
    {
        return ImageSize::from($this->string('size')->value());
    }

    public function imageQuality(): ImageQuality
    {
        return ImageQuality::from($this->string('quality')->value());
    }

    /**
     * @return list<string>
     */
    public function referenceImages(): array
    {
        /** @var list<string> */
        return $this->array('reference_images');
    }
}
