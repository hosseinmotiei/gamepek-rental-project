<?php

namespace App\Services\Contract;

/**
 * Substitutes {{name}} placeholders in a contract template body.
 *
 * DELIBERATELY NOT Blade::render(). Template bodies are edited by admins, and
 * compiling admin-supplied text as Blade is arbitrary PHP execution inside the
 * application -- an admin-panel compromise would become remote code execution.
 * A plain strtr over escaped values cannot execute anything.
 *
 * Every substituted value is passed through e() first, so a variable carrying
 * markup cannot inject it into the rendered contract.
 */
class TemplateRenderer
{
    public function render(string $body, array $variables): string
    {
        $replacements = [];

        foreach ($variables as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $replacements['{{'.$key.'}}'] = e((string) $value);
        }

        return strtr($body, $replacements);
    }

    /** @return list<string> placeholder names present in the body */
    public function placeholders(string $body): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', $body, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /** @return list<string> placeholders the given variable set does not cover */
    public function missing(string $body, array $variables): array
    {
        return array_values(array_diff($this->placeholders($body), array_keys($variables)));
    }
}
