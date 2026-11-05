<?php
// Domain normalisation: lowercase, strip scheme/credentials/path/port/trailing dot, www. == bare.

function licence_normalise_domain(string $d): string
{
    $d = strtolower(trim($d));
    $d = preg_replace('#^[a-z][a-z0-9+.\-]*://#', '', $d);
    $d = preg_split('#[/?\\#]#', $d, 2)[0];
    $at = strrpos($d, '@');
    if ($at !== false) {
        $d = substr($d, $at + 1);
    }
    $d = preg_replace('#:\d+$#', '', $d);
    $d = rtrim($d, '.');
    if (strpos($d, 'www.') === 0) {
        $d = substr($d, 4);
    }
    return $d;
}

function licence_domain_valid(string $normalised): bool
{
    return (bool)preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $normalised);
}
