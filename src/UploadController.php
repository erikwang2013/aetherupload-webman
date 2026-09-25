<?php

namespace AetherUpload;

class UploadController
{
    use ExamplePageTrait,SimpleValidateTrait;

    private const KNOWN_ERROR_MESSAGES = [
        'invalid_operation',
        'upload_error',
        'invalid_resource_size',
        'invalid_resource_type',
        'missing_mimetype',
        'write_resource_fail',
        'rename_resource_fail',
        'delete_resource_fail',
        'create_header_fail',
        'write_header_fail',
        'read_header_fail',
        'delete_header_fail',
        'create_subfolder_fail',
        'create_resource_fail',
    ];

    private function fail(array $result, \Exception $e)
    {
        return Responser::reportError($result, in_array($e->getMessage(), self::KNOWN_ERROR_MESSAGES, true) ? $e->getMessage() : Runtime::trans('upload_error'));
    }

    public function __construct()
    {
        // 语言文件目录取自路径端口（宿主差异由适配器吸收）；登记动作交给翻译端口
        $directory = Runtime::paths()->translationsPath() . DIRECTORY_SEPARATOR . 'aetherupload';

        Runtime::translator()->loadMessages($directory, 'zh');
        Runtime::translator()->loadMessages($directory, 'en');
    }

    /**
     * Preprocess the upload request
     */
    public function preprocess()
    {
        // locale 会透传给 symfony 的 setLocale(string)，数组会抛 TypeError（未被 catch Exception 接住 → 500）；
        // 不做白名单，非字符串回落到默认 'en'，查不到语言目录时由下游自然降级
        $locale = Runtime::request()->input('locale', 'en');
        Runtime::setLocale(is_string($locale) ? $locale : 'en');

        $result = [
            'error'                => 0,
            'chunkSize'            => 0,
            'groupSubDir'          => '',
            'resourceTempBaseName' => '',
            'resourceExt'          => '',
            'savedPath'            => '',
        ];

        if($this->validatedWithError(Runtime::request(), [
            'resource_name' => 'required',
            'resource_size' => 'required',
            'group'         => 'required',
            'resource_hash' => 'present',
        ])){
            return Responser::reportError($result, Runtime::trans('invalid_resource_params'));
        }

        $partialResource = null;

        try {

            $resourceName = Runtime::request()->input('resource_name');
            $resourceSize = Runtime::request()->input('resource_size');
            $resourceHash = Runtime::request()->input('resource_hash');
            $group = Runtime::request()->input('group');

            // security: resource_name 要进 pathinfo()，数组会抛 TypeError（500）；
            // resource_size 是数值语义（JSON 客户端会直接发整数），只要求标量 —— 数组会被 (int) 成 1 从而绕过声明值校验
            if ( ! is_string($resourceName) || ! is_scalar($resourceSize) ) {
                return Responser::reportError($result, Runtime::trans('invalid_resource_params'));
            }

            ConfigMapper::applyGroupConfig($group);

            $result['resourceTempBaseName'] = $resourceTempBaseName = Util::generateTempName();
            $result['resourceExt'] = $resourceExt = strtolower(pathinfo($resourceName, PATHINFO_EXTENSION));
            $result['groupSubDir'] = $groupSubDir = Util::generateSubDirName();
            $result['chunkSize'] = ConfigMapper::get('chunk_size');

            $partialResource = new PartialResource($resourceTempBaseName, $resourceExt, $groupSubDir);

            $partialResource->filterBySize($resourceSize);

            $partialResource->filterByExtension($resourceExt);

            // determine if this upload meets the condition of instant completion
            // is_string() 前置：数组 hash 会被 (string) 转成 "Array" 拼出 file_Array 这种垃圾 key（同 saveChunk）
            if ( ConfigMapper::get('instant_completion') === true && ! empty($resourceHash) && is_string($resourceHash) ) {
                try {
                    $savedPath = RedisSavedPath::get($savedPathKey = RedisSavedPath::getKey($group, $resourceHash));
                } catch ( \Exception $e ) {
                    $savedPath = null;
                }

                if ( ! empty($savedPath) ) {
                    $result['savedPath'] = $savedPath;

                    return Responser::returnResult($result);
                }
            }

            $partialResource->create();

            $partialResource->chunkIndex = 0;

        } catch ( \Exception $e ) {

            if ( $partialResource !== null ) {
                $partialResource->cleanup();
            }

            return $this->fail($result, $e);
        }

        return Responser::returnResult($result);
    }

