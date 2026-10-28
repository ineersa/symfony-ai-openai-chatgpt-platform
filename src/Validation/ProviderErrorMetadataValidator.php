<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Validation;

final class ProviderErrorMetadataValidator
{
    /**
     * Retain identifiers only, never provider messages or arbitrary values.
     *
     * @param array<string, mixed> $error
     *
     * @return array<string, string>
     */
    public static function validate(array $error): array
    {
        $metadata = [];
        foreach (['code', 'type', 'param'] as $key) {
            $value = $error[$key] ?? null;
            if (\is_string($value) && \strlen($value) <= 128 && 1 === preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.:\[\]-]*\z/D', $value)) {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }
}
