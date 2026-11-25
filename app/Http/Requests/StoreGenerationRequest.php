<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\GenerationRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreGenerationRequest extends FormRequest
{
    /**
     * Sizes and qualities are validated against the configured rate card
     * rather than a list written out here. Anything this application cannot
     * price, it cannot honestly offer.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $sizes = array_keys(config('pixelforge.estimation.output_tokens'));
        $qualities = array_keys(config('pixelforge.estimation.output_tokens')[$sizes[0]]);

        return [
            'prompt' => ['required', 'string', 'min:1', 'max:1000'],
            'size' => ['required', 'string', Rule::in($sizes)],
            'quality' => ['required', 'string', Rule::in($qualities)],
            'reference_image_urls' => ['sometimes', 'array', 'max:4'],
            'reference_image_urls.*' => ['url:http,https', 'max:2048'],
        ];
    }

    public function toGenerationRequest(): GenerationRequest
    {
        return new GenerationRequest(
            prompt: $this->string('prompt')->toString(),
            size: $this->string('size')->toString(),
            quality: $this->string('quality')->toString(),
            referenceImageUrls: $this->input('reference_image_urls', []),
        );
    }
}
