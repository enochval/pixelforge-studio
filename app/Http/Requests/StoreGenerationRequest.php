<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\GenerationRequest;
use App\Integrations\Pixelforge\RateCardRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenerationRequest extends FormRequest
{
    /**
     * Sizes and qualities are validated against the live rate card rather than
     * a list written out here, so the accepted values and the priceable values
     * cannot drift apart. Anything this application cannot price, it will not
     * accept.
     *
     * The prompt limit and the cap of four reference images are the provider's,
     * mirrored here so an oversized request is refused with a field error
     * instead of reaching the provider and coming back as a 422.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rates = app(RateCardRepository::class)->current();

        return [
            'prompt' => ['required', 'string', 'min:1', 'max:4000'],
            'size' => ['required', 'string', Rule::in($rates->sizes())],
            'quality' => ['required', 'string', Rule::in($rates->qualities())],
            'reference_image_urls' => ['sometimes', 'array', 'max:4'],
            'reference_image_urls.*' => ['url:http,https', 'max:2048'],
        ];
    }

    public function toGenerationRequest(): GenerationRequest
    {
        /** @var array<int, string> $referenceImageUrls */
        $referenceImageUrls = $this->input('reference_image_urls', []);

        return new GenerationRequest(
            prompt: $this->string('prompt')->toString(),
            size: $this->string('size')->toString(),
            quality: $this->string('quality')->toString(),
            referenceImageUrls: $referenceImageUrls,
        );
    }
}
