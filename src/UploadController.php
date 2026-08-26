<?php

namespace AetherUpload;

use support\Translation;

class UploadController
{
    use ExamplePageTrait,SimpleValidateTrait;

    public function __construct()
    {
        $translationPath = config('translation')['path'] ?? base_path() . DIRECTORY_SEPARATOR . 'resource' . DIRECTORY_SEPARATOR . 'translations';
        Translation::addResource('phpfile',$translationPath.DIRECTORY_SEPARATOR.'aetherupload'.DIRECTORY_SEPARATOR.'zh'.DIRECTORY_SEPARATOR.'messages.php','zh');
        Translation::addResource('phpfile',$translationPath.DIRECTORY_SEPARATOR.'aetherupload'.DIRECTORY_SEPARATOR.'en'.DIRECTORY_SEPARATOR.'messages.php','en');
    }

    /**
     * Preprocess the upload request
     */
    public function preprocess()
    {
        locale(request()->input('locale', 'en'));

        $result = [
            'error'                => 0,
            'chunkSize'            => 0,
            'groupSubDir'          => '',
            'resourceTempBaseName' => '',
            'resourceExt'          => '',
            'savedPath'            => '',
        ];

        if($this->validatedWithError(request(), [
            'resource_name' => 'required',
            'resource_size' => 'required',
            'group'         => 'required',
            'resource_hash' => 'present',
        ])){
            return Responser::reportError($result, trans('invalid_resource_params'));
        }

        $partialResource = null;

        try {

            $resourceName = request()->input('resource_name');
            $resourceSize = request()->input('resource_size');
            $resourceHash = request()->input('resource_hash');
            $group = request()->input('group');

            ConfigMapper::applyGroupConfig($group);

            $result['resourceTempBaseName'] = $resourceTempBaseName = Util::generateTempName();
            $result['resourceExt'] = $resourceExt = strtolower(pathinfo($resourceName, PATHINFO_EXTENSION));
            $result['groupSubDir'] = $groupSubDir = Util::generateSubDirName();
            $result['chunkSize'] = ConfigMapper::get('chunk_size');

            $partialResource = new PartialResource($resourceTempBaseName, $resourceExt, $groupSubDir);

            $partialResource->filterBySize($resourceSize);

            $partialResource->filterByExtension($resourceExt);

            // determine if this upload meets the condition of instant completion
            if ( ConfigMapper::get('instant_completion') === true && ! empty($resourceHash) ) {
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

            // cleanup partial files created before the failure to avoid orphans
            if ( $partialResource !== null ) {
                @unlink($partialResource->realPath);

                if ( $partialResource->header->exists() ) {
                    unset($partialResource->chunkIndex);
                }
            }

            $knownMessages = [trans('invalid_operation'), trans('invalid_resource_size'), trans('invalid_resource_type'), trans('create_subfolder_fail'), trans('create_resource_fail')];

            return Responser::reportError($result, in_array($e->getMessage(), $knownMessages, true) ? $e->getMessage() : trans('upload_error'));
        }

        return Responser::returnResult($result);
    }

    /**
     * Handle and save the uploaded chunks
     */
    public function saveChunk()
    {
        locale(request()->input('locale', 'en'));

        $result = ['error' => 0, 'savedPath' => ''];

        if($this->validatedWithError(request(), [
            'chunk_total'            => 'required',
            'chunk_index'            => 'required',
            'resource_temp_basename' => 'required',
            'resource_ext'           => 'required',
            'group_subdir'           => 'required',
            'group'                  => 'required',
            'resource_hash'          => 'present',
        ])){
            return Responser::reportError($result, trans('invalid_resource_params'));
        }

        $chunkTotalCount = request()->input('chunk_total');
        $chunkIndex = request()->input('chunk_index');
        $resourceTempBaseName = request()->input('resource_temp_basename');
        $resourceExt = request()->input('resource_ext');
        $chunk = request()->file('resource_chunk');
        $groupSubDir = request()->input('group_subdir');
        $resourceHash = request()->input('resource_hash');
        $group = request()->input('group');
        $partialResource = null;

        // security: whitelist client-controlled path components to prevent directory traversal
        $safePathComponentPattern = '/^[a-zA-Z0-9_\-]+$/';
        foreach ( ['group_subdir' => $groupSubDir, 'resource_temp_basename' => $resourceTempBaseName, 'resource_ext' => $resourceExt] as $name => $value ) {
            if ( preg_match($safePathComponentPattern, (string)$value) !== 1 ) {
                return Responser::reportError($result, trans('invalid_resource_params'));
            }
        }

        // security: cap the total number of chunks to avoid resource-exhaustion flooding
        if ( (int)$chunkTotalCount > 10000 ) {
            return Responser::reportError($result, trans('invalid_resource_params'));
        }

        try{

            ConfigMapper::applyGroupConfig($group);

            $savedPathKey = RedisSavedPath::getKey($group, $resourceHash);

            $partialResource = new PartialResource($resourceTempBaseName, $resourceExt, $groupSubDir);

            // security: resource_ext is client-controlled, apply the same extension filter as preprocess
            $partialResource->filterByExtension($resourceExt);

            // when the whitelist is empty, filterByExtension only consults the blacklist,
            // so additionally hard-reject clearly executable extensions
            if ( empty(ConfigMapper::get('resource_extensions')) && in_array($resourceExt, ['php', 'phtml', 'php3', 'php4', 'php5', 'phps', 'pht', 'shtml', 'shtm', 'jsp', 'asp', 'aspx', 'cgi', 'sh'], true) ) {
                throw new \Exception(trans('invalid_resource_type'));
            }

            // determine if this upload meets the condition of instant completion,
            // checked before exists() so re-sending the final chunk after completion is idempotent
            if ( ConfigMapper::get('instant_completion') === true && ! empty($resourceHash) ) {
                try {
                    $savedPath = RedisSavedPath::get($savedPathKey);
                } catch ( \Exception $e ) {
                    $savedPath = null;
                }

                if ( ! empty($savedPath) ) {
                    if ( $partialResource->exists() ) {
                        @unlink($partialResource->realPath);

                        if ( $partialResource->header->exists() ) {
                            unset($partialResource->chunkIndex);
                        }
                    }

                    $result['savedPath'] = $savedPath;

                    return Responser::returnResult($result);
                }
            }

            // do a check to prevent security intrusions
            if ( $partialResource->exists() === false ) {
                throw new \Exception(trans('invalid_operation'));
            }

            if ( ! $chunk || $chunk->isValid() === false ) {
                throw new \Exception(trans('upload_error'));
            }

            // validate the data in header file to avoid the errors when network issue occurs
            $lastChunkIndex = (int)($partialResource->chunkIndex);
            if ( (int)$chunkIndex <= $lastChunkIndex ) {
                // duplicate chunk, already saved - return success idempotently
                return Responser::returnResult($result);
            }
            if ( (int)$chunkIndex > $lastChunkIndex + 1 ) {
                // a chunk is missing in between, report the error instead of silently returning success
                return Responser::reportError($result, trans('upload_error'));
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
                if ( ConfigMapper::get('event_before_upload_complete') === true ) {
                    \Webman\Event\Event::emit('aetherupload.before_upload_complete', $partialResource);
                }

                $resourceRealHash = $partialResource->calculateHash();

                if ( ConfigMapper::get('lax_mode') === false && $resourceHash !== $resourceRealHash ) {
                    throw new \Exception(trans('upload_error'));
                }

                $partialResource->rename($completeName = Util::getFileName($resourceRealHash, $resourceExt));

                $savedPath = SavedPathResolver::encode($group, $groupSubDir, $completeName);

                if ( ConfigMapper::get('instant_completion') === true ) {
                    RedisSavedPath::set($savedPathKey, $savedPath);
                }

                unset($partialResource->chunkIndex);

                // trigger the event when an upload completes
                if ( ConfigMapper::get('event_upload_complete') === true ) {
                    \Webman\Event\Event::emit('aetherupload.upload_complete', new Resource($group, ConfigMapper::get('group_dir'), $groupSubDir, $completeName));
                }

                $result['savedPath'] = $savedPath;
            }

        } catch ( \Exception $e ) {

            if ( $partialResource !== null ) {
                @unlink($partialResource->realPath);

                if ( $partialResource->header->exists() ) {
                    unset($partialResource->chunkIndex);
                }
            }

            $knownMessages = [trans('invalid_operation'), trans('upload_error'), trans('invalid_resource_size'), trans('invalid_resource_type'), trans('missing_mimetype'), trans('write_resource_fail'), trans('rename_resource_fail'), trans('delete_resource_fail'), trans('create_header_fail'), trans('write_header_fail'), trans('read_header_fail'), trans('delete_header_fail')];

            return Responser::reportError($result, in_array($e->getMessage(), $knownMessages, true) ? $e->getMessage() : trans('upload_error'));
        }

        return Responser::returnResult($result);

    }



}
