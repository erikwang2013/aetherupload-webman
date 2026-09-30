# S3 兼容厂商接入对照表

本表为「`storage.driver = s3`」提供逐厂商的接入参数参考。**一个内置的零依赖 S3 客户端 + 三个配置键就能接入下表全部厂商**：

```php
'storage' => [
    'driver' => 'local',                  // local（默认）| s3
    's3' => [
        'endpoint'   => '',               // 各家端点（见本表）；留空 = AWS 默认（按 region 推导）
        'region'     => 'us-east-1',      // 签名用 region；多数厂商不校验具体值，但个别会（见注）
        'bucket'     => '',
        'access_key' => '',
        'secret_key' => '',
        'path_style' => true,             // 我们的默认；企业自建与多数自托管需要它，三家中国云需要 false
        'prefix'     => '',
        'multipart_threshold' => 104857600, // 超此大小走 multipart（分片固定 64MB）
        'payload_signing' => 'hash',      // hash（默认）| unsigned（华为云 OBS 等需要）
    ],
],
```

**置信度图例**：`官方` = 厂商官方文档核实；`二手` = 集成文档 / 社区实测；`推断` = 未验证的模式推断。核验时间：2026-10。厂商政策与端点会变，接入前建议以厂商现行文档复核。

## 30 秒上手（四个典型）

```php
// AWS（最通用）
'endpoint' => '', 'region' => 'ap-southeast-1', 'path_style' => true,

// 阿里云 OSS（S3 兼容端点；注意：仅 virtual-hosted，path_style 必须 false）
'endpoint' => 'https://s3.oss-cn-hangzhou.aliyuncs.com', 'region' => 'cn-hangzhou', 'path_style' => false,

// MinIO / Ceph 自托管（自填端点，可带端口）
'endpoint' => 'https://minio.example.com:9000', 'region' => 'us-east-1', 'path_style' => true,

// 华为云 OBS（仅 virtual-hosted + 只接受 UNSIGNED-PAYLOAD）
'endpoint' => 'https://obs.cn-north-4.myhuaweicloud.com', 'region' => 'cn-north-4', 'path_style' => false, 'payload_signing' => 'unsigned',
```

## 一、全球公有云

