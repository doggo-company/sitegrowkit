/**
 * Synthetic Fixture を供給する FigmaApi 実装（SPEC §83–85）。
 * - CI / テスト / offline 開発で実 Figma API を不要にする
 * - CLI からは環境変数 AGENTCMS_FIGMA_FIXTURE=<dir> で選択される
 * - Variables は fixture dir に variables.json がある場合のみ利用可能
 *   （無い場合は本物の plan 制限と同じ 403 を返し、fallback 経路を実走させる）
 */
import { existsSync, readFileSync } from "node:fs";
import { join } from "node:path";
export class FixtureFigmaApi {
    dir;
    calls = [];
    constructor(dir) {
        this.dir = dir;
    }
    label() {
        return `Figma Fixture (${this.dir})`;
    }
    readJson(file) {
        return JSON.parse(readFileSync(join(this.dir, file), "utf8"));
    }
    getFileMeta(fileKey) {
        this.calls.push({ endpoint: "file_meta", detail: fileKey });
        return Promise.resolve({
            ok: true,
            data: this.readJson("meta.json"),
        });
    }
    getFile(fileKey) {
        this.calls.push({ endpoint: "file", detail: fileKey });
        return Promise.resolve({
            ok: true,
            data: this.readJson("file.json"),
        });
    }
    getFileNodes(fileKey, ids) {
        this.calls.push({
            endpoint: "file_nodes",
            detail: `${fileKey} ids=${ids.length}`,
        });
        const file = this.readJson("file.json");
        const nodes = {};
        for (const id of ids) {
            const found = findNode(file.document, id);
            nodes[id] = found
                ? {
                    document: found,
                    components: file.components,
                    componentSets: file.componentSets,
                    styles: file.styles,
                }
                : null;
        }
        return Promise.resolve({
            ok: true,
            data: {
                name: file.name,
                version: file.version,
                editorType: file.editorType ?? "figma",
                nodes,
            },
        });
    }
    getImageRenders(fileKey, ids, opts) {
        this.calls.push({
            endpoint: "image_renders",
            detail: `${fileKey} ids=${ids.length}`,
        });
        const images = {};
        for (const id of ids) {
            const file = `renders/${id.replace(/[:;]/g, "-")}.${opts.format}`;
            images[id] = existsSync(join(this.dir, file))
                ? `fixture://${file}`
                : null;
        }
        return Promise.resolve({ ok: true, data: { err: null, images } });
    }
    getImageFills(fileKey) {
        this.calls.push({ endpoint: "image_fills", detail: fileKey });
        return Promise.resolve({
            ok: true,
            data: this.readJson("image-fills.json"),
        });
    }
    getLocalVariables(fileKey) {
        this.calls.push({ endpoint: "variables", detail: fileKey });
        if (!existsSync(join(this.dir, "variables.json"))) {
            // 実 API の plan 制限（Enterprise 限定）と同じ挙動を再現する
            return Promise.resolve({
                ok: false,
                kind: "forbidden",
                status: 403,
                message: "Variables API is available to full members of Enterprise orgs",
            });
        }
        return Promise.resolve({
            ok: true,
            data: this.readJson("variables.json"),
        });
    }
    download(url) {
        this.calls.push({ endpoint: "download", detail: url });
        if (!url.startsWith("fixture://")) {
            return Promise.resolve({
                ok: false,
                kind: "not_found",
                status: 404,
                message: `fixture 外の URL はダウンロードできません: ${url}`,
            });
        }
        const rel = url.slice("fixture://".length);
        const abs = join(this.dir, rel);
        if (!existsSync(abs)) {
            return Promise.resolve({
                ok: false,
                kind: "not_found",
                status: 404,
                message: `fixture ファイルがありません: ${rel}`,
            });
        }
        return Promise.resolve({ ok: true, data: readFileSync(abs) });
    }
}
export function findNode(root, id) {
    if (root.id === id)
        return root;
    for (const child of root.children ?? []) {
        const found = findNode(child, id);
        if (found)
            return found;
    }
    return null;
}
