<?php

namespace AetherUpload\Storage;

/**
 * 最小 S3 客户端：SigV4 标准签名 + curl 传输 + 重试 + multipart。
 * 只做本包需要的五个操作：PUT / multipart / HEAD / DELETE / 预签名 GET。
 *
 * 关键设计：成品文件在本地 → payload sha256 可预算 → 永远不用 aws-chunked 流式签名
 * （各家 S3 兼容实现最易翻车处）。$transport 可注入：单测逐字节断言请求，生产用 curlTransport()。
 */
final class S3Client
{
    /** 固定分片大小：非末片恒为 64MB（≥ S3 的 5MB 下限），末片可小 */
    private const PART_SIZE = 67108864;

    private const MAX_ATTEMPTS = 3;
    private const RETRY_SLEEP_US = 200000;

    /** @var array */
    private $config;

    /** @var callable */
    private $transport;

    public function __construct(array $config, ?callable $transport = null)
    {
        $config = array_merge([
            'endpoint' => '',
            'region' => '',
            'bucket' => '',
            'access_key' => '',
            'secret_key' => '',
            'path_style' => true,
            'prefix' => '',
            'multipart_threshold' => 104857600,
            'payload_signing' => 'hash',
        ], $config);

        foreach ( ['region', 'bucket', 'access_key', 'secret_key'] as $required ) {
            if ( ! is_string($config[$required]) || $config[$required] === '' ) {
                // 只报键名，绝不回显值
                throw new \Exception('S3 配置缺少 storage.s3.' . $required . '（S3 驱动必需）');
            }
        }

        if ( ! in_array($config['payload_signing'], ['hash', 'unsigned'], true) ) {
            // 只报键名与允许值，不回显输入
            throw new \Exception('S3 配置 storage.s3.payload_signing 非法（只允许 hash 或 unsigned）');
        }

        $this->config = $config;
        $this->transport = $transport ?: self::curlTransport();
    }

    public function key(string $groupDir, string $groupSubDir, string $name): string
    {
        $prefix = (string)$this->config['prefix'];

        if ( $prefix !== '' && substr($prefix, -1) !== '/' ) {
            $prefix .= '/';
        }

        return $prefix . $groupDir . '/' . $groupSubDir . '/' . $name;
    }

    public function put(string $key, string $localPath, string $contentType): void
    {
        $size = (int)filesize($localPath);
        $response = $this->call('PUT', $key, '', ['Content-Type' => $contentType], null, $localPath, $size, 0);

        if ( $response['status'] !== 200 ) {
            throw $this->fail('PUT', $key, $response);
        }
    }

    public function head(string $key): bool
    {
        $response = $this->call('HEAD', $key, '', [], null, null, null, 0);

        if ( $response['status'] === 200 ) {
            return true;
        }

        if ( $response['status'] === 404 ) {
            return false;
        }

        throw $this->fail('HEAD', $key, $response);
    }

    public function delete(string $key): void
    {
        $response = $this->call('DELETE', $key, '', [], null, null, null, 0);

        // S3 删除幂等：缺失也是成功（204；个别实现 404，同样放过）
        if ( ! in_array($response['status'], [200, 204, 404], true) ) {
            throw $this->fail('DELETE', $key, $response);
        }
    }

    public function presign(string $key, int $ttl, array $responseParams = []): string
    {
        [$scheme, $host, $path] = $this->objectUrl($key);

        $query = '';
        foreach ( $responseParams as $name => $value ) {
            $query .= ($query === '' ? '' : '&') . rawurlencode((string)$name) . '=' . rawurlencode((string)$value);
        }

        $signed = SignatureV4::presignQuery(
            'GET',
            $path,
            $query,
            ['host' => $host],
            $this->config['access_key'],
            $this->config['secret_key'],
            $this->config['region'],
            's3',
            gmdate('Ymd\THis\Z'),
            $ttl
        );

        return $scheme . '://' . $host . $path . '?' . $signed;
    }

