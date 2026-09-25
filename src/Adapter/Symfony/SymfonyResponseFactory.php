<?php

namespace AetherUpload\Adapter\Symfony;

use AetherUpload\Contract\ResponseFactoryInterface;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class SymfonyResponseFactory implements ResponseFactoryInterface
{
    public function text(string $body, int $status = 200): object
    {
        return new SymfonyResponse($body, $status);
    }

    /**
     * @param mixed $data
     */
    public function json($data, int $status = 200): object
    {
        // 自己编码而不用 JsonResponse：它的默认 flag 是 JSON_HEX_* 那一套，
        // 会让同一份错误消息在六个框架下字节不同。契约要求的 flag 在这里显式写死。
        $body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new SymfonyResponse($body, $status, ['Content-Type' => 'application/json']);
    }

    public function file(string $path): object
    {
        return self::withMimeType(new SymfonyFileResponse($path), $path);
    }

    public function download(string $path, string $name): object
    {
        $response = new SymfonyFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name);

        return self::withMimeType($response, $path);
    }

    /**
     * 预先定好 Content-Type。
     *
     * BinaryFileResponse 只在 prepare() 阶段猜 mime，猜的时候用的是可选的 symfony/mime；
     * 宿主没装这个包（framework-bundle 只 suggest）时，本该 200 的下载会在响应阶段抛 LogicException 变成 500。
     * webman 的 response()->file() 同样是即时用 mime_content_type() 定型的，这里对齐。
     */
    private static function withMimeType(SymfonyFileResponse $response, string $path): SymfonyFileResponse
    {
        if ( function_exists('mime_content_type') ) {
            $mime = @mime_content_type($path);

            if ( is_string($mime) && $mime !== '' ) {
                $response->headers->set('Content-Type', $mime);
            }
        }

        return $response;
    }
}
