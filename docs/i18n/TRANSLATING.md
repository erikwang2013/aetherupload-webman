# 翻译作业说明（i18n）

本目录是 `README.md` 的多语言版本与三张设计图的翻译版。**新增或更新一门语言时照本文档做，别自由发挥。**
唯一的源语言是仓库根目录的 `README.md`（中文）；译文必须以它为准，不要凭记忆补内容、也不要删章节。

## 产物（一门语言五个文件）

| 文件 | 说明 |
|---|---|
| `docs/i18n/README.<lang>.md` | `README.md` 全文译文 |
| `docs/i18n/strings/<lang>.json` | 三张图的文案表（按图分节，值填译文） |
| `docs/i18n/img/aetherupload-architecture.<lang>.svg` | 架构图 |
| `docs/i18n/img/aetherupload-design.<lang>.svg` | 设计思路图 |
| `docs/i18n/img/aetherupload-lifecycle.<lang>.svg` | 上传生命周期图 |

`<lang>` 用 ISO 639-1 两字母码：`en ko ru de fr es pt hi ar bn id ja`。
`docs/js/aetherupload-pet.svg`（项目宠物）里没有文字，**不需要**翻译，直接引用原件。

## 一、README 译文

### 1. 顶部语言导航

版式与根 `README.md` 保持一致：**H1 → 宠物图 → 本行导航 → 正文**。
把下面这一行原样插在宠物图那一行**之后**（**逐字照抄，包括中文那条**，13 个条目一条不少）：

```markdown
[中文](../../README.md) · [English](README.en.md) · [한국어](README.ko.md) · [Русский](README.ru.md) · [Deutsch](README.de.md) · [Français](README.fr.md) · [Español](README.es.md) · [Português](README.pt.md) · [हिन्दी](README.hi.md) · [العربية](README.ar.md) · [বাংলা](README.bn.md) · [Bahasa Indonesia](README.id.md) · [日本語](README.ja.md)
```

### 2. 要翻译 / 不要翻译

**翻译**：正文、标题、表格单元格、列表项、图片 `alt`、脚注、
**代码块里的中文注释**、项目结构树里的中文注解。

**不要翻译**：类名 / 方法名 / 文件名 / 路径 / 目录名 / URL / 命令 / 配置键 / JSON 键 /
HTTP 头名字 / `php webman aetherupload:groups` 这类命令行 / 代码本身。
代码块里只动注释，代码一个字符都别改。

### 3. 路径要改（译文在 `docs/i18n/` 下，比原文深两层）

| 原文里的引用 | 译文里改成 |
|---|---|
| `docs/js/aetherupload-pet.svg` | `../js/aetherupload-pet.svg` |
| `docs/aetherupload-architecture.svg` | `img/aetherupload-architecture.<lang>.svg` |
| `docs/aetherupload-design.svg` | `img/aetherupload-design.<lang>.svg` |
| `docs/aetherupload-lifecycle.svg` | `img/aetherupload-lifecycle.<lang>.svg` |
| `docs/HARNESS.md` / `docs/REPORT.md` | `../HARNESS.md` / `../REPORT.md` |

例外：**项目结构树代码块里的路径保持原样**（那是在描述仓库布局，不是链接）。

**另有 3 处要译**：代码块里 `/项目根目录的绝对路径/`（crontab 两条 + nginx `alias` 一条）。
它不是真实路径而是给人看的占位符 —— 留着中文，读者不知道要替换成什么。
按目标语言改写成同一含义的占位路径即可（`/absolute/path/to/project-root/`、
`/chemin/absolu/vers/la/racine/du/projet/`、`/プロジェクトルートの絶対パス/` …），
其余代码字符仍然一个都不许动。

### 4. 页内锚点跟着标题走

原文有一处页内跳转 `[支持的框架](#支持的框架)`。译文标题变了，锚点必须跟着改。
GitHub 的锚点算法：标题转小写 → 去掉除字母/数字/空格/连字符/下划线以外的字符 → 空格换成 `-`。
（中文、日文、韩文、俄文、印地文、阿拉伯文、孟加拉文的字符都保留，不做音译。）

## 二、三张图

文案表由脚本从 SVG 里抽出，见 `docs/i18n/strings/_source.json`：三个节
`architecture`(48 条) / `design`(45 条) / `lifecycle`(41 条)，值是空的，把它们填上译文。

```bash
# 1) 复制模板，填三个节
cp docs/i18n/strings/_source.json docs/i18n/strings/<lang>.json

# 2) 逐张生成（第三参数是节名；缺任何一条原文都会以非 0 退出）
php docs/i18n/tools/svg-text.php apply docs/aetherupload-architecture.svg docs/i18n/strings/<lang>.json docs/i18n/img/aetherupload-architecture.<lang>.svg architecture
php docs/i18n/tools/svg-text.php apply docs/aetherupload-design.svg       docs/i18n/strings/<lang>.json docs/i18n/img/aetherupload-design.<lang>.svg       design
php docs/i18n/tools/svg-text.php apply docs/aetherupload-lifecycle.svg    docs/i18n/strings/<lang>.json docs/i18n/img/aetherupload-lifecycle.<lang>.svg    lifecycle
```

**在 SVG 里出现的两类文字要分开对待：**

1. **可见标签**（`<text>`）：受格子宽度限制，**越短越好**。译文明显比原文长时脚本会自动注入
   `textLength` 把字距压回原宽 —— 那就意味着挤，读起来难看。所以宁可换短词
   （例：`可挂中间件` → "Middleware" 而不是 "Middleware can be attached here"）。
2. **无障碍描述**（`<desc>`/`<title>`，含 `·` 分隔的复合串）：不给宽度，**写全**
   （例：`浏览器 · aetherupload-all.js · Architecture component · 算 md5 / 切片`）。

**键不能改**：脚本按原文精确匹配，键写错就是"缺这条"直接失败。改完用
`git diff --stat docs/i18n/img/` 看大小是否正常（译文不该比原文小太多，也不该大很多）。

## 三、硬性约束

- **只创建本文档列出的那五个文件**（README + strings + 3 张图）。不要动 `README.md`、
  别的语言的任何文件、`src/`、`tests/`、`config/`。
- **不要跑 git 命令**（不 add、不 commit、不 push）。
- 图表重命名/新增节点都不行：这轮只换文案，结构、几何、配色、`data-*` 钩子一律原样。
- 阿拉伯语不加 `dir="rtl"` 之类的包装（Markdown 里混排会坏），代码块保持原样即可。
- 术语一致性：同一个中文词在同一门语言里始终用同一个译法。技术词（webman、Redis、md5、
  `preprocess`、`chunk`…）优先保留行业通用写法，不要硬译。

## 四、已知不覆盖（不要自行修，也别当成缺陷）

图里 `aria-label`、`data-node-*` / `data-edge-*` 等**属性值**仍是中文：
`svg-text.php` 只重写 `<text>` / `<title>` / `<desc>` 的文本节点，属性级替换不在工具契约里。
这些属性在静态 SVG 里没有消费者（README 用 `<img src>` 引用，读屏读的是 `alt`），
所以本轮**有意不动**它们 —— 保住 `data-*` 钩子与源图逐字节一致，比翻一遍没人读的字符串更值。

## 五、交付时报告

1. 五个文件的路径与大小；
2. 三条 `apply` 命令各自的退出码与「注入 textLength 几处」；
3. 刻意保留原文/英文的地方及原因（例如某个术语没有通行译法）；
4. 你没做到或不确定的部分，**明说**，别糊过去。