    public function uploadMultipartOrPut(string $key, string $localPath, string $contentType): void
    {
        if ( (int)filesize($localPath) > (int)$this->config['multipart_threshold'] ) {
            $this->multipart($key, $localPath, $contentType);

            return;
        }

        $this->put($key, $localPath, $contentType);
    }

    public function multipart(string $key, string $localPath, string $contentType): void
    {
        $init = $this->call('POST', $key, 'uploads=', ['Content-Type' => $contentType], null, null, null, 0);

        if ( $init['status'] !== 200 || ! preg_match('#<UploadId>([^<]+)</UploadId>#', (string)$init['body'], $m) ) {
            throw $this->fail('CreateMultipartUpload', $key, $init);
        }

        $uploadId = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1);
        $fileSize = (int)filesize($localPath);
        $partCount = (int)ceil($fileSize / self::PART_SIZE);
        $parts = [];

        for ( $i = 1; $i <= $partCount; $i++ ) {
            $offset = ($i - 1) * self::PART_SIZE;
            $size = min(self::PART_SIZE, $fileSize - $offset);

            $response = $this->call('PUT', $key, 'partNumber=' . $i . '&uploadId=' . rawurlencode($uploadId), ['Content-Type' => $contentType], null, $localPath, $size, $offset);

            if ( $response['status'] !== 200 || ! isset($response['headers']['etag']) ) {
                $this->abort($key, $uploadId);
                throw $this->fail('UploadPart#' . $i, $key, $response);
            }

            $parts[] = ['number' => $i, 'etag' => $response['headers']['etag']];
        }

        $xml = '<CompleteMultipartUpload>';
        foreach ( $parts as $part ) {
            // ENT_NOQUOTES：ETag 的双引号是值的一部分，不做实体化（生效于 XML 文本节点，&<> 仍被转义）
            $xml .= '<Part><PartNumber>' . $part['number'] . '</PartNumber><ETag>' . htmlspecialchars($part['etag'], ENT_NOQUOTES) . '</ETag></Part>';
        }
        $xml .= '</CompleteMultipartUpload>';

        try {
            $complete = $this->call('POST', $key, 'uploadId=' . rawurlencode($uploadId), ['Content-Type' => 'application/xml'], $xml, null, null, 0);
        } catch ( \Exception $e ) {
            // 响应丢失场景也要恢复：complete 可能已在服务端成功，只是响应没回来（call() 重试耗尽后抛）
            $confirmed = false;
            try {
                $confirmed = $this->head($key);
            } catch ( \Exception $headError ) {
                // HEAD 失败不掩盖原始异常
            }

            if ( $confirmed ) {
                return;
            }

            $this->abort($key, $uploadId);
            throw $e;
        }

        // 经典陷阱：HTTP 200 但体内嵌 <Error> 仍是失败
        if ( $complete['status'] === 200 && stripos((string)$complete['body'], '<Error') === false ) {
            return;
        }

        // 失败路径同样先 HEAD 确认（防「实际成功但响应异常」）
        if ( $this->head($key) ) {
            return;
        }

