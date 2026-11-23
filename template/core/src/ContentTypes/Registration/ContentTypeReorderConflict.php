<?php

declare(strict_types=1);

namespace AgentCms\Core\ContentTypes\Registration;

use RuntimeException;

/**
 * 並べ替えの競合（Phase 9.7 / STAGE 20C）。
 *
 * **transaction を抜けるためだけに使う内部例外**である。closure から `false` を
 * 返しても `Connection::transaction()` は commit してしまうので、
 * 「1 件でも版が違えば全体を戻す」は例外でしか表現できない。
 * `ContentTypeSettingsWriter::reorder()` の外へは出さない。
 */
final class ContentTypeReorderConflict extends RuntimeException {}
