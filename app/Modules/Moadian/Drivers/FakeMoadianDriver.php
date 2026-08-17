<?php

declare(strict_types=1);

namespace App\Modules\Moadian\Drivers;

use App\Modules\Moadian\Contracts\MoadianDriver;
use App\Modules\Moadian\Support\MoadianPayload;
use App\Modules\Moadian\Support\SubmissionResult;
use App\Modules\Moadian\Support\SubmissionStatus;
use RuntimeException;

/**
 * The only driver that exists, and the only one that will until a customer asks
 * ([ADR 0011](../../../../docs/adr/0011-moadian-adapter-without-a-provider.md)).
 *
 * ## It is a test double AND the launch configuration
 *
 * `MOADIAN_ENABLED=false` for every plan, so nothing reaches this in production. What it
 * has to be is a faithful *contract* — every behaviour the interface promises, exercisable
 * on demand, so that the queue, the inbox and the mapping above it are tested against the
 * real shape rather than against a stub that always says yes.
 *
 * ## Three outcomes, chosen by the caller
 *
 * A fake that only succeeds tests one third of the code. `rejectNext()` and `failNext()`
 * make the other two reachable, which is what the spec's acceptance line — "covering accept,
 * reject and transport failure" — actually requires. `failNext()` throws, because that is
 * how the contract distinguishes "we could not ask" from "they said no".
 */
final class FakeMoadianDriver implements MoadianDriver
{
    /** @var list<MoadianPayload> */
    private array $sent = [];

    /** @var list<array{code: string, message: string}> */
    private array $rejections = [];

    private int $transportFailures = 0;

    /** @var array<string, SubmissionStatus> */
    private array $statuses = [];

    private int $sequence = 0;

    public function send(MoadianPayload $payload): SubmissionResult
    {
        if ($this->transportFailures > 0) {
            $this->transportFailures--;

            // Never recorded in `$sent`: a request that did not arrive was not sent, and a
            // test asserting "one submission" after a retry would otherwise count two.
            throw new RuntimeException('moadian: transport failure (fake)');
        }

        $this->sent[] = $payload;

        $rejection = array_shift($this->rejections);

        if ($rejection !== null) {
            return SubmissionResult::rejected($rejection['code'], $rejection['message']);
        }

        $this->sequence++;

        $reference = sprintf('FAKE-%s-%04d', $payload->type, $this->sequence);

        // Deterministic, and derived from the reference: a tax id that changed between runs
        // would make every golden-number assertion above this unpinnable.
        $this->statuses[$reference] = new SubmissionStatus('accepted', taxId: 'TAX-'.$this->sequence);

        return SubmissionResult::accepted($reference, 'TAX-'.$this->sequence);
    }

    public function status(string $reference): SubmissionStatus
    {
        return $this->statuses[$reference] ?? new SubmissionStatus('pending');
    }

    public function cancel(string $reference, string $reason): SubmissionResult
    {
        $this->sequence++;

        return SubmissionResult::accepted(sprintf('FAKE-cancel-%04d', $this->sequence));
    }

    public function name(): string
    {
        return 'fake';
    }

    /* ------------------------------------------------------------- test controls -- */

    public function rejectNext(string $code = 'E-100', string $message = 'شناسهٔ اقتصادی خریدار نامعتبر است.'): void
    {
        $this->rejections[] = ['code' => $code, 'message' => $message];
    }

    public function failNext(int $times = 1): void
    {
        $this->transportFailures += $times;
    }

    public function markRejectedOnPoll(string $reference, string $code, string $message): void
    {
        // A document accepted at submission and rejected later — the case that makes
        // polling a different question from sending.
        $this->statuses[$reference] = new SubmissionStatus('rejected', errorCode: $code, errorMessage: $message);
    }

    /** @return list<MoadianPayload> */
    public function sent(): array
    {
        return $this->sent;
    }

    public function reset(): void
    {
        $this->sent = [];
        $this->rejections = [];
        $this->transportFailures = 0;
        $this->statuses = [];
        $this->sequence = 0;
    }
}
