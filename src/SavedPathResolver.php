<?php

namespace AetherUpload;

class SavedPathResolver
{

    public static function encode($group, $groupSubDir, $name)
    {
        return $group . '_' . $groupSubDir . '_' . $name;
    }

    public static function decode($savedPath)
    {
        $parts = explode('_', $savedPath, 3);

        if ( count($parts) !== 3 ) {
            throw new \Exception(trans('invalid_operation'));
        }

        foreach ( $parts as $field ) {
            if ( preg_match('/^[a-zA-Z0-9_\-][a-zA-Z0-9_\-\.]*$/', $field) !== 1 ) {
                throw new \Exception(trans('invalid_operation'));
            }
        }

        list($group, $groupSubDir, $name) = $parts;

        return (object)[
            'group'        => $group,
            'groupSubDir'  => $groupSubDir,
            'resourceName' => $name,
        ];
    }

}