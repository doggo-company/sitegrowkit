"""
JMdict（EDRDG・CC BY-SA 4.0）から、カタカナ語 → 英語表記の表を作る（UX 一周 20 周目 R2）。

**翻訳はしない。** その語の元の英語（lsource・英訳の中から、カタカナの音と子音の並びが 6 割以上合うもの）だけを使う
（トリミング → trimming・パン → pan、パンを bread にはしない）。合うものが無い語は表に入れず、音のローマ字になる。

使い方（kit の repo で）:

    python3 scripts/build-katakana-english.py --fetch            # 最新の JMdict_e を取って作り直す
    python3 scripts/build-katakana-english.py --source <JMdict_e.gz>
    python3 scripts/build-katakana-english.py --source <JMdict_e.gz> --check   # 同梱の表と同じものができるか

書き出す先（template/core/resources/reading/）:
- katakana-english.tsv.gz — 表（同じ入力からは同じバイト列）
- katakana-english.json — 元にした JMdict の版の日付・sha256、表の sha256。**版を切るとき package-release.mjs が見る**
  （EDRDG の許諾は新しい版から定期的に作り直すことを求める。古い表のままでは版を切れない・scripts/jmdict-freshness.mjs）
- katakana-english.NOTICE — JMdict の許諾（版の日付を書き換える）。配るときは必ず一緒に
"""
import argparse, datetime, difflib, gzip, hashlib, io, json, os, re, sys, tempfile, unicodedata, urllib.request, xml.etree.ElementTree as ET

ROOT = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
OUT = os.path.join(ROOT, 'template', 'core', 'resources', 'reading')
TABLE = os.path.join(OUT, 'katakana-english.tsv.gz')
META = os.path.join(OUT, 'katakana-english.json')
NOTICE = os.path.join(OUT, 'katakana-english.NOTICE')
SLUG_JS = os.path.join(ROOT, 'template', 'resources', 'js', 'lib', 'reading-slug.mjs')
JMDICT_URL = 'http://ftp.edrdg.org/pub/Nihongo/JMdict_e.gz'

ap = argparse.ArgumentParser(description='JMdict からカタカナ語 → 英語の表を作る')
g = ap.add_mutually_exclusive_group(required=True)
g.add_argument('--source', help='JMdict_e.gz の場所')
g.add_argument('--fetch', action='store_true', help=f'最新の JMdict_e.gz を {JMDICT_URL} から取って作る')
ap.add_argument('--check', action='store_true', help='書き出さずに、同梱の表と同じものができるかだけ見る（決定的か）')
args = ap.parse_args()

src = open(SLUG_JS, encoding='utf-8').read()
def table(name):
    block = src[src.index(f"const {name} = {{"): src.index("};", src.index(f"const {name} = {{"))]
    out = {}
    for line in block.split("\n")[1:]:
        m = re.match(r"""\s*["']?([^"':\s]+)["']?\s*:\s*["']([^"']*)["']""", line)
        if m: out[m.group(1)] = m.group(2)
    return out
BASE, PAIRS = table("BASE"), table("PAIRS")
def romanize(kana):
    text = ''.join(chr(ord(c) - 0x60) if 'ァ' <= c <= 'ヶ' else c for c in kana)
    out, gem, i = '', False, 0
    while i < len(text):
        ch = text[i]; pair = PAIRS.get(text[i:i+2]); r = pair or BASE.get(ch)
        if ch == 'っ': gem = True; i += 1; continue
        if ch == 'ー': i += 1; continue
        if r:
            out += ((('t' if r.startswith('ch') else r[0]) + r) if gem else r); gem = False
            i += 2 if pair else 1; continue
        gem = False; i += 1
    return out
def skeleton(s):
    s = unicodedata.normalize('NFKD', s.lower())
    s = ''.join(c for c in s if c.isascii())
    for a, b in [('ph','f'),('h','f'),('th','s'),('sh','s'),('ch','s'),('ts','s'),('ck','k'),('c','k'),('q','k'),('x','ks'),('l','r'),('v','b'),('j','z'),('dz','z')]:
        s = s.replace(a, b)
    s = re.sub(r'[aeiouy\W_\d]', '', s)
    return re.sub(r'(.)\1+', r'\1', s)
def ascii_word(t):
    t = unicodedata.normalize('NFKD', t)
    t = ''.join(c for c in t if c.isascii()).strip()
    return t if re.match(r"^[A-Za-z][A-Za-z' -]{0,29}$", t) and len(t.split()) <= 3 else None
KATA = re.compile(r'^[ァ-ヶー]+$')
best = {}
if args.fetch:
    tmp = tempfile.mkdtemp(prefix='jmdict-')
    source = os.path.join(tmp, 'JMdict_e.gz')
    urllib.request.urlretrieve(JMDICT_URL, source)
else:
    source = args.source

created = None
with gzip.open(source, 'rt', encoding='utf-8') as f:
    for line in f:
        m = re.search(r'<!-- JMdict created: (\d{4}-\d{2}-\d{2}) -->', line)
        if m:
            created = m.group(1)
            break
        if '<entry>' in line:
            break
