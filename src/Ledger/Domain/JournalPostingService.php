<?php

declare(strict_types=1);

namespace App\Ledger\Domain;

/**
 * Posts journal entries, enforcing the contextual rules (accounts must exist,
 * be open, and be denominated in each leg's currency) via
 * {@see AccountStatusReader} before the structural invariants in
 * {@see JournalEntry::post()}.
 */
final class JournalPostingService
{
    public function __construct(private readonly AccountStatusReader $accounts) {}

    public function post(JournalEntryId $id, Leg ...$legs): JournalEntry
    {
        foreach ($legs as $leg) {
            $this->accounts->assertPostable($leg->account, $leg->amount->currency);
        }

        return JournalEntry::post($id, ...$legs);
    }
}
