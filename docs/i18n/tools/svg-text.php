<?php
/**
 * SVG 文案抽取 / 回填工具（i18n 用）。
 *
 *   php svg-text.php extract <in.svg> <out.json>                  # 抽出全部可见文案
 *   php svg-text.php apply <in.svg> <map.json> <out.svg> [section]  # 按映射回填，其余字节原样保留
 *
 * 译文表按**图**分节（architecture / design / lifecycle），一份语言文件装三张图的文案；
 * apply 时用 section 取对应那一节。缺任何一条原文都返回非 0 —— 宁可失败也不要产出半张中英混杂的图。
 *
 * 为什么不用 DOMDocument：它会把 <style> 里的 CSS、自闭合标签、属性顺序全部重排，
 * 产出与原文无关的巨量 diff。这里只做「替换文本节点的内部内容」，
 * 文件其余部分逐字节不变 —— git diff 里能一眼看出改了哪些文案。
 *
 * textLength：渲染器是按**原文**实测宽度排版的，译文更长就会溢出宿主格子。
 * 估算宽度后按原宽注入 textLength + lengthAdjust，让译文自动挤进同一格。
 */

const NODE_RE = '#<(text|title|desc)\b([^>]*)>(.*?)</\1>#s';

/** 粗略字宽（em）：CJK / 全角按 1，其余按 0.55。够用于「比原文宽还是窄」的判断。 */
function estWidth(string $s): float
{
    $w = 0.0;

    foreach ( preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch ) {
        $w += preg_match('/[\x{1100}-\x{115F}\x{2E80}-\x{A4CF}\x{AC00}-\x{D7A3}\x{F900}-\x{FAFF}\x{FE30}-\x{FE4F}\x{FF00}-\x{FF60}\x{FFE0}-\x{FFE6}\x{20000}-\x{3FFFD}]/u', $ch) ? 1.0 : 0.55;
    }

    return $w;
}

function nodes(string $svg): array
{
    preg_match_all(NODE_RE, $svg, $m, PREG_SET_ORDER);

    return $m;
}

/**
 * SV G原文里本来就有实体（`&lt;md5&gt;.&lt;ext&gt;`）。键与节点内容统一先解码再比对，
 * 这样译文表里写 `&lt;md5&gt;` 还是 `<md5>` 都能命中；写回时统一重新转义，不会二次转义成 `&amp;lt;`。
 */
function decode(string $s): string
{
    return html_entity_decode(trim($s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function main(array $argv): int
{
    $mode = $argv[1] ?? '';

    if ( $mode === 'extract' ) {
        $svg = file_get_contents($argv[2]);
        $map = [];

        foreach ( nodes($svg) as $n ) {
            $text = decode($n[3]);

            if ( $text === '' ) {
                continue;
            }
            $map[$text] = '';   // 值由译者填
        }

        file_put_contents($argv[3], json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        fwrite(STDERR, count($map) . " strings → {$argv[3]}\n");

        return 0;
    }

    if ( $mode !== 'apply' ) {
        fwrite(STDERR, "usage: extract <in.svg> <out.json> | apply <in.svg> <map.json> <out.svg> [section]\n");

        return 2;
    }

    [$in, $mapFile, $out] = [$argv[2], $argv[3], $argv[4]];
    $section = $argv[5] ?? null;
    $svg = file_get_contents($in);
    $map = json_decode(file_get_contents($mapFile), true);

    if ( $section !== null ) {
        $map = is_array($map) ? ($map[$section] ?? null) : null;
    }

    if ( ! is_array($map) ) {
        fwrite(STDERR, "bad map file: $mapFile" . ($section !== null ? " (section: $section)" : '') . "\n");

        return 1;
    }

    $missing = [];
    $fitted = 0;

    // 查表前把键也解码：译文表里键写成 `&lt;md5&gt;`（从 SVG 里抄的）或 `<md5>`（人写的）都能命中
    $lookup = [];

    foreach ( $map as $k => $v ) {
        $lookup[decode((string)$k)] = $v;
    }

    $translated = preg_replace_callback(NODE_RE, static function (array $m) use ($lookup, &$missing, &$fitted) {
        $text = decode($m[3]);

        if ( $text === '' || ! array_key_exists($text, $lookup) ) {
            if ( $text !== '' ) {
                $missing[] = $text;
            }

            return $m[0];
        }

        $new = (string)$lookup[$text];

        if ( $new === '' ) {
            return $m[0];   // 未翻译的留原文，便于事后 grep 出来
        }

        $attrs = $m[2];

        // 只有可见文本需要控宽：title/desc 是给读屏与工具用的，加了反而迷惑
        if ( $m[1] === 'text' && strpos($attrs, 'textLength') === false && estWidth($new) > estWidth($text) ) {
            $attrs .= sprintf(' textLength="%d" lengthAdjust="spacingAndGlyphs"', (int)round(estWidth($text) * (float)attrFontSize($attrs)));
            $fitted++;
        }

        return '<' . $m[1] . $attrs . '>' . htmlspecialchars($new, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</' . $m[1] . '>';
    }, $svg);

    if ( $missing !== [] ) {
        fwrite(STDERR, 'MISSING ' . count($missing) . " strings:\n  " . implode("\n  ", array_slice(array_unique($missing), 0, 10)) . "\n");

        return 1;
    }

    file_put_contents($out, $translated);
    // 注意：变量后面紧跟中文时必须用 {} 包起来 —— PHP 的标识符允许 \x80-\xff 字节，
    // 写成 "$out（…" 会把全角括号当成变量名的一部分，静默打印成空串。
    fwrite(STDERR, "wrote {$out}（注入 textLength {$fitted} 处）\n");

    return 0;
}

/** 从 <text> 属性串里取 font-size，取不到按 11（渲染器主字号） */
function attrFontSize(string $attrs): float
{
    return preg_match('/font-size="([\d.]+)"/', $attrs, $m) ? (float)$m[1] : 11.0;
}

exit(main($argv));
