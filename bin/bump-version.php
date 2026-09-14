#!/usr/bin/env php
<?php
// バージョン同期スクリプト（AGENTS.md「Version sync」5箇所）。
// Usage:
//   php bin/bump-version.php 1.1.3            # 明示指定
//   php bin/bump-version.php --level=patch    # 現行からpatch+1（minor/major可）
//
// Makefile から呼ぶこと（make bump / make bump LEVEL=minor / make bump V=2.0.0）。
// php -r ワンライナーにしない理由: バックスラッシュを含む正規表現が
// make→shell→php の引用層で化けるため。ファイルにすれば安全。

declare(strict_types=1);

function currentVersion(): string
{
    $c = json_decode((string) file_get_contents('composer.json'), true);
    return is_array($c) && isset($c['version']) ? (string) $c['version'] : '0.0.0';
}

function nextVersion(string $cur, string $level): string
{
    $parts = array_map('intval', explode('.', $cur) + [0, 0, 0]);
    [$major, $minor, $patch] = [$parts[0], $parts[1], $parts[2]];
    if ($level === 'major') {
        $major++;
        $minor = 0;
        $patch = 0;
    } elseif ($level === 'minor') {
        $minor++;
        $patch = 0;
    } else {
        $patch++;
    }
    return "{$major}.{$minor}.{$patch}";
}

$explicit = null;
$level = 'patch';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--level=')) {
        $level = substr($arg, strlen('--level='));
    } elseif ($arg !== '') {
        $explicit = $arg;
    }
}

$cur = currentVersion();
$new = $explicit ?? nextVersion($cur, $level);
if (!preg_match('/^\d+\.\d+\.\d+$/', $new)) {
    fwrite(STDERR, "invalid version: {$new}\n");
    exit(1);
}

// 1. composer.json（管理画面アップロードが version 必須のため削除不可）
$f = 'composer.json';
$j = (string) file_get_contents($f);
$j = (string) preg_replace('/("version":\s*")[^"]+"/', '${1}' . $new . '"', $j, 1);
file_put_contents($f, $j);

// 2. eccube-plugin.yaml
$f = 'eccube-plugin.yaml';
file_put_contents($f, (string) preg_replace('/^version: .*/m', "version: {$new}", (string) file_get_contents($f), 1));

// 3. SERVER_VERSION
$f = 'Service/McpHttpService.php';
file_put_contents($f, (string) preg_replace(
    "/public const SERVER_VERSION = '[^']*';/",
    "public const SERVER_VERSION = '{$new}';",
    (string) file_get_contents($f),
    1
));

// 4. CHANGELOG（先頭に空エントリ。内容は手で追記する）
$f = 'Documents/CHANGELOG.md';
$c = (string) file_get_contents($f);
$entry = "## [{$new}] - " . date('Y-m-d') . "\n\n### Fixed\n- （追記してください）\n\n";
$c = (string) preg_replace('/(# Changelog\n\n)/', '${1}' . $entry, $c, 1);
file_put_contents($f, $c);

// 5. README バッジ
$f = 'README.md';
file_put_contents($f, (string) preg_replace(
    '/(badge\/version-)[0-9]+\.[0-9]+\.[0-9]+(-blue)/',
    '${1}' . $new . '${2}',
    (string) file_get_contents($f),
    1
));

echo "bump {$cur} -> {$new}\n";
