<?php

namespace App\Pixelforge;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;

/**
 * A generation we are asking Pixelforge to create.
 */
final readonly class NewGeneration
{
    /**
     * @param  list<string>  $referenceImages  Up to 4 http(s) URLs.
     * @param  array<string, string>  $metadata  Echoed back on the generation and in every webhook.
     */
    public function __construct(
        public string $prompt,
        public ImageSize $size,
        public ImageQuality $quality,
        public array $referenceImages,
        public array $metadata,
    ) {}

    /**
     * The POST /v1/generations body. Built only from these fields, so a retry sends exactly
     * the same request, which Pixelforge requires when an Idempotency-Key is reused.
     *
     * @return array<string, mixed>
     */
    public function toRequestBody(): array
    {
        $body = [
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
        ];

        if ($this->referenceImages !== []) {
            $body['reference_images'] = $this->referenceImages;
        }

        if ($this->metadata !== []) {
            $body['metadata'] = $this->metadata;
        }

        return $body;
    }
}
