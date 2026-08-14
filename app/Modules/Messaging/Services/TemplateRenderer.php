<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Enums\AutomationKey;
use App\Modules\Messaging\Models\MessageTemplate;

/**
 * Turning a shop's sentence into an ordered token list.
 *
 * ## Order of appearance IS the token order
 *
 * «سلام {name}، دستگاه {device} آماده است» yields `[name, device]`, which becomes
 * Kavenegar's `token` and `token2`. Nothing sorts them and nothing keys them: the position
 * in the sentence is the position on the wire.
 *
 * That means **editing the sentence reorders the tokens**, and a shop that swaps two
 * variables around gets a message with the values swapped. It is the sharp edge of pattern
 * sends and it cannot be designed away — the gateway's API is positional — so it is stated
 * here, pinned by a test, and surfaced in the editor as a preview the shop reads before
 * saving.
 *
 * ## A variable with no value renders empty rather than as its own name
 *
 * A missing `{amount}` becoming the literal «amount» in a customer's message is worse than
 * a gap: it reads as a bug the customer can see. Empty is quieter and equally wrong, so the
 * template editor refuses to save a variable the automation does not supply — the
 * declaration lives on {@see AutomationKey::variables()} and this is the fallback for a
 * template saved before a variable was renamed.
 */
final class TemplateRenderer
{
    /** `{name}`, `{ticket_code}`, `{due_date_j}` — letters, digits, underscore. */
    private const PATTERN = '/\{([a-z0-9_]+)\}/i';

    /**
     * The variable names a template uses, in the order they appear.
     *
     * @return list<string>
     */
    public function variablesIn(string $body): array
    {
        preg_match_all(self::PATTERN, $body, $matches);

        // Duplicates keep their first position: «{name} … {name}» is one token used twice
        // by the pattern, not two tokens.
        return array_values(array_unique($matches[1]));
    }

    /**
     * The ordered token values for a template, given what the automation supplies.
     *
     * @param  array<string, string|int|null>  $values
     * @return list<string>
     */
    public function tokensFor(string $body, array $values): array
    {
        $tokens = [];

        foreach ($this->variablesIn($body) as $name) {
            $value = $values[$name] ?? null;

            $tokens[] = $value === null ? '' : (string) $value;
        }

        return $tokens;
    }

    /**
     * The message as the customer will read it — for the editor's preview.
     *
     * The preview matters more than it looks: it is the only place a shop sees the effect
     * of reordering variables before a customer does.
     *
     * @param  array<string, string|int|null>  $values
     */
    public function preview(string $body, array $values): string
    {
        return preg_replace_callback(
            self::PATTERN,
            fn (array $match): string => (string) ($values[$match[1]] ?? ''),
            $body,
        ) ?? $body;
    }

    /**
     * Variables a template uses that its automation will never supply.
     *
     * The editor refuses to save while this is non-empty — a `{amount}` in a birthday
     * message renders as a hole in a sentence somebody receives.
     *
     * @return list<string>
     */
    public function unknownVariables(string $body, AutomationKey $key): array
    {
        return array_values(array_diff($this->variablesIn($body), $key->variables()));
    }

    /**
     * Everything needed to hand one message to {@see SendSms}.
     *
     * @param  array<string, string|int|null>  $values
     * @return array{template_id: string, tokens: list<string>, body: string}|null
     */
    public function resolve(MessageTemplate $template, array $values): ?array
    {
        if (! $template->isSendable()) {
            return null;
        }

        return [
            'template_id' => (string) $template->provider_template_id,
            'tokens' => $this->tokensFor($template->body, $values),
            'body' => $this->preview($template->body, $values),
        ];
    }
}
