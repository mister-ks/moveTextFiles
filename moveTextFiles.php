<?php

function moveTextFiles($sourceDir, $destinationDir, $move = false)
{
    $sourceDir = rtrim($sourceDir, DIRECTORY_SEPARATOR);
    $destinationDir = rtrim($destinationDir, DIRECTORY_SEPARATOR);

    if (!is_dir($sourceDir)) {
        echo "ソースディレクトリが存在しません: $sourceDir\n";
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'txt') {
            $relativePath = substr($fileInfo->getPathname(), strlen($sourceDir) + 1);
            $targetPath = $destinationDir . DIRECTORY_SEPARATOR . dirname($relativePath);

            if (!is_dir($targetPath)) {
                mkdir($targetPath, 0777, true);
            }

            $destFile = $destinationDir . DIRECTORY_SEPARATOR . $relativePath;

            if ($move) {
                rename($fileInfo->getPathname(), $destFile);
                echo "Moved: " . $fileInfo->getPathname() . " -> $destFile\n";
            } else {
                copy($fileInfo->getPathname(), $destFile);
                echo "Copied: " . $fileInfo->getPathname() . " -> $destFile\n";
            }
        }
    }
}

// ----------------------------
// デフォルトのパス設定
// ----------------------------
$defaultSource = 'F:\\Documents\\VSCodeProjects\\phpbat';
$defaultDest = 'E:\\G：￥￥\\Logs\\phpbat';
$defaultMove = true;

// ----------------------------
// 引数処理（なければデフォルト）
// ----------------------------
$source = $argc > 1 ? $argv[1] : $defaultSource;
$dest = $argc > 2 ? $argv[2] : $defaultDest;
$moveFlag = $argc > 3 ? $argv[3] === 'move' : $defaultMove;

moveTextFiles($source, $dest, $moveFlag);