if created is None:
    sys.exit('JMdict の版の日付（JMdict created）が見つかりません')

for ev, el in ET.iterparse(gzip.open(source), events=('end',)):
    if el.tag != 'entry': continue
    if True:  # 漢字の表記がある語でも、カタカナの読み（コーヒー・珈琲）は外来語
        # 出所の言語（lsource の xml:lang。書いていなければ英語）。英語でない語（パン ← pão・アルバイト ← Arbeit）は、
        # 英語でも同じ語として通るとき（カフェ → cafe）だけ英語にし、訳語（パン → bread）は使わない（UX 一周 21 周目 Y2）。
        # 英語にできなければ音のローマ字。同じ見出しに英語の別の語があっても（パン ← pan・カメラを振る）、よく使う方で決める
        sources = []
        for s in el.findall('sense'):
            for ls in s.findall('lsource'):
                if ls.get('ls_type') == 'part':
                    continue
                sources.append((ls.get('{http://www.w3.org/XML/1998/namespace}lang', 'eng'), ls.text or ''))
        glosses = []
        for s in el.findall('sense'):
            for g in s.findall('gloss'):
                w = ascii_word(re.sub(r'\s*\(.*?\)', '', g.text or ''))
                if w: glosses.append(w)
        foreign = bool(sources) and all(lang != 'eng' for lang, _ in sources)
        if foreign:
            # 訳語のうち、音が合うもの（＝英語でも同じ語: café → cafe・yoga）だけ。訳しただけの語（bread）は音が合わず外れる
            cands = glosses
        else:
            cands = [w for w in (ascii_word(text) for lang, text in sources if lang == 'eng') if w] + glosses
        for r in el.findall('r_ele'):
            reb = r.findtext('reb'); pri = r.find('re_pri') is not None
            if not reb or not KATA.match(reb): continue
            sk = skeleton(romanize(reb))
            if len(sk) < 1: continue
            top = None
            for c in cands:  # 同点なら先に載っている方（元の語・最初の意味）
                score = difflib.SequenceMatcher(None, sk, skeleton(c)).ratio()
                if top is None or score > top[0]:
                    top = (score, c)
            if top is None or top[0] < 0.6:
                if not foreign:
                    continue
                # 英語でない語で、英語にできない: 音で読む印（同じ見出しの英語の語より、よく使う方を取る）
                eng, score = None, 0.0
            else:
                eng = re.sub(r'[^a-z0-9]+', '-', top[1].lower().replace("'", '')).strip('-')
                score = top[0]
            cur = best.get(reb)
            if cur is None or (pri and not cur[1]) or (pri == cur[1] and score > cur[2]):
                best[reb] = (eng, pri, score)
    el.clear()
# JMdict ent_seq 1075040 / 1104720 / 2451340 / 1065940 の英語表記。
# 短い語・語尾の r・長音を含む借用語は子音照合だけでは拾えない（25周目 #5・26周目 #4）。
for word, english in {'センター': 'center', 'ビザ': 'visa', 'スムージー': 'smoothie', 'ジューサー': 'juicer'}.items():
    best[word] = (english, True, 1.0)

# 英語にできない英語でない語は、英語を空にして載せる（＝その語は音で読む。アルバイトを アルバ｜イト に切らない）
best = {k: (v[0] or '', v[1], v[2]) for k, v in best.items()}
rows = ''.join(f'{k}\t{v}\n' for k, (v, _, _) in sorted(best.items()))
buf = io.BytesIO()
# 同じ入力 → 同じバイト列（gzip の時刻 0・名前なし・並びは見出し順）
with gzip.GzipFile(filename='', fileobj=buf, mode='wb', compresslevel=9, mtime=0) as f:
    f.write(rows.encode('utf-8'))
data = buf.getvalue()
table_sha = hashlib.sha256(data).hexdigest()
with open(source, 'rb') as f:
    source_sha = hashlib.sha256(f.read()).hexdigest()

if args.check:
    have = hashlib.sha256(open(TABLE, 'rb').read()).hexdigest() if os.path.exists(TABLE) else None
    print(f'作り直した表 {table_sha}\n同梱の表     {have}')
    sys.exit(0 if have == table_sha else 1)

with open(TABLE, 'wb') as f:
    f.write(data)
meta = {
    'jmdict_created': created,
    'jmdict_source_sha256': source_sha,
    'table_sha256': table_sha,
    'words': len(best),
    'built_on': datetime.date.today().isoformat(),
}
with open(META, 'w', encoding='utf-8') as f:
    json.dump(meta, f, ensure_ascii=False, indent=2)
    f.write('\n')
notice = open(NOTICE, encoding='utf-8').read()
notice = re.sub(r'JMdict created: \d{4}-\d{2}-\d{2}', f'JMdict created: {created}', notice)
open(NOTICE, 'w', encoding='utf-8').write(notice)
print(f'{len(best)} 語・JMdict created {created}・{table_sha[:16]}')