    /**
     * Handle and save the uploaded chunks
     */
    public function saveChunk()
    {
        // 同 preprocess：数组 locale 会触发 setLocale(string) 的 TypeError，非字符串回落到默认 'en'
        $locale = Runtime::request()->input('locale', 'en');
        Runtime::setLocale(is_string($locale) ? $locale : 'en');

        $result = ['error' => 0, 'savedPath' => ''];

        if($this->validatedWithError(Runtime::request(), [
            'chunk_total'            => 'required',
            'chunk_index'            => 'required',
            'resource_temp_basename' => 'required',
            'resource_ext'           => 'required',
            'group_subdir'           => 'required',
            'group'                  => 'required',
            'resource_hash'          => 'present',
        ])){
            return Responser::reportError($result, Runtime::trans('invalid_resource_params'));
        }

        $chunkTotalCount = Runtime::request()->input('chunk_total');
        $chunkIndex = Runtime::request()->input('chunk_index');
        $resourceTempBaseName = Runtime::request()->input('resource_temp_basename');
        $resourceExt = Runtime::request()->input('resource_ext');
        $chunk = Runtime::request()->file('resource_chunk');
        $groupSubDir = Runtime::request()->input('group_subdir');
        $resourceHash = Runtime::request()->input('resource_hash');
        $group = Runtime::request()->input('group');
        $partialResource = null;

        // security: whitelist client-controlled path components to prevent directory traversal
        // is_string() 前置：isSafePathComponent() 内部的 (string) 转换会把数组变成合法字符串 "Array"
        foreach ( ['group_subdir' => $groupSubDir, 'resource_temp_basename' => $resourceTempBaseName, 'resource_ext' => $resourceExt] as $name => $value ) {
            if ( ! is_string($value) || Util::isSafePathComponent($value, false, 64) === false ) {
                return Responser::reportError($result, Runtime::trans('invalid_resource_params'));
            }
        }

        // group_subdir 与 group 一样参与 savedPath 的 '_' 分隔拼接（SavedPathResolver::encode），
        // 含下划线会让 decode 错位、资源永久 404，服务端自己生成的取值不含下划线
        if ( str_contains($groupSubDir, '_') ) {
            return Responser::reportError($result, Runtime::trans('invalid_resource_params'));
        }

        // security: reject non-numeric chunk parameters, otherwise garbage casts to 0 and silently "succeeds"
        // is_scalar() 前置：数组进 (string) 会抛 "Array to string conversion" 警告
        if ( ! is_scalar($chunkIndex) || ! is_scalar($chunkTotalCount) || ! ctype_digit((string)$chunkIndex) || ! ctype_digit((string)$chunkTotalCount) || (int)$chunkIndex < 1 || (int)$chunkTotalCount < 1 ) {
            return Responser::reportError($result, Runtime::trans('invalid_resource_params'));
        }

        // security: cap the total number of chunks to avoid resource-exhaustion flooding
        if ( (int)$chunkTotalCount > 10000 ) {
            return Responser::reportError($result, Runtime::trans('invalid_resource_params'));
        }

        try{

            ConfigMapper::applyGroupConfig($group);

            // 快照 group 配置，避免并发请求覆盖单例后读到其它组的设置
            $eventBeforeUploadComplete = ConfigMapper::get('event_before_upload_complete');
            $eventUploadComplete = ConfigMapper::get('event_upload_complete');
            $resourceExtensions = ConfigMapper::get('resource_extensions');

            // security: resource_hash 是客户端可控值，仅在非空（秒传开启）时才会拼成 redis 字段名，
            // 这里只做安全检查，不再无条件计算 key —— 宽松模式下客户端提交空 hash，不能因此报错
            // is_string() 前置：数组会被 (string) 转成 "Array" 通过白名单，拼出 file_Array 这种垃圾 key
            if ( ! empty($resourceHash) && ( ! is_string($resourceHash) || Util::isSafePathComponent($resourceHash, false, 64) === false ) ) {
                throw new \Exception(Runtime::trans('invalid_operation'));
            }

            $partialResource = new PartialResource($resourceTempBaseName, $resourceExt, $groupSubDir);

            // security: resource_ext is client-controlled, apply the same extension filter as preprocess
            $partialResource->filterByExtension($resourceExt);

            // when the whitelist is empty, filterByExtension only consults the blacklist,
            // so additionally hard-reject clearly executable extensions;
            // partial overlap with the forbidden_extensions config default is intentional - this list works even if that config is emptied
            if ( empty($resourceExtensions) && in_array($resourceExt, ['php', 'phtml', 'php3', 'php4', 'php5', 'phps', 'pht', 'shtml', 'shtm', 'jsp', 'asp', 'aspx', 'cgi', 'sh'], true) ) {
                throw new \Exception(Runtime::trans('invalid_resource_type'));
            }

            // determine if this upload meets the condition of instant completion,
            // checked before exists() so re-sending the final chunk after completion is idempotent
            if ( ConfigMapper::get('instant_completion') === true && ! empty($resourceHash) ) {
                try {
                    $savedPath = RedisSavedPath::get($savedPathKey = RedisSavedPath::getKey($group, $resourceHash));
                } catch ( \Exception $e ) {
                    $savedPath = null;
                }

                if ( ! empty($savedPath) ) {
                    if ( $partialResource->exists() ) {
                        $partialResource->cleanup();
                    }

                    $result['savedPath'] = $savedPath;

                    return Responser::returnResult($result);
                }
            }

            // do a check to prevent security intrusions
            if ( $partialResource->exists() === false ) {
                throw new \Exception(Runtime::trans('invalid_operation'));
            }

            // 分块本身无效（如网络抖动导致上传被截断）不应销毁已拼好的进度，直接报错让客户端重传该分块
            if ( ! $chunk || $chunk->isValid() === false ) {
                return Responser::reportError($result, Runtime::trans('upload_error'));
            }

            // validate the data in header file to avoid the errors when network issue occurs
            $lastChunkIndex = (int)($partialResource->chunkIndex);
            if ( (int)$chunkIndex <= $lastChunkIndex ) {
                return Responser::returnResult($result);
            }
            if ( (int)$chunkIndex > $lastChunkIndex + 1 ) {
                // a chunk is missing in between, report the error instead of silently returning success
                return Responser::reportError($result, Runtime::trans('upload_error'));
            }

            // security: enforce the size limit incrementally, not only at completion
            $partialResource->filterBySize(filesize($partialResource->realPath) + filesize($chunk->getRealPath()));

            $partialResource->append($chunk->getRealPath());

            $partialResource->chunkIndex = $chunkIndex;

            // determine if the resource file is completed
            if ( (int)$chunkIndex === (int)$chunkTotalCount ) {

                $partialResource->checkSize();

                $partialResource->checkMimeType();

                // trigger the event before an upload completes
                if ( $eventBeforeUploadComplete === true ) {
                    Runtime::events()->emit('aetherupload.before_upload_complete', $partialResource);
                }

                $resourceRealHash = $partialResource->calculateHash();

                if ( ConfigMapper::get('lax_mode') === false && $resourceHash !== $resourceRealHash ) {
                    throw new \Exception(Runtime::trans('upload_error'));
                }

                $partialResource->rename($completeName = Util::getFileName($resourceRealHash, $resourceExt));

                $savedPath = SavedPathResolver::encode($group, $groupSubDir, $completeName);

                // 秒传记录只在客户端提供了 hash 时才有意义，空 hash 写入会污染后续上传
                if ( ConfigMapper::get('instant_completion') === true && ! empty($resourceHash) ) {
                    RedisSavedPath::set(RedisSavedPath::getKey($group, $resourceHash), $savedPath);
                }

                unset($partialResource->chunkIndex);

                // trigger the event when an upload completes
                if ( $eventUploadComplete === true ) {
                    Runtime::events()->emit('aetherupload.upload_complete', new Resource($group, $partialResource->groupDir, $groupSubDir, $completeName));
                }

                $result['savedPath'] = $savedPath;
            }

        } catch ( \Exception $e ) {

            if ( $partialResource !== null ) {
                $partialResource->cleanup();
            }

            return $this->fail($result, $e);
        }

        return Responser::returnResult($result);

    }



}
