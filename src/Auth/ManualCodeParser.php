<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth;

/** Provider-neutral parser. The caller enforces its redirect URI and mandatory state policy. */
final class ManualCodeParser
{
    /** @return array{code: ?string, state: ?string, client_id: ?string, error: ?string} */
    public static function parse(#[\SensitiveParameter] string $input): array
    {
        $input = trim($input);
        $parameters = [];
        if (str_contains($input, '://')) {
            $query = parse_url($input, \PHP_URL_QUERY);
            if (\is_string($query)) {
                parse_str($query, $parameters);
            }
        } elseif (str_contains($input, '=')) {
            parse_str($input, $parameters);
        } elseif (str_contains($input, '#')) {
            [$parameters['code'], $parameters['state']] = explode('#', $input, 2);
        } elseif ('' !== $input) {
            $parameters['code'] = $input;
        }
        $result = [];
        foreach (['code', 'state', 'client_id', 'error'] as $key) {
            $value = $parameters[$key] ?? null;
            if (null !== $value && !\is_string($value)) {
                throw new AuthException('OAuth callback contains a non-scalar parameter.');
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
