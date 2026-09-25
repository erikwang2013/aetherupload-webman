<?php

namespace AetherUpload\Contract;

/**
 * 配置读取端口。
 *
 * 内核只认「逻辑键」（如 root_dir、groups.file.group_dir），
 * 宿主前缀（webman 的 plugin.erikwang2013.aetherupload-webman.app.、
 * 其余框架的 aetherupload.）由各适配器负责拼装。
 */
interface ConfigInterface
{
    /**
     * 点号分隔取嵌套配置；缺失时返回 $default。
     *
     * @param string $key     逻辑键，不含宿主前缀
     * @param mixed  $default
     * @return mixed
     */
    public function get(string $key, $default = null);
}
