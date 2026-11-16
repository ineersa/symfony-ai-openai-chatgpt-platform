<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT;

/** Lossless diagnostics belong to the caller; the bridge does not sanitize provider errors. */
final class ProviderErrorMessage
{
    /** @param array<string, mixed> $error */
    public static function format(array $error, ?int $status = null, ?string $body = null): string
    {
        $prefix = null === $status ? 'Provider error' : 'HTTP '.$status;
        $message = \is_string($error['message'] ?? null) ? $error['message'] : null;
        $details = $body ?? json_encode($error, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (false === $details) {
            // A non-JSON-compatible value must not replace the provider failure
            // with an encoder exception or silently substitute invalid bytes.
            $details = var_export($error, true);
        }

        return $prefix.(null !== $message ? ': '.$message : '')."\n".$details;
    }
}
