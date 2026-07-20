<?php

use MathiasGrimm\GlimpseCli\Updater\GithubReleasesStrategy;

return [

    /*
    |--------------------------------------------------------------------------
    | Self-updater Strategy
    |--------------------------------------------------------------------------
    |
    | The strategy used by the "self-update" command to locate new versions
    | of the application. Versions are discovered through Packagist and the
    | new PHAR is downloaded from the "glimpse" asset attached to the GitHub
    | release of the matching tag. The build must be stamped with the tag
    | name verbatim (see README), since the updater compares build version
    | and tag as plain strings.
    |
    */

    'strategy' => GithubReleasesStrategy::class,

];