        $this->abort($key, $uploadId);
        throw $this->fail('CompleteMultipartUpload', $key, $complete);
    }

    /** @return callable 默认 curl 传输；bodyFile 时以 CURLOPT_UPLOAD 流式发送（不载入内存） */
    public static function curlTransport(): callable
    {
        return static function (array $request): array {
            $headers = [];
            foreach ( $request['headers'] as $name => $value ) {
                $headers[] = $name . ': ' . $value;
            }

            $responseHeaders = [];
            $ch = curl_init($request['url']);

            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $request['method'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CONNECTTIMEOUT => 10,
                // 无总超时（大文件 PUT 不能被截断），低速守卫兜底
                CURLOPT_LOW_SPEED_LIMIT => 1024,
                CURLOPT_LOW_SPEED_TIME => 60,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                    $responseHeaders[] = rtrim($line, "\r\n");

                    return strlen($line);
                },
            ]);

            $handle = null;

            if ( $request['bodyFile'] !== null ) {
                $handle = fopen($request['bodyFile'], 'rb');

                if ( $handle === false ) {
                    throw new \Exception('无法打开本地文件：' . $request['bodyFile']);
                }

                if ( $request['bodyOffset'] > 0 ) {
                    fseek($handle, $request['bodyOffset']);
                }

                // CURLOPT_UPLOAD 隐含 PUT；INFILESIZE 限定只读 size 字节（multipart 分片零拷贝）
                curl_setopt($ch, CURLOPT_UPLOAD, true);
                curl_setopt($ch, CURLOPT_INFILE, $handle);
                curl_setopt($ch, CURLOPT_INFILESIZE, $request['bodySize']);
            } elseif ( $request['body'] !== null ) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $request['body']);
            }

            if ( $request['method'] === 'HEAD' ) {
                curl_setopt($ch, CURLOPT_NOBODY, true);
            }

            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);

            if ( $handle !== null ) {
                fclose($handle);
            }

            // 不调用 curl_close()：PHP 8.0 起是空操作，8.5 起废弃。宿主的 error handler（如 webman）
            // 会把这条 deprecation 转成 ErrorException，在传输层炸成假失败并触发重试；句柄交给 GC 回收。

            if ( $body === false ) {
                throw new \Exception('curl: ' . $error);
            }

            $parsed = [];
            foreach ( $responseHeaders as $line ) {
                $pos = strpos($line, ':');
                if ( $pos !== false ) {
                    $parsed[strtolower(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
                }
            }

            return ['status' => $status, 'headers' => $parsed, 'body' => (string)$body];
        };
    }

    /** @return array{0:string,1:string,2:string} [scheme, host, path]（path 以 / 开头，含 bucket 视 path_style） */
    private function objectUrl(string $key): array
    {
        $endpoint = (string)$this->config['endpoint'];
        $pathStyle = $this->config['path_style'] !== false;
        $bucket = (string)$this->config['bucket'];

        if ( $endpoint === '' ) {
            $region = $this->config['region'];
            $host = $pathStyle ? 's3.' . $region . '.amazonaws.com' : $bucket . '.s3.' . $region . '.amazonaws.com';
            $path = $pathStyle ? '/' . $bucket . '/' . $key : '/' . $key;

            return ['https', $host, $path];
        }

        $parts = parse_url(strpos($endpoint, '://') === false ? 'https://' . $endpoint : $endpoint);
        $scheme = $parts['scheme'] ?? 'https';
        $base = rtrim($parts['path'] ?? '', '/');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        if ( $pathStyle ) {
            return [$scheme, ($parts['host'] ?? '') . $port, $base . '/' . $bucket . '/' . $key];
        }

        return [$scheme, $bucket . '.' . ($parts['host'] ?? '') . $port, $base . '/' . $key];
    }

    /**
     * 发一个已签名请求（幂等操作内部重试至多 3 次：网络异常 / 5xx / 429；4xx 不重试）。
     */
    private function call(string $method, string $key, string $query, array $headers, ?string $body, ?string $bodyFile, ?int $bodySize, int $bodyOffset): array
    {
        [$scheme, $host, $path] = $this->objectUrl($key);

        // payload 哈希必须等于**实际发送的字节**：multipart 分片是 [offset, size) 切片，
        // 用整文件哈希会被 MinIO/AWS 拒绝（XAmzContentSHA256Mismatch），且造成 O(n²) 读取放大。
        // 整文件（单次 PUT / 单分片）走 hash_file 快路径，其余哈希切片。
        // payload_signing='unsigned'：华为云 OBS 等实现只接受 UNSIGNED-PAYLOAD（跳过内容哈希，省一次全文件读）。
        if ( $this->config['payload_signing'] === 'unsigned' ) {
            $payloadHash = 'UNSIGNED-PAYLOAD';
        } elseif ( $bodyFile === null ) {
            $payloadHash = hash('sha256', (string)$body);
        } elseif ( $bodyOffset === 0 && ($bodySize === null || $bodySize === filesize($bodyFile)) ) {
            $payloadHash = (string)hash_file('sha256', $bodyFile);
        } else {
            $payloadHash = self::hashFileSlice($bodyFile, $bodyOffset, (int)$bodySize);
        }
        // 只签三个自产头：host / x-amz-content-sha256 / x-amz-date（Content-Type 等不签，S3 允许）
        $signedHeaders = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate = gmdate('Ymd\THis\Z'),
        ];

        $request = [
            'method' => $method,
            'url' => $scheme . '://' . $host . $path . ($query === '' ? '' : '?' . $query),
            'headers' => array_merge(
                $headers,
                [
                    'Host' => $host,
                    'x-amz-content-sha256' => $payloadHash,
                    'x-amz-date' => $amzDate,
                    'Authorization' => SignatureV4::authorizationHeader($method, $path, $query, $signedHeaders, $payloadHash, $this->config['access_key'], $this->config['secret_key'], $this->config['region'], 's3', $amzDate),
                ]
            ),
            'body' => $body,
            'bodyFile' => $bodyFile,
            'bodyOffset' => $bodyOffset,
            'bodySize' => $bodySize,
        ];

        $attempt = 0;

        while ( true ) {
            $attempt++;

            try {
                $response = ($this->transport)($request);
            } catch ( \Exception $e ) {
                if ( $attempt >= self::MAX_ATTEMPTS ) {
                    throw new \Exception('S3 ' . $method . ' failed: transport error: ' . $e->getMessage() . ' (key=' . $key . ')', 0, $e);
                }

                usleep(self::RETRY_SLEEP_US * $attempt);
                continue;
            }

            if ( ($response['status'] >= 500 || $response['status'] === 429) && $attempt < self::MAX_ATTEMPTS ) {
                usleep(self::RETRY_SLEEP_US * $attempt);
                continue;
            }

            return $response;
        }
    }

    private function abort(string $key, string $uploadId): void
    {
        try {
            $this->call('DELETE', $key, 'uploadId=' . rawurlencode($uploadId), [], null, null, null, 0);
        } catch ( \Exception $e ) {
            // best-effort：清理失败不掩盖原始异常
        }
    }

    /** 只哈希文件的 [offset, offset+size) 切片（流式，不整读） */
    private static function hashFileSlice(string $path, int $offset, int $size): string
    {
        $handle = fopen($path, 'rb');

        if ( $handle === false ) {
            throw new \Exception('无法打开本地文件：' . $path);
        }

        fseek($handle, $offset);
        $context = hash_init('sha256');
        hash_update_stream($context, $handle, $size);
        fclose($handle);

        return hash_final($context);
    }

    /** @param array{status:int,body:string} $response */
    private function fail(string $op, string $key, array $response): \Exception
    {
        // spec §6：异常/日志/错误体一律不外泄。错误体只留诊断信息，凭据标识一律擦掉：
        // 既擦本驱动配置的 access_key 裸串（网关错误页回显 Authorization 等情形），也擦
        // <AWSAccessKeyId> 标签（可能回显的不是本配置的 key）。access_key 过短则只擦标签，
        // 避免把诊断文本大面积替换成 ***。
        $body = (string)$response['body'];
        $accessKey = (string)($this->config['access_key'] ?? '');

        if ( strlen($accessKey) >= 8 ) {
            $body = str_replace($accessKey, '***', $body);
        }

        $body = (string)preg_replace('#<AWSAccessKeyId>[^<]*</AWSAccessKeyId>#', '<AWSAccessKeyId>***</AWSAccessKeyId>', $body);
        $code = preg_match('#<Code>([^<]*)</Code>#', $body, $m) ? $m[1] : '';
        $message = preg_match('#<Message>([^<]*)</Message>#', $body, $m) ? $m[1] : substr($body, 0, 200);

        return new \Exception(sprintf('S3 %s failed: HTTP %d %s %s (key=%s)', $op, $response['status'], $code, $message, $key));
    }
}
