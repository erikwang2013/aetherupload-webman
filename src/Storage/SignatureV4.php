<?php

namespace AetherUpload\Storage;

/**
 * AWS Signature Version 4（S3 规则：路径逐段编码、不归一化点段、不双重编码）。
 * 纯函数；正确性锚 = AWS 官方 sig-v4 测试向量（tests/SignatureV4Test.php）。
 */
final class SignatureV4
{
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    private function __construct()
    {
    }

    /**
     * @param array $headers 名称大小写不敏感；值 trim + 连续空白折叠；参与签名前按小写名排序
     */
    public static function canonicalRequest(string $method, string $uri, string $query, array $headers, string $payloadHash): string
    {
        $normalized = self::normalizedHeaders($headers);

        $canonicalHeaders = '';
        foreach ( $normalized as $name => $value ) {
            $canonicalHeaders .= $name . ':' . $value . "\n";
        }

        return strtoupper($method) . "\n"
            . self::canonicalUri($uri) . "\n"
            . self::canonicalQuery($query) . "\n"
            . $canonicalHeaders . "\n"
            . implode(';', array_keys($normalized)) . "\n"
            . $payloadHash;
    }

    public static function stringToSign(string $amzDate, string $date, string $region, string $service, string $canonicalRequest): string
    {
        return self::ALGORITHM . "\n"
            . $amzDate . "\n"
            . self::scope($date, $region, $service) . "\n"
            . hash('sha256', $canonicalRequest);
    }

    /** @return string 原始二进制签名密钥 */
    public static function signingKey(string $secretKey, string $date, string $region, string $service): string
    {
        $key = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
        $key = hash_hmac('sha256', $region, $key, true);
        $key = hash_hmac('sha256', $service, $key, true);

        return hash_hmac('sha256', 'aws4_request', $key, true);
    }

    public static function authorizationHeader(string $method, string $uri, string $query, array $headers, string $payloadHash, string $accessKey, string $secretKey, string $region, string $service, string $amzDate): string
    {
        $date = substr($amzDate, 0, 8);
        $signature = hash_hmac(
            'sha256',
            self::stringToSign($amzDate, $date, $region, $service, self::canonicalRequest($method, $uri, $query, $headers, $payloadHash)),
            self::signingKey($secretKey, $date, $region, $service)
        );

        return self::ALGORITHM
            . ' Credential=' . $accessKey . '/' . self::scope($date, $region, $service)
            . ', SignedHeaders=' . implode(';', array_keys(self::normalizedHeaders($headers)))
            . ', Signature=' . $signature;
    }

    /** @return string 完整查询串（含 X-Amz-Signature），供 URL 拼接 */
    public static function presignQuery(string $method, string $uri, string $query, array $headers, string $accessKey, string $secretKey, string $region, string $service, string $amzDate, int $expires, string $payloadHash = 'UNSIGNED-PAYLOAD'): string
    {
        $date = substr($amzDate, 0, 8);

        $params = [
            'X-Amz-Algorithm' => self::ALGORITHM,
            'X-Amz-Credential' => $accessKey . '/' . self::scope($date, $region, $service),
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string)$expires,
            'X-Amz-SignedHeaders' => implode(';', array_keys(self::normalizedHeaders($headers))),
        ];

        // 把已有查询参数与 X-Amz-* 合并、统一编码一次、按名称（再按值）排序
        $pairs = self::parseQuery($query);
        foreach ( $params as $name => $value ) {
            $pairs[] = [rawurlencode((string)$name), rawurlencode((string)$value)];
        }
        usort($pairs, static function (array $a, array $b): int {
            return $a[0] === $b[0] ? strcmp($a[1], $b[1]) : strcmp($a[0], $b[0]);
        });
        $canonicalQuery = implode('&', array_map(static function (array $p): string {
            return $p[0] . '=' . $p[1];
        }, $pairs));

        $signature = hash_hmac(
            'sha256',
            self::stringToSign($amzDate, $date, $region, $service, self::canonicalRequest($method, $uri, $canonicalQuery, $headers, $payloadHash)),
            self::signingKey($secretKey, $date, $region, $service)
        );

        return $canonicalQuery . '&X-Amz-Signature=' . $signature;
    }

    private static function scope(string $date, string $region, string $service): string
    {
        return $date . '/' . $region . '/' . $service . '/aws4_request';
    }

    /** @return array<string,string> 小写名 => 归一值，按名排序 */
    private static function normalizedHeaders(array $headers): array
    {
        $normalized = [];

        foreach ( $headers as $name => $value ) {
            $normalized[strtolower((string)$name)] = trim((string)preg_replace('/\s+/', ' ', (string)$value));
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /** S3：逐段 rawurlencode（不做点段归一化、不双重编码） */
    private static function canonicalUri(string $uri): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $uri)));
    }

    private static function canonicalQuery(string $query): string
    {
        $pairs = self::parseQuery($query);
        usort($pairs, static function (array $a, array $b): int {
            return $a[0] === $b[0] ? strcmp($a[1], $b[1]) : strcmp($a[0], $b[0]);
        });

        return implode('&', array_map(static function (array $p): string {
            return $p[0] . '=' . $p[1];
        }, $pairs));
    }

    /** @return array<int,array{0:string,1:string}> 已编码的 [名, 值] 对（先解码再编码一次，保证规范语义） */
    private static function parseQuery(string $query): array
    {
        if ( $query === '' ) {
            return [];
        }

        $pairs = [];
        foreach ( explode('&', $query) as $pair ) {
            if ( $pair === '' ) {
                continue;
            }
            $eq = strpos($pair, '=');
            $name = $eq === false ? $pair : substr($pair, 0, $eq);
            $value = $eq === false ? '' : substr($pair, $eq + 1);
            $pairs[] = [rawurlencode(rawurldecode($name)), rawurlencode(rawurldecode($value))];
        }

        return $pairs;
    }
}
