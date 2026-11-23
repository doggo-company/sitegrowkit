import {test} from 'node:test';
import assert from 'node:assert/strict';
import {serviceContainers} from '../desktop/container-state.mjs';
// 実機で残った compose run の created コンテナが、稼働中表示を妨げた事例。
test('一時コンテナを除外し、停止・不健康な常駐サービスは保持する',()=>{
 const rows=[
  {Service:'app',State:'running',Health:'healthy',Labels:'com.docker.compose.oneoff=False'},
  {Service:'node',State:'created',Labels:'project=qa,com.docker.compose.oneoff=True,service=node'},
  {Service:'web',State:'exited',Labels:'com.docker.compose.oneoff=False'},
  {Service:'mysql',State:'running',Health:'unhealthy',Labels:{}},
 ];
 assert.deepEqual(serviceContainers(rows.map(r=>JSON.stringify(r)).join('\n')), [rows[0],rows[2],rows[3]]);
 assert.deepEqual(serviceContainers(JSON.stringify(rows)),[rows[0],rows[2],rows[3]]);
});
test('ラベルがオブジェクトでも一時コンテナを除外する',()=>{
 assert.deepEqual(serviceContainers(JSON.stringify([{Labels:{'com.docker.compose.oneoff':'True'}}])),[]);
 assert.deepEqual(serviceContainers(''),[]);
});
