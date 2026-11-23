#!/usr/bin/env node
/** `npx create-agent-cms <dir>` 用エントリ。`agentcms create <dir>` と等価。 */
import { create } from "./commands/create.js";
const dir = process.argv[2];
if (!dir) {
    console.error("使い方: npx create-agent-cms <dir>");
    process.exit(1);
}
process.exit(await create(dir));
