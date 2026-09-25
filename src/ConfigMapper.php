<?php

namespace AetherUpload;

class ConfigMapper
{
    private static $_instance = null;
    private $root_dir;
    private $resource_subdir_rule;
    private $chunk_size;
    private $resource_maxsize;
    private $resource_extensions;
    private $group;
    private $group_dir;
    private $middleware_preprocess;
    private $middleware_uploading;
    private $middleware_display;
    private $middleware_download;
    private $forbidden_extensions;
    private $instant_completion;
    private $route_preprocess;
    private $route_uploading;
    private $route_display;
    private $route_download;
    private $lax_mode;
    private $extra_mime_types;
    private $x_accel_redirect;
    private $event_before_upload_complete;
    private $event_upload_complete;
    /**
     * @deprecated 内核已改走 Runtime::config() 的逻辑键，前缀由各适配器负责。
     *             保留此常量仅为兼容可能引用它的外部代码，新代码请勿使用。
     */
    const PREFIX = 'plugin.erikwang2013.aetherupload-webman.app.';

    private function __construct()
    {
        //disallow new instance
    }

    /**
     * 取「本执行上下文」的配置实例。
     *
     * `$_instance` 只是**当前上下文的镜像**（保留它是为了让 resetConfigMapper() 这类
     * 反射式测试钩子继续有效）；真正的归属是 RequestContext：
     *
     *  1. 镜像为空（含被反射置 null）→ 重建本上下文实例，承接旧语义
     *  2. 上下文里的实例与镜像不一致 → 说明执行上下文已切换（webman 换请求 / Hyperf 换协程）：
     *     上下文自己已有实例就采用它（保住本上下文 applyGroupConfig 的结果），
     *     否则重建一个干净的，**绝不能沿用上一段的快照**
     *  3. 其余情况一致，直接返回
     */
    private static function instance()
    {
        $context = Runtime::context();

        if ( self::$_instance === null ) {
            self::$_instance = (new self())->applyCommonConfig();
            $context->configMapper = self::$_instance;

            return self::$_instance;
        }

        if ( $context->configMapper !== self::$_instance ) {
            if ( $context->configMapper instanceof self ) {
                self::$_instance = $context->configMapper;

                return self::$_instance;
            }

            self::$_instance = (new self())->applyCommonConfig();
            $context->configMapper = self::$_instance;

            return self::$_instance;
        }

        return self::$_instance;
    }

    private function applyCommonConfig()
    {
        $config = Runtime::config();

        $this->root_dir = $config->get('root_dir');
        $this->chunk_size = $config->get('chunk_size');
        $this->resource_subdir_rule = $config->get('resource_subdir_rule');
        $this->forbidden_extensions = $config->get('forbidden_extensions');
        $this->middleware_preprocess = $config->get('middleware_preprocess');
        $this->middleware_uploading = $config->get('middleware_uploading');
        $this->middleware_display = $config->get('middleware_display');
        $this->middleware_download = $config->get('middleware_download');
        $this->instant_completion = $config->get('instant_completion');
        $this->route_preprocess = $config->get('route_preprocess');
        $this->route_uploading = $config->get('route_uploading');
        $this->route_display = $config->get('route_display');
        $this->route_download = $config->get('route_download');
        $this->lax_mode = $config->get('lax_mode');
        $this->extra_mime_types = $config->get('extra_mime_types');
        $this->x_accel_redirect = $config->get('x_accel_redirect');

        return $this;
    }

    private function applyGroupConfig($group)
    {
        // 分组名参与存储路径的编码(SavedPathResolver::encode)，含下划线时解码会把它拆成多个字段，导致该分组下的资源全部404
        if ( ! is_string($group) || str_contains($group, '_') ) {
            throw new \Exception(Runtime::trans('invalid_operation'));
        }

        $config = Runtime::config();

        if ( ! in_array($group, array_keys((array)$config->get('groups'))) ) {
            throw new \Exception(Runtime::trans('invalid_operation'));
        }

        $this->group = $group;
        $this->group_dir = $config->get('groups.' . $group . '.group_dir');
        $this->resource_maxsize = $config->get('groups.' . $group . '.resource_maxsize');
        $this->resource_extensions = $config->get('groups.' . $group . '.resource_extensions');
        $this->event_before_upload_complete = $config->get('groups.' . $group . '.event_before_upload_complete');
        $this->event_upload_complete = $config->get('groups.' . $group . '.event_upload_complete');

        return $this;
    }

    public static function get($property)
    {
        return self::instance()->{$property};
    }

    public static function set($property, $value)
    {
        $instance = self::instance();

        if ( ! property_exists($instance, $property) ) {
            throw new \Exception('invalid property');
        }

        $instance->{$property} = $value;
    }

    public static function __callStatic($name, $arguments)
    {
        return self::instance()->{$name}(... $arguments);
    }

}