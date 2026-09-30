<?php

namespace AetherUpload\Tests;

use AetherUpload\Storage\SignatureV4;
use PHPUnit\Framework\TestCase;

/**
 * AWS 官方 sig-v4 测试向量（rhymu8354/aws-sig-v4-test-suite 镜像，原 aws-samples 库已删）。
 * 固定材料：AKIDEXAMPLE / wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY / 20150830T123600Z / us-east-1 / service。
 * 期望值是官方 .creq / .sts / .authz 文件的逐字节内容 —— 不是我们实现的复读。
 */
class SignatureV4Test extends TestCase
{
    const ACCESS = 'AKIDEXAMPLE';
    const SECRET = 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY';
    const AMZ_DATE = '20150830T123600Z';
    const REGION = 'us-east-1';
    const SERVICE = 'service';
    const EMPTY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function testGetVanillaVector(): void
    {
        $headers = ['Host' => 'example.amazonaws.com', 'X-Amz-Date' => self::AMZ_DATE];

        $creq = SignatureV4::canonicalRequest('GET', '/', '', $headers, self::EMPTY_HASH);

        $this->assertSame(
            "GET\n/\n\nhost:example.amazonaws.com\nx-amz-date:" . self::AMZ_DATE
                . "\n\nhost;x-amz-date\n" . self::EMPTY_HASH,
            $creq
        );
        $this->assertSame(
            "AWS4-HMAC-SHA256\n" . self::AMZ_DATE . "\n20150830/us-east-1/service/aws4_request\n"
                . 'bb579772317eb040ac9ed261061d46c1f17a8133879d6129b6e1c25292927e63',
            SignatureV4::stringToSign(self::AMZ_DATE, '20150830', self::REGION, self::SERVICE, $creq)
        );
        $this->assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, '
                . 'SignedHeaders=host;x-amz-date, '
                . 'Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            SignatureV4::authorizationHeader('GET', '/', '', $headers, self::EMPTY_HASH, self::ACCESS, self::SECRET, self::REGION, self::SERVICE, self::AMZ_DATE)
        );
    }

    public function testHeaderValueTrimVector(): void
    {
        // My-Header2: "a   b   c" —— 头值 trim + 连续空白折叠为单空格
        $headers = [
            'Host' => 'example.amazonaws.com',
            'My-Header1' => 'value1',
            'My-Header2' => '"a   b   c"',
            'X-Amz-Date' => self::AMZ_DATE,
        ];

        $this->assertSame(
            SignatureV4::canonicalRequest('GET', '/', '', $headers, self::EMPTY_HASH),
            "GET\n/\n\nhost:example.amazonaws.com\nmy-header1:value1\nmy-header2:\"a b c\"\nx-amz-date:" . self::AMZ_DATE
                . "\n\nhost;my-header1;my-header2;x-amz-date\n" . self::EMPTY_HASH
        );
        $this->assertStringContainsString(
            'Signature=acc3ed3afb60bb290fc8d2dd0098b9911fcaa05412b367055dee359757a9c736',
            SignatureV4::authorizationHeader('GET', '/', '', $headers, self::EMPTY_HASH, self::ACCESS, self::SECRET, self::REGION, self::SERVICE, self::AMZ_DATE)
        );
    }

    public function testPostXWwwFormUrlencodedVector(): void
    {
        $body = 'Param1=value1';
        $payloadHash = hash('sha256', $body); // 9095672b...（官方 .creq 一致）
        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Host' => 'example.amazonaws.com',
            'X-Amz-Date' => self::AMZ_DATE,
            'Content-Length' => '13',
        ];

        $this->assertSame('9095672bbd1f56dfc5b65f3e153adc8731a4a654192329106275f4c7b24d0b6e', $payloadHash);
        $this->assertStringContainsString(
            'Signature=fec50118d90ecf934441dd37fb9a49bd7f5adb6450802ca3a0977623bbb7c27f',
            SignatureV4::authorizationHeader('POST', '/', '', $headers, $payloadHash, self::ACCESS, self::SECRET, self::REGION, self::SERVICE, self::AMZ_DATE)
        );
    }

    public function testPresignQueryCarriesCanonicalParameters(): void
    {
        // 预签名无官方向量可嵌（docs 示例值已不可考），正确性由 MinIO 端到端验收；
        // 此处只锁结构与「参数集完整 + 单次编码」两条硬性质。
        $query = SignatureV4::presignQuery('GET', '/bucket/a.gif', '', ['host' => 's3.example.com'], 'AKIDEXAMPLE', self::SECRET, self::REGION, 's3', self::AMZ_DATE, 300);

        $this->assertStringContainsString('X-Amz-Algorithm=AWS4-HMAC-SHA256', $query);
        $this->assertStringContainsString('X-Amz-Credential=AKIDEXAMPLE%2F20150830%2Fus-east-1%2Fs3%2Faws4_request', $query);
        $this->assertStringContainsString('X-Amz-Date=' . self::AMZ_DATE, $query);
        $this->assertStringContainsString('X-Amz-Expires=300', $query);
        $this->assertStringContainsString('X-Amz-SignedHeaders=host', $query);
        $this->assertMatchesRegularExpression('/X-Amz-Signature=[0-9a-f]{64}$/', $query);
    }
}
