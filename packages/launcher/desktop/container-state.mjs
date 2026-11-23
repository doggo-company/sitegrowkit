// ビルド等の一時コンテナを、サイトの常駐サービスの健康判定に混ぜない。
export function serviceContainers(output) {
  const raw=output.trim();
  const containers=raw.startsWith('[')?JSON.parse(raw):raw.split('\n').filter(Boolean).map(line=>JSON.parse(line));
  return containers.filter(container=>{
    const labels=container.Labels;
    const oneOff=typeof labels==='string'
      ? /(?:^|,)com\.docker\.compose\.oneoff=true(?:,|$)/i.test(labels)
      : String(labels?.['com.docker.compose.oneoff']).toLowerCase()==='true';
    return !oneOff;
  });
}
