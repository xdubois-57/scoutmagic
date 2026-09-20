<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * For the consumer whose message is written by a machine, for a machine,
 * and arrives as an attachment nobody will ever open (roadmap IT-06).
 *
 * **Opt-in, and separate from {@see MessageConsumerInterface} on purpose.**
 * Every existing consumer sorts human correspondence, and none of them
 * should acquire a method about bytes to satisfy a contract written for
 * one machine feed. A consumer implementing this is asked in addition to
 * `analyze()`, never instead of it.
 *
 * **Why it had to exist at all.** The roadmap assumed
 * `Service\AttachmentPolicy` could hand a DMARC report over, but that
 * policy answers a different question — which attachments become documents
 * a person opens — and it refuses archives on the sound grounds that a zip
 * is a container nobody inspected. `Api\CandidateAttachment` carries no
 * bytes, equally deliberately. So a DMARC consumer written the way the
 * roadmap describes would have seen every report recorded as
 * `AttachmentOmission::MIME_REJECTED` and read none of them. The choice
 * was to widen an allowlist that protects every mailbox, or to open one
 * narrow, declared, bounded door. This is that door.
 *
 * **Three bounds, all the consumer's own.** It says which sniffed types it
 * wants, how large a payload it will take, and nothing else reaches it.
 * The module keeps none of the bytes and writes no file.
 */
interface PayloadConsumerInterface
{
    /**
     * The **sniffed** types whose bytes this consumer wants — never what
     * the sender's `Content-Type` claimed.
     *
     * An empty list means « none », which is a complete answer and turns
     * this consumer back into an ordinary one.
     *
     * @return string[]
     */
    public function payloadMimeTypes(): array;

    /**
     * The largest payload this consumer will be handed, in bytes.
     *
     * **It is asked rather than imposed** because only the consumer knows
     * what its own feed looks like: a DMARC aggregate report is kilobytes,
     * and a ceiling generous enough for it is still two orders of
     * magnitude below anything that would trouble a shared host. A
     * payload over the ceiling is not passed and not truncated — half a
     * machine report is not a smaller machine report, it is rubbish.
     */
    public function maxPayloadBytes(): int;

    /**
     * What this consumer makes of the payloads that matched.
     *
     * Called once per message, with every matching payload, and only when
     * at least one matched. Failing here costs this consumer its answer
     * and nothing else: the registry catches, journals against this
     * consumer id, and the synchronisation carries on with everybody
     * else's mail.
     *
     * @param list<MessagePayload> $payloads
     */
    public function analyzePayloads(CandidateMessage $message, array $payloads): AnalysisResult;
}
