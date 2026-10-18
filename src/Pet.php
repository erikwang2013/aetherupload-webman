<?php

namespace AetherUpload;

/**
 * 项目宠物「以太兽」的表情形态与上传状态映射。
 *
 * 形态资源位于 docs/js/pet-expressions/，随 docs/js 一起发布到宿主的
 * <文档根>/vendor/aetherupload/js/pet-expressions/。
 */
final class Pet
{
    /** 表情形态：名称 => [中文标签, 表情代码] */
    private const EXPRESSIONS = [
        'smile'      => ['label' => '笑', 'code' => ':emoji-smile:'],
        'joy'        => ['label' => '欢乐', 'code' => ':emoji-joy:'],
        'cry'        => ['label' => '哭', 'code' => ':emoji-cry:'],
        'depressed'  => ['label' => '沮丧', 'code' => ':emoji-depressed:'],
        'terrified'  => ['label' => '惊恐', 'code' => ':emoji-terrified:'],
        'angry'      => ['label' => '愤怒', 'code' => ':emoji-angry:'],
        'coquettish' => ['label' => '撒娇', 'code' => ':emoji-coquettish:'],
        'cute'       => ['label' => '卖萌', 'code' => ':emoji-cute:'],
        'pleasing'   => ['label' => '讨好', 'code' => ':emoji-pleasing:'],
        'knocking'   => ['label' => '敲打', 'code' => ':emoji-knocking:'],
        'facepalm'   => ['label' => '捂脸', 'code' => ':emoji-facepalm:'],
        'tongue'     => ['label' => '吐舌头', 'code' => ':emoji-tongue:'],
    ];

    /** 上传状态 => 表情形态 */
    private const STATE_EXPRESSIONS = [
        'idle'      => 'smile',
        'begin'     => 'cute',
        'hashing'   => 'pleasing',
        'uploading' => 'knocking',
        'retry'     => 'terrified',
        'instant'   => 'joy',
        'success'   => 'joy',
        'error'     => 'cry',
    ];

    /** 上传状态 => 中文说明 */
    private const STATE_LABELS = [
        'idle'      => '待机',
        'begin'     => '准备中',
        'hashing'   => '计算哈希',
        'uploading' => '上传中',
        'retry'     => '重试中',
        'instant'   => '秒传成功',
        'success'   => '上传成功',
        'error'     => '上传失败',
    ];

    /** 发布后的浏览器访问路径 */
    public const BASE_URL = '/vendor/aetherupload/js/pet-expressions';

    /**
     * 全部表情形态的元数据（名称 => 标签/代码）。
     */
    public static function expressions(): array
    {
        return self::EXPRESSIONS;
    }

    /**
     * 全部表情形态名称（顺序与 README 表格一致）。
     */
    public static function names(): array
    {
        return array_keys(self::EXPRESSIONS);
    }

    /**
     * 形态名称 => 中文标签，便于页面展示。
     */
    public static function expressionLabels(): array
    {
        $labels = [];

        foreach ( self::EXPRESSIONS as $name => $meta ) {
            $labels[$name] = $meta['label'];
        }

        return $labels;
    }

    /**
     * 上传状态 => 表情形态。
     */
    public static function stateExpressions(): array
    {
        return self::STATE_EXPRESSIONS;
    }

    /**
     * 上传状态 => 中文说明。
     */
    public static function stateLabels(): array
    {
        return self::STATE_LABELS;
    }

    /**
     * 状态对应的形态；未知状态回落到待机的 smile。
     */
    public static function expressionForState(string $state): string
    {
        return self::STATE_EXPRESSIONS[$state] ?? 'smile';
    }

    /**
     * 形态资源的浏览器 URL。
     *
     * @throws \InvalidArgumentException 未知形态名称
     */
    public static function url(string $expression): string
    {
        if ( ! isset(self::EXPRESSIONS[$expression]) ) {
            throw new \InvalidArgumentException('Unknown pet expression: ' . $expression);
        }

        return self::BASE_URL . '/' . $expression . '.svg';
    }
}