| 厂商 | endpoint 模板 | region 约定 | path_style | 签名 / 兼容注意 | 来源 | 置信度 |
|---|---|---|---|---|---|---|
| **AWS** | 留空即可（按 region 推导）；显式写法 `s3.{region}.amazonaws.com` | 标准 AWS region（`ap-southeast-1` 等） | 两种都支持（我们默认 true 可用） | SigV4；`region` 必须与桶所在区域一致 | [AWS S3 endpoints](https://docs.aws.amazon.com/general/latest/gr/s3.html) | 官方 |
| **Google Cloud (GCS)** | `https://storage.googleapis.com` | 任意非空（互通层不校验具体值） | 两种皆可 | SigV4 + **HMAC 密钥**（需在控制台开启 interoperability；建议用服务账号密钥，`GOOG` 前缀；用个人账号密钥上传常报 403 SignatureDoesNotMatch）。**multipart 限 32 片** → 64MB 分片下 >2GB 对象请把 `multipart_threshold` 调到 ≥ 文件大小（≤5GB 走单次 PUT） | [GCS interoperability](https://docs.cloud.google.com/storage/docs/interoperability) | 官方；32 片限制为二手 |
| **IBM Cloud (COS)** | `s3.{region}.cloud-object-storage.appdomain.cloud`（另有 `.private.`/`.direct.` 变体） | `us-south` `us-east` `eu-gb` `eu-de` `au-syd` `jp-tok` `jp-osa` `ca-tor` `br-sao` `eu-es` `ca-mon` `in-che` `in-mum` 等；跨区 `us`/`eu`/`ap`；单站点码 `ams03` `sjc04` `hkg02` 等 | 两种皆可 | SigV4；端点须与桶位置匹配 | [IBM COS endpoints](https://cloud.ibm.com/docs/cloud-object-storage) | 官方 |
| **Oracle Cloud (OCI)** | `https://{namespace}.compat.objectstorage.{region}.oraclecloud.com`（新域亦见 `.oci.customer-oci.com`） | OCI region（如 `us-ashburn-1`）；签名 region 与端点一致 | **path-style 为主**（`endpoint` 必须含 namespace）；新版支持 vhost 但前缀特殊 `{bucket}.vhcompat...` | SigV4；务必 **disableChunkedEncoding**（本客户端签真实 payload，天然满足）；AWS CLI 2.23.5+ 需 `AWS_REQUEST_CHECKSUM_CALCULATION=when_required` | [OCI S3 compat](https://docs.oracle.com/en-us/iaas/Content/Object/s3-virtual-style.htm) | 官方 |
| **Cloudflare R2** | `https://{account}.r2.cloudflarestorage.com` | `auto`（R2 忽略签名 region，任意非空可） | **建议 true**（社区多例报告 vhost 报 403；我们默认 true 直接可用） | SigV4；不以 ACL（不支持 ACL）；S3 令牌在面板生成 | [R2 S3 API](https://developers.cloudflare.com/r2/api/s3/api/) | 官方 + 二手 |
| **Wasabi** | `s3.{region}.wasabisys.com`（US East 1 亦可用 `s3.wasabisys.com`） | `us-east-1` `us-east-2` `us-west-1` `us-west-2` `us-central-1` `ca-central-1` `eu-west-1/2/3` `eu-central-1/2` `eu-south-1` `ap-northeast-1/2` `ap-southeast-1/2` | 两种皆可 | SigV4；**端点必须与桶 region 一致**，否则 SignatureDoesNotMatch / AuthorizationHeaderMalformed | [Wasabi service URLs](https://docs.wasabi.com/docs/service-urls-for-wasabis-storage-regions) | 官方 |
| **Backblaze B2** | `s3.{region}.backblazeb2.com`（如 `s3.us-west-004.backblazeb2.com`） | 账号级区域 `us-west-002/004` `us-east-005` `eu-central-003` 等；**端点必须带 region**（无 region 的 `s3.backblazeb2.com` 会被部分工具拒绝） | 两种皆可（社区两说；默认 true 可用） | **仅 SigV4**（不支持 V2）；必须 HTTPS；用 application key（非 master key） | [B2 S3 API](https://www.backblaze.com/apidocs/introduction-to-the-s3-compatible-api) | 官方；寻址风格二手 |
| **DigitalOcean Spaces** | `{region}.digitaloceanspaces.com` | `nyc3` `sfo2/sfo3` `ams3` `sgp1` `lon1` `fra1` `tor1` `blr1` `syd1` `atl1` 等；**SDK 的 region 填 `us-east-1` 等任意 AWS 名**（真实区域由端点决定） | 两种皆可（官方示例以 vhost 为主） | SigV4 | [DO Spaces + AWS SDK](https://docs.digitalocean.com/products/spaces/how-to/use-aws-sdks/) | 官方 |
| **IDrive e2** | `s3.{region}.idrivee2.com`（如 `s3.us-east-1.idrivee2.com`；面板会给账号专属端点的等价形态） | `us-west-1..4` `us-southwest-1` `us-midwest-1` `us-central-1` `us-east-1` `us-southeast-1` `ca-east-1` `eu-west-1..4` `eu-central-1/2` `eu-south-1` `ap-southeast-1` `ap-northeast-1` | 未逐一核实（默认 true 可用） | SigV4；区域级访问密钥 (每区域可独立启用) | [IDrive e2 endpoints](https://storage.idrive.com/s3-storage-e2/e2-endpoint-urls) | 官方 |
| **Seagate Lyve Cloud** | `s3.{region}.lyvecloud.seagate.com` | `us-east-1` `us-west-1` `us-central-1/2` `eu-west-1` `ap-southeast-1`；**无全局端点** | 两种皆可（官方明示） | SigV4 | [Lyve Cloud S3 endpoints](https://www.seagate.com/manuals/lyve-cloud/s3-storage/s3-api-endpoints/) | 官方 |
| **Akamai（含原 Linode Object Storage）** | `{cluster}.linodeobjects.com`（如 `us-east-1.linodeobjects.com`；18 个机房） | 区域从端点推导（`nl-ams-1` `us-ord-1` `eu-central-1` `jp-osa-1` `fr-par-1` `se-sto-1` 等）；**配置里的 region 一般被忽略** | 两种皆可（官方 vhost 模板 `%(bucket)s.{cluster}...`） | SigV4 | [Linode Object Storage URLs](https://www.linode.com/docs/products/storage/object-storage/guides/urls/) [Akamai s3cmd](https://techdocs.akamai.com/cloud-computing/docs/using-s3cmd-with-object-storage) | 官方 |
| **Vultr Object Storage** | `{region}.vultrobjects.com`（如 `ewr1.vultrobjects.com`；另有 `ams1/2` `sjc1` `sgp1` 等） | **Vultr 忽略该值**（boto3 惯例从主机名取 `hostname.split('.')[0]`） | 两种皆可（vhost 模板 `%(bucket)s.{region}...`） | SigV4 | [Vultr s3cmd](https://docs.vultr.com/how-to-use-s3cmd-with-vultr-object-storage) | 官方 |
| **Fastly Object Storage** | `https://{region}.object.fastlystorage.app` | `us-west` `us-central-1` `us-east` `uk-east-1` `eu-central` `eu-south-1` `jp-central-1` `au-east-1` | **建议 true**（官方示例 path-style） | SigV4；**不支持 chunked 上传**（multipart 支持，≥5MB/片）；ETag ≠ MD5（勿以 ETag 校验内容）；不提供公开匿名访问 | [Fastly Object Storage](https://www.fastly.com/documentation/guides/platform/object-storage/) [AWS CLI 指南](https://www.fastly.com/documentation/guides/platform/object-storage/aws-cli-for-fastly-object-storage/) | 官方 |
| **Cubbit DS3** | `https://s3.cubbit.eu`（租户形态 `s3.{tenant}.cubbit.eu`；自建 Composer 网关则填网关域名） | `eu-west-1`（可留空） | 两种皆可（官方建议 vhost；部分工具实测 path 亦通） | SigV4（V2 亦支持）；注意旧文档里的 `s3.cubbit.io` 是错误主机名，用 `s3.cubbit.eu` | [Cubbit Docs](https://docs.cubbit.io/composer/gateway/how-to-install) + 社区实测 | 官方 + 二手 |

## 二、欧洲 / 亚太 / 中国云

| 厂商 | endpoint 模板 | region 约定 | path_style | 签名 / 兼容注意 | 来源 | 置信度 |
|---|---|---|---|---|---|---|
| **Exoscale (SOS)** | `https://sos-{zone}.exo.io`（如 `sos-ch-gva-2.exo.io`） | zone 即 region：`ch-gva-2` `ch-dk-2` `de-fra-1` `de-muc-1` `at-vie-1/2` `bg-sof-1` `hr-zag-1` | vhost 更稳（path 已 deprecated） | SigV4（region 槽填 zone） | [Exoscale AWS CLI 快速上手](https://community.exoscale.com/documentation/object-storage/s3-api-quickstart/) | 端点官方；path 二手 |
| **Hetzner** | `https://{region}.your-objectstorage.com`（如 `fsn1.your-objectstorage.com`） | `fsn1` `nbg1` `hel1` 等 location code 兼作签名 region（也接受 `auto`） | 两种皆可 | SigV4；**签真实 payload**（SDK 新默认的 aws-chunked/trailer checksum 会被拒——本客户端签真实哈希，天然满足） | [Hetzner Object Storage](https://docs.hetzner.com/storage/object-storage/overview/) | 端点官方；签名细节二手 |
| **IONOS** | `https://s3.{region}.ionoscloud.com`（如 `s3.eu-central-1.ionoscloud.com`） | `eu-central-1..4` `eu-south-2` `us-central-1` 等；端点须与桶 region 一致 | 官方未明确（vhost 为主，客户端有 Force Path Style 开关） | SigV4（官方称 V2/V4 均支持，实践建议 V4） | [IONOS S3 endpoints](https://docs.ionos.com/cloud/storage-and-backup/cloud-object-storage) | 端点官方；寻址未验证 |
| **DreamHost (DreamObjects)** | 新集群 `https://s3.us-east-005.dream.io`；老集群 `https://objects-us-east-1.dream.io`（迁移中） | 新 `us-east-005`；老 `us-east-1` | 未核实（Ceph 底座，默认 true 可用） | **新集群仅 SigV4** 且无桶级 public 权限；老集群支持 V2 但不支持 chunked | [DreamObjects 集群说明](https://help.dreamhost.com/hc/en-us/articles/32254965300372) | 官方；寻址未验证 |
| **ArvanCloud** | `https://s3.{region}.arvanstorage.ir`（`ir-thr-at1` / `ir-tbz-sh1`） | `ir-thr-at1` `ir-tbz-sh1` | path-style 客户端示例常见；官方两种 URL 形态都有出现 | SigV4（rclone 默认可 `--v2-auth` 回退）；私有文件签名 URL 官方示例为 V2 风格 query | [ArvanCloud Object Storage](https://docs.arvancloud.ir/en/object-storage/) | 端点官方；细节二手 |
| **Bizfly Cloud** | `https://hn.ss.bfcplatform.vn`（Hà Nội）/ `https://hcm.ss.bfcplatform.vn` | `hn` / `hcm` | **path-style**（官方 s3cmd 示例即 path） | SigV4（官方示例 `s3v4`；FAQ 称兼容 v2+v4） | [Bizfly Simple Storage](https://docs.bizflycloud.vn/simple_storage/howtos/client_tool/) | 官方 |
| **Sakura Internet** | `https://s3.{site}.sakurastorage.jp`：`s3.isk01.sakurastorage.jp`（石狩）`s3.tky01.sakurastorage.jp`（東京）`s3.arc02...`（归档） | `jp-north-1`（isk01）/ `jp-east-1`（tky01、arc02）；站点各有独立密钥 | 官方 AWS CLI 示例默认 vhost | SigV4；**S3 API 不支持建/删桶**（控制台建桶）；AWS CLI ≥2.23 需禁用 checksum 计算 | [Sakura 对象存储（isk01 官方）](https://knowledge.sakura.ad.jp/48152/) | isk01 官方；tky01/arc02 二手 |
| **Rakuten Cloud** | —— **服务已于 2023-12-31 终止**（对象存储随 OpenStack 线一并停服），无需接入 | — | — | — | [楽天 终止公告](https://comm.rakuten.co.jp/oshirase/20230131.html) | 官方 |
| **NIFTY Cloud（现 FJcloud-V）** | `https://jp-east-1.storage.api.nifcloud.com`、`https://jp-west-2.storage.api.nifcloud.com` | `jp-east-1` / `jp-west-2` | **仅 path-style** | SigV4（AWS4-HMAC-SHA256）；旧 NIFTY Cloud 品牌 2020 年终止，现由富士通 FJcloud-V 提供 | [FJcloud-V API 端点](https://pfs.nifcloud.com/api/endpoint.htm) | 端点官方；path 二手 |
| **Telefonica Cloud** | **未验证**（Telefónica Tech STaaS 为专属租户交付，无公开端点文档；历史 Open Cloud 为华为系，provider 已归档） | 未验证 | 未验证 | 未验证 | [Telefónica Tech STaaS 手册](https://telefonicatech.com/) | 未验证 |
| **阿里云 OSS** | `https://s3.oss-{region}.aliyuncs.com`（如 `s3.oss-cn-hangzhou.aliyuncs.com`；原生域 `oss-cn-hangzhou.aliyuncs.com` 亦可） | OSS region 形态 `cn-hangzhou`（**不带 `oss-` 前缀**），须与桶地域一致 | **必须 false（仅 virtual-hosted）**——path-style 会被拒（403 SecondLevelDomainForbidden） | **拒绝 aws-chunked**（AWS SDK V4 默认 chunked 会失败；本客户端签真实 payload，理论兼容、未实测）。必须用 OSS 的 AK/SK | [阿里云 OSS 兼容 S3 文档](https://www.alibabacloud.com/help/zh/oss/developer-reference/use-aws-sdks-to-access-oss) | 官方 |
| **腾讯云 COS** | `https://cos.{region}.myqcloud.com`（如 `cos.ap-guangzhou.myqcloud.com`；桶名为 `{name}-{appid}`） | 标准 AWS 风格 `ap-guangzhou` `ap-beijing` `ap-hongkong` `eu-frankfurt` 等 | **2024-01-01 后创建的桶必须 false**（path-style 报 403 PathStyleDomainForbidden；旧桶两种皆可但官方建议 virtual） | SigV4（官方各语言示例含 presigner）；社区报告 aws-sdk-go-v2 需 `signingName:"cos"`（二手，未在官方核实） | [腾讯 COS 支持 AWS S3 SDK](https://www.tencentcloud.com/document/product/436/75050) | 端点/寻址官方；signingName 二手 |
| **华为云 OBS** | `https://obs.{region}.myhuaweicloud.com`（如 `obs.cn-north-4.myhuaweicloud.com`；**端点不得带桶名**） | `cn-north-4` 等；region 与端点域名强绑定，签名 scope 须一致 | **必须 false（要求 virtual-hosted**，报 VirtualHostDomainRequired；部分局点已禁 path-style） | **SigV4 仅接受 `UNSIGNED-PAYLOAD` / `STREAMING-AWS4-HMAC-SHA256-PAYLOAD`** → 把 `payload_signing` 设为 `unsigned`；强制 `X-Amz-Content-Sha256` 头；不支持 ListMultipartUploads | [OBS FAQ](https://support.huaweicloud.com/obs_faq/obs_faq_0274.html) [签名说明](https://support.huaweicloud.com/obs_faq/obs_faq_0173.html) | 官方 |
| **中国移动云 EOS** | `https://eos-{region}.cmecloud.cn`（如 `eos-wuxi-1.cmecloud.cn`；~30 个地域） | `location_constraint` 形态 `wuxi1`（小写去横线，建桶用）；**SigV4 的 region 取值未验证** | 未验证（SDK 示例未显式设 path；内/外网域名不同） | 官方帮助中心称 REST API 支持 Signature V2(HMAC-SHA1) 与 V4(HMAC-SHA256)；node 示例用 v2 | [移动云对象存储签名文档](https://ecloud.10086.cn/op-help-center/doc/article/101016) | 签名官方；端点列表二手；其余未验证 |
| **青云 QingStor** | `https://s3.{zone}.qingstor.com`（如 `s3.pek3b.qingstor.com`） | QingCloud zone（`pek3b` 等）**代 AWS region** 参与 V4 签名 | 两种皆支持（官方两例并列） | SigV4 与 SigV2 均支持（header/query 两种签名）；官方声明"重要功能兼容，非 100% 覆盖" | [QingStor S3 兼容文档](https://docsv3.qingcloud.com/storage/object-storage/s3/intro/) | 官方 |

## 三、企业存储 / 自托管 / 备份

自建产品的端点由部署决定（常见带端口），`endpoint` 请填实际数据网地址 / VIP。

| 产品 | S3 兼容方式 | 端点形态 | path_style 与签名注意 | 来源 | 置信度 |
|---|---|---|---|---|---|
| **Dell ECS** | 原生 S3 API | 直连节点 `:9020`(HTTP) / `:9021`(HTTPS)；LB 前置则 80/443 | 两种皆可（vhost 需基础 URL + 通配 DNS/证书）；多数 SDK 建议 path-style；无 region 概念（建桶传 `Standard`） | [Dell KB 000014110](https://www.dell.com/support/kbdoc/en-us/000014110/connecting-to-ecs-with-s3-browser) | 官方 |
| **Dell ObjectScale**（ECS 后继） | 原生 S3 API | `EXTERNAL_ENDPOINT`（如 `10.55.66.77:9021`）；节点池 `:9020/9021` | 官方示例 `setPathStyleAccess(true)`；不支持 region（传标准值） | [ObjectScale Admin Guide](https://www.dell.com/support/manuals/en-sg/objectscale-product-family/objectscale_p_1_3_admin_guide/perform-an-s3-workload-using-the-s3-browser) | 官方 |
| **Hitachi Vantara HCP** | 原生（HS3，按命名空间启用） | `https://{tenant}.{hcp-domain}`（桶 = namespace） | path-style 常见（GitLab 生产配置）；**SignatureDoesNotMatch 时把 endpoint 从 namespace 换成 tenant**（已知坑）；不支持 IAM/STS | [HCP 架构指南](https://www.hitachivantara.com/en-us/pdf/architecture-guide/hcp-s3-with-ev-14-5.pdf) | 官方 + 二手 |
| **Hitachi VSP One Object** | 原生 S3 兼容（支持 Object Lock / S3 Tables） | `https://s3.{region}.{domain}`（部署时定义） | 未见 SigV4 特例 | [VSP One Object 文档](https://docs.hitachivantara.com/) | 官方（端口为二手） |
| **HPE Alletra Storage MP X10000** | 原生 S3/S3a | 前端数据网地址（`--endpoint-url={地址}`；固定端口未公开） | 凭据 = Object Service 用户名/密码（access/secret key）；R2 版有 S3 over RDMA 扩展（非标） | [HPE PSNow a00145978enw](https://www.hpe.com/psnow/doc/a00145978enw) | 官方 |
| **NetApp StorageGRID** | 原生 S3 REST API | 推荐 LB endpoint（示例 `https://192.0.2.5:10443`）；存储节点 LDR 默认 `:18082`(HTTPS)/`:18084`(HTTP) | 两种皆可（IP 配置默认 path-style，官方称 path-style 未来将弃用；vhost 需 Grid Manager 配域名+通配证书）；**接受 UNSIGNED-PAYLOAD 语义下的 x-amz-content-sha256**；不支持 `x-amz-security-token` | [StorageGRID 端口](https://docs.netapp.com/us-en/storagegrid-118/admin/summary-ip-addresses-and-ports-for-client-connections.html) | 官方 |
| **Pure Storage FlashBlade** | 原生 S3 API | 数据 VIP（`https://{data-vip}`，默认 443） | 集成示例一律 path-style；**不校验 region**（任意值可）；注意客户端从 hostname 反推 `s3.<region>.amazonaws.com` 的案例会失败 | [Pure 博客（Presto on FlashBlade）](https://blog.purestorage.com/purely-technical/presto-on-flashblade-s3/) | API 官方；寻址二手 |
| **Qumulo** | 原生 S3 API（需 `qq s3_modify_settings --enable`） | 任意节点 `:9000`（固定不可改；默认仅 HTTPS 自签证书） | **仅 path-style**（官方原文）；AWS CLI 需 `s3.addressing_style path` | [Qumulo S3 API](https://docs.qumulo.com/administrator-guide/s3-api/configuring-using-s3-api.html) | 官方 |
| **VAST Data** | 原生 S3（Element Store） | 集群 VIP；HTTPS `:443`（**客户端不要写端口**——写了会与 SigV4 冲突） | boto3 示例 `signature_version='s3v4'` + `addressing_style: 'path'`；region 任意非空（示例 `vast-1`） | [VAST boto3 文档](https://kb.vastdata.com/documentation/docs/connecting-to-the-boto3-client-interface-5) | 官方（页面 JS 渲染，经搜索摘要转述） |
| **XSKY XEOS** | 原生标准 S3 接口 | **无公开技术文档**（仅营销材料/招股书/认证列表） | 未验证 | 港交所招股书 / 官方测试报告 | 二手 |
| **Cloudian HyperStore** | 原生 S3 API（有独立 AWS API 支持文档） | `s3-{region}.{domain}`（或 `s3.{region}.{domain}`）；S3 服务 `:80/:443` | 通配 DNS `*.s3-{region}.{domain}` 才支持 vhost，**无通配则 path-style**；域名不要含 "s3"、不要用 IP 作 endpoint | [HyperStore 管理指南 v8.2](https://cloudian.com/wp-content/themes/merged/hyperstore-help/8.2.1/) | 官方 |
| **Scality RING** | 官方 S3 Connector 组件（同门 ARTESCA 为轻量 S3-only） | Connector + LB，对外 `:80/:443` | SigV4 与 SigV2 均支持；完整 AWS IAM 模型 | [Scality RING7 datasheet](http://storage.scality.com/rs/963-KAI-434/images/Scality%20RING7%20Data%20Sheet.pdf) | SigV4/IAM 官方；端口二手 |
| **Commvault** | **双重身份**：(a) S3 客户端（官方维护 40+ 家兼容厂商列表）；(b) 自营 S3 端点 Unified Data Vault（2026-01 起 EA，GA 目标 2026 春，无公开 endpoint） | 视身份而定 | 未取得 GA 状态细节 | [Commvault 支持列表](https://documentation.commvault.com/v11/software/supported_s3_compatible_object_storage_vendors.html) | 列表官方；UDV 未核实 |
| **EVault** | **现代产品线不提供** S3 兼容存储；历史 LTS2（2013，Swift 底座）曾提供 S3+Swift API；品牌 2017 并入 Carbonite | 无 | — | AVPreserve 档案 / Commvault 列表 | 二手 |
| **Flexential** | Object-Based Storage 宣称兼容 Amazon S3 API（同时转售 Wasabi 归档） | 无公开 endpoint（销售获取） | 无公开的 SigV4/寻址细节 | [Flexential OBS datasheet](https://www.flexential.com/system/files/file/2020-03/object-based-storage.pdf) | 二手（端点为未核实） |
| **11:11 Systems（原 iland）** | 11:11 Cloud Object Storage，官方称 fully S3-compatible；Veeam Ready 条目注明 **不支持 IAM/STS** | 无公开 endpoint（控制台管理） | 无公开细节 | [11:11 产品页](https://1111systems.com/services/object-storage/) | 官方定性；端点未核实 |
| **MinIO** | 原生（事实标准） | `http://host:9000`（默认，Console 9001） | **默认 path-style**；vhost 需 `MINIO_DOMAIN` + DNS；region 任意非空（默认 us-east-1） | MinIO 官方文档 | 官方 |
| **Ceph RGW** | 原生 radosgw | CivetWeb 缺省 `:7480`；Ansible 默认 `:8080`；Beast `:8080`/SSL `:443` | 两种皆可（vhost 需 `rgw_dns_name` + 通配 DNS/证书）；SigV4 与 SigV2 均支持，支持 UNSIGNED-PAYLOAD 与 STREAMING 签名 | [Ceph radosgw config-ref](https://docs.ceph.com/en/latest/radosgw/config-ref/) | 官方 |
| **SeaweedFS** | 原生 `weed s3` 网关 | 默认 `:8333` | **必须 path-style**（不提供 per-bucket 子域）；region 不校验；HTTPS 需反代终止 + `-s3.externalUrl`，否则签名校验失败 | SeaweedFS 仓库/集成文档（多源一致） | 官方 |
| **OpenStack Swift（需启用 s3api）** | 中间件 `s3api`（源自 swift3，**非默认开启**） | Swift proxy 地址（生产常为 `https://s3.{region}.{domain}`） | 开启方式：`proxy-server.conf` pipeline 加 `s3api`（Keystone 版需 `authtoken` 的 `delay_auth_decision=True`，分片上传依赖 SLO 中间件）；SigV4 支持（需 Keystone 凭据：`openstack ec2 credentials create`） | [Swift middleware 文档](https://docs.openstack.org/swift/latest/middleware.html) | 官方 |
| **Microsoft Azure Blob** | **原生不是 S3 协议**（无 S3 端点）；替代：S3 网关（如 VersityGW / Flexify.IO / S3Proxy；MinIO Gateway for Azure 已弃用） | 网关自身地址 | 网关成为额外故障点；元数据映射有损（如 VersityGW 以 `Vgw-` 前缀存元数据） | [VersityGW 后端](https://github.com/versity/versitygw) / Azure Marketplace | 官方（第三方声明）+ 二手 |

## 四、按配置键的横向要点

- **`path_style`**：企业自建与多数自托管（Qumulo 强制、SeaweedFS 必需、Dell/Cloudian 无通配 DNS 时、VAST/FlashBlade 示例、R2/Fastly 建议）都要 **true**（我们的默认，恰好是自建场景的通用答案）。**必须 false 的三家**：阿里云 OSS、腾讯 COS（2024 后建桶）、华为云 OBS。
- **`region`**：多数厂商不校验（ECS/ObjectScale 传 `Standard`、FlashBlade/SeaweedFS 任意、VAST 任意非空、Vultr/Linode/Akamai 忽略）；但 **Wasabi、Oracle、华为云、腾讯/阿里** 要求与桶一致，签名 region 与 endpoint 强绑定；DO 的 SDK region 填 AWS 名即可（真实区域由端点决定）。
- **`payload_signing`**：**华为云 OBS** 设 `unsigned`（官方 FAQ：SigV4 仅接受 UNSIGNED-PAYLOAD / STREAMING 形式）；OS/Hetzner/Fastly/Oracle 等拒绝 aws-chunked 的实现用默认 `hash`（本客户端签真实 payload，非 chunked）即可。
- **`endpoint` 可带端口**（企业端点 `9020/9021`、`18082`、`9000`、`8333`、`7480`…全部支持）；VAST 例外：**不要写端口**。
- **GCS 互通层 multipart ≤32 片**：64MB 分片下 >2GB 对象请把 `multipart_threshold` 调到 ≥ 文件大小（≤5GB 走单次 PUT）。

## 五、未能核实清单（如实）

1. XSKY XEOS 端点/寻址/SigV4 细节（无公开技术文档）。
2. Pure FlashBlade 官方 path/vhost 支持矩阵（support.purestorage.com 需登录）。
3. Flexential 端点与签名细节；11:11 Systems 端点域名。
4. EVault LTS2 是否仍可订购；Commvault Unified Data Vault 的 GA 状态与端点。
5. Hitachi HCP / HPE X10000 的 S3 默认端口（官方只给 URL 形态）。
6. Cloudian 的 SigV4 region 校验精确行为。
7. Swift s3api 与 `reseller_prefix` 的容器命名精确映射。
8. 中国移动云 EOS 的 path-style 支持性与 SigV4 region 取值。
9. 腾讯 COS `signingName:"cos"`（仅二手 Go 文章）。
10. Sakura tky01/arc02 的官方原文与 path-style 支持性；IONOS/DreamHost/IDrive/ArvanCloud/Cubbit 的官方寻址风格表述。
11. Telefónica Cloud 的任何 S3 端点/区城/寻址（仅营销手册）。
12. Azure：未发现微软"官方否认 S3 兼容"的声明页，也未发现原生 S3 端点公告（结论基于多方第三方与微软合作伙伴页面）。

## 六、维护说明

本表经三路并行核验（2026-10）：全球云 14 家、欧亚/中国云 15 家、企业/自托管/备份 16 项，逐项附来源与置信度。**未实测的厂商按"置信度"列如实标注**——其中端到端只在本机真 MinIO 上验证过完整链路（见 `docs/REPORT.md`），其余厂商为协议级兼容 + 文档级核验。接入前若发现与厂商现行文档不符，以厂商文档为准并欢迎回馈修订。
