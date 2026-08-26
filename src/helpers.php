<?php

if ( ! function_exists('aetherupload_display_link') ) {
    function aetherupload_display_link($savedPath)
    {
        return \AetherUpload\Util::getDisplayLink($savedPath);
    }
}

if ( ! function_exists('aetherupload_download_link') ) {
    function aetherupload_download_link($savedPath, $newName)
    {
        return \AetherUpload\Util::getDownloadLink($savedPath, $newName);
    }
}


