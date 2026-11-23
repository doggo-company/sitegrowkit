import { runCapture } from "./run.js";
export function classifyComposeFailure(output) {
    if (/all predefined address pools have been fully subnetted/i.test(output))
        return "network_pool";
    if (/port is already allocated|address already in use/i.test(output))
        return "port";
    if (/Cannot connect to the Docker daemon|docker daemon is not running|error during connect/i.test(output))
        return "daemon";
    return "unknown";
}
/**
 * このパソコンにある SiteGrowKit の試作環境（compose のプロジェクト名が agentcms- で始まるもの）。
 * **一覧を出すだけで、止めたり消したりはしない**（どれを止めるかは本人が選ぶ）。
 */
export function listSiteGrowKitEnvironments(run = runCapture) {
    const r = run("docker", [
        "ps",
        "-a",
        "--filter",
        "label=com.docker.compose.project",
        "--format",
        '{{.Label "com.docker.compose.project"}}\t{{.Label "com.docker.compose.project.working_dir"}}\t{{.State}}',
    ]);
    if (r.code !== 0)
        return [];
    const byProject = new Map();
    for (const line of r.stdout.split("\n")) {
        const [project = "", folder = "", state = ""] = line.split("\t");
        if (!project.startsWith("agentcms-"))
            continue;
        const seen = byProject.get(project);
        // 1 つでも動いていれば running
        if (!seen || state === "running")
            byProject.set(project, { project, folder, state });
    }
    return [...byProject.values()].sort((a, b) => a.project.localeCompare(b.project));
}
/** 原因ごとの、画面に出す言葉 */
export function composeFailureMessage(cause, environments, currentProject) {
    if (cause === "network_pool") {
        const others = environments.filter((e) => e.project !== currentProject);
        const list = others.length
            ? others
                .map((e) => `・${e.folder || e.project}（${e.state === "running" ? "動いています" : "止まっています"}）`)
                .join("\n")
            : "・（ほかの試作環境は見つかりませんでした）";
        return {
            title: "Docker の内部ネットワークの空きがありません",
            message: "このパソコンで動いている（または止めたままの）SiteGrowKit の試作環境が多く、Docker が新しいサイト用のネットワークを作れませんでした。" +
                "使っていない試作環境を選んで、そのフォルダで `agentcms stop` を実行すると空きます（中身は消えません）。こちらでは自動で止めません。\n" +
                list,
            fix: "使っていない試作環境のフォルダで `agentcms stop` を実行してから、もう一度お試しください",
        };
    }
    if (cause === "port") {
        return {
            title: "使おうとした番号（ポート）がほかで使われています",
            message: "ほかのアプリか、別の SiteGrowKit の試作環境が同じ番号を使っています。使っていない試作環境を `agentcms stop` で止めるか、.env の *_PORT を変えてから、もう一度お試しください。",
            fix: "使っていない試作環境を止めるか、.env の *_PORT を変更してください",
        };
    }
    if (cause === "daemon") {
        return {
            title: "Docker に繋がりませんでした",
            message: "Docker Desktop が起動しているか確認してから、もう一度お試しください。",
            fix: "Docker Desktop を起動してから再実行してください",
        };
    }
    return {
        title: "サイトの土台を起動できませんでした",
        message: "Docker Desktop が起動しているか確認してから、もう一度お試しください。詳しい原因は「詳細を見る」のログにあります。",
        fix: "`agentcms doctor` で環境を診断してください。ポート衝突の場合は .env の *_PORT を変更できます",
    };
}
