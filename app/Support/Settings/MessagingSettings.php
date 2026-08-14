<?php

declare(strict_types=1);

namespace App\Support\Settings;

use App\Modules\Messaging\Enums\AutomationKey;

/**
 * Which automatic messages a shop has switched on.
 *
 * ## Everything defaults to OFF, and the direction is the whole point
 *
 * A shop that has never opened the messaging screen must never wake up to texts it did not
 * authorise and a wallet somebody drained on its behalf. That is not a preference — it is
 * the difference between software a shopkeeper trusts and software that spent their money
 * while they slept.
 *
 * So the default is off for **every** automation, including the obviously useful ones. An
 * enabled-unless-disabled default would silently switch messaging on for every tenant that
 * already exists the moment this phase deploys, which is the single most damaging thing
 * this module could do.
 *
 * The asymmetry with, say, the rounding default is deliberate: rounding affects a number on
 * a screen, and this sends messages to other people and spends real credit.
 *
 * ## An unknown key is off, not on
 *
 * A settings document is user input by the time it reaches here. A malformed value, a key
 * from a newer version, a hand-edited JSON blob — all resolve to off. Failing closed is the
 * same discipline the repair approval cap uses, for the same reason: the safe direction is
 * the one that does less.
 */
final readonly class MessagingSettings
{
    /**
     * @param  array<string, bool>  $automations  keyed by AutomationKey value
     */
    public function __construct(
        public array $automations,
        /** Quiet hours: nothing sends before this hour, shop-local. */
        public int $quietUntilHour,
        /** Nothing sends after this hour either — a 2am «تولدت مبارک» is not a kindness. */
        public int $quietFromHour,
    ) {}

    public function isEnabled(AutomationKey $key): bool
    {
        return $this->automations[$key->value] ?? false;
    }

    /**
     * Is this a reasonable hour to text somebody?
     *
     * Applies to swept automations only — a repair marked ready at 9pm should still text,
     * because the customer is waiting for exactly that. Birthdays and due-date reminders
     * can wait for morning.
     */
    public function isQuietAt(int $hour): bool
    {
        return $hour < $this->quietUntilHour || $hour >= $this->quietFromHour;
    }
}
