<?php
// Minimal .env loader. The .env file lives in the project root, outside public/.

function env_load(string $file): void
{
    $vars = &env_store();
    if (!is_readable($file)) {
        return;
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        $len = strlen($val);
        if ($len >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[$len - 1] === $val[0]) {
            $val = substr($val, 1, -1);
        }
        if ($key !== '') {
            $vars[$key] = $val;
        }
    }
}

function &env_store(): array
{
    static $vars = [];
    return $vars;
}

function env(string $key, $default = null)
{
    $vars = &env_store();
    if (array_key_exists($key, $vars)) {
        return $vars[$key];
    }
    $v = getenv($key);
    return $v === false ? $default : $v;
}
