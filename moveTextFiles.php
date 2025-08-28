<?php
/**
 * moveTextFiles.php
 * 複数フォルダ対応・JSONC設定対応版
 * - config.jsonc を自動検出（__DIR__ → .\ の順）。--config=... 指定があれば最優先。
 * - preserveStructure が true の場合も「対象ファイルがあるフォルダのみ」出力側に作成。
 */
declare(strict_types=1);

function stripJsonComments( string $jsonWithComments ): string
{
    $noBlock = preg_replace( '#/\*.*?\*/#s', '', $jsonWithComments );
    if ( $noBlock === null )
    {
        $noBlock = $jsonWithComments;
    }
    $lines = preg_split( "/\R/u", $noBlock );
    if ( $lines === false )
    {
        $lines = [ $noBlock ];
    }
    $outLines = [];
    foreach ( $lines as $line )
    {
        $inString = false;
        $escaped = false;
        $result = '';
        $len = strlen( $line );
        for ( $i = 0; $i < $len; $i++ )
        {
            $ch = $line[$i];
            $next = ( $i + 1 < $len ) ? $line[$i + 1] : '';
            if ( $ch === '"' && !$escaped )
            {
                $inString = !$inString;
                $result .= $ch;
                continue;
            }
            if ( $ch === '\\' && !$escaped )
            {
                $escaped = true;
                $result .= $ch;
                continue;
            }
            else
            {
                $escaped = false;
            }
            if ( !$inString && $ch === '/' && $next === '/' )
            {
                break;
            }
            $result .= $ch;
        }
        $outLines[] = $result;
    }
    return implode( PHP_EOL, $outLines );
}

function loadJsonc( string $path ): array
{
    if ( !is_file( $path ) )
    {
        throw new RuntimeException( "設定ファイルが見つかりません: {$path}" );
    }
    $raw = file_get_contents( $path );
    if ( $raw === false )
    {
        throw new RuntimeException( "設定ファイルの読み込みに失敗しました: {$path}" );
    }
    $json = stripJsonComments( $raw );
    $data = json_decode( $json, true, flags: JSON_THROW_ON_ERROR );
    if ( !is_array( $data ) )
    {
        throw new RuntimeException( "設定ファイルの形式が不正です: {$path}" );
    }
    return $data;
}

function rtrimSeparator( string $path ): string
{
    return rtrim( $path, "/\\" );
}

function hasAllowedExtension( string $filePath, array $extList ): bool
{
    if ( empty( $extList ) )
    {
        return true;
    }
    $ext = strtolower( pathinfo( $filePath, PATHINFO_EXTENSION ) );
    return in_array( $ext, array_map( 'strtolower', $extList ), true );
}

function isHidden( string $path ): bool
{
    $base = basename( $path );
    if ( $base === '' || $base === '.' || $base === '..' )
    {
        return false;
    }
    return str_starts_with( $base, '.' );
}

function uniqueDestinationPath( string $destPath ): string
{
    if ( !file_exists( $destPath ) )
    {
        return $destPath;
    }
    $dir = dirname( $destPath );
    $name = pathinfo( $destPath, PATHINFO_FILENAME );
    $ext = pathinfo( $destPath, PATHINFO_EXTENSION );
    $suffix = date( 'Ymd_His' );
    $candidate = $dir . DIRECTORY_SEPARATOR . $name . '_' . $suffix . ( $ext !== '' ? ".{$ext}" : '' );
    $idx = 1;
    while ( file_exists( $candidate ) )
    {
        $candidate = $dir . DIRECTORY_SEPARATOR . $name . '_' . $suffix . "_{$idx}" . ( $ext !== '' ? ".{$ext}" : '' );
        $idx++;
    }
    return $candidate;
}

/**
 * ディレクトリは「必要になったときだけ」作成する方針に変更
 * - preserveStructure=true でも、対象ファイル出力直前に親ディレクトリを mkdir する
 */
function processOneTarget( string $sourceDir, string $destinationDir, bool $doMove, array $includeExtensions, bool $preserveStructure, int $maxDepth, bool $skipHiddenEntries, bool $dryRun, array $includePatterns, array $excludePatterns ): void
{
    $sourceDir = rtrimSeparator( $sourceDir );
    $destinationDir = rtrimSeparator( $destinationDir );

    if ( !is_dir( $sourceDir ) )
    {
        fwrite( STDERR, "[WARN] ソースディレクトリが存在しません: {$sourceDir}" . PHP_EOL );
        return;
    }

    // ルート出力先だけは用意（ここは必須土台）
    if ( !is_dir( $destinationDir ) )
    {
        if ( $dryRun )
        {
            echo "[DRYRUN] 出力先作成: {$destinationDir}" . PHP_EOL;
        }
        else
        {
            if ( !mkdir( $destinationDir, 0777, true ) && !is_dir( $destinationDir ) )
            {
                throw new RuntimeException( "出力先ディレクトリの作成に失敗: {$destinationDir}" );
            }
        }
    }

    $iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $sourceDir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
    $iterator->setMaxDepth( $maxDepth >= 0 ? $maxDepth : -1 );

    foreach ( $iterator as $item )
    {
        $path = ( string )$item;
        $rel = ltrim( substr( $path, strlen( $sourceDir ) ), '/\\' );

        if ( $skipHiddenEntries && isHidden( $path ) )
        {
            continue;
        }

        // ディレクトリ訪問時の事前生成はしない（ここが今回の変更点）
        if ( $item->isDir() )
        {
            continue;
        }

        // 拡張子フィルタ
        if ( !hasAllowedExtension( $path, $includeExtensions ) )
        {
            continue;
        }

        // include / exclude パターン
        $basename = basename( $path );
        $allow = true;

        if ( !empty( $includePatterns ) )
        {
            $allow = false;
            foreach ( $includePatterns as $pat )
            {
                $m = @preg_match( $pat, $basename );
                if ( $m === 1 )
                {
                    $allow = true;
                    break;
                }
                elseif ( $m === false )
                {
                    fwrite( STDERR, "[WARN] includePatterns の正規表現が不正です: {$pat}" . PHP_EOL );
                }
            }
            if ( !$allow )
            {
                continue;
            }
        }

        if ( !empty( $excludePatterns ) )
        {
            foreach ( $excludePatterns as $pat )
            {
                $m = @preg_match( $pat, $basename );
                if ( $m === 1 )
                {
                    $allow = false;
                    break;
                }
                elseif ( $m === false )
                {
                    fwrite( STDERR, "[WARN] excludePatterns の正規表現が不正です: {$pat}" . PHP_EOL );
                }
            }
            if ( !$allow )
            {
                continue;
            }
        }

        // 出力先パス算出
        $destPath = $preserveStructure ? ( $destinationDir . DIRECTORY_SEPARATOR . $rel ) : ( $destinationDir . DIRECTORY_SEPARATOR . $basename );

        // 出力先の「親ディレクトリ」だけを、ここでオンデマンド作成
        $destParent = dirname( $destPath );
        if ( !is_dir( $destParent ) )
        {
            if ( $dryRun )
            {
                echo "[DRYRUN] 生成(Dir): {$destParent}" . PHP_EOL;
            }
            else
            {
                if ( !mkdir( $destParent, 0777, true ) && !is_dir( $destParent ) )
                {
                    fwrite( STDERR, "[ERROR] 出力先ディレクトリの作成に失敗: {$destParent}" . PHP_EOL );
                    continue;
                }
            }
        }

        // 競合回避
        if ( file_exists( $destPath ) )
        {
            $destPath = uniqueDestinationPath( $destPath );
        }

        // 実処理（またはドライラン表示）
        if ( $dryRun )
        {
            echo "[DRYRUN] " . ( $doMove ? "MOVE" : "COPY" ) . " : {$path}  =>  {$destPath}" . PHP_EOL;
            continue;
        }

        if ( $doMove )
        {
            if ( !@rename( $path, $destPath ) )
            {
                if ( !@copy( $path, $destPath ) )
                {
                    fwrite( STDERR, "[ERROR] コピー失敗: {$path} => {$destPath}" . PHP_EOL );
                    continue;
                }
                if ( !@unlink( $path ) )
                {
                    fwrite( STDERR, "[WARN] 元ファイル削除に失敗: {$path}" . PHP_EOL );
                }
            }
            echo "[DONE] MOVED: {$path} => {$destPath}" . PHP_EOL;
        }
        else
        {
            if ( !@copy( $path, $destPath ) )
            {
                fwrite( STDERR, "[ERROR] コピー失敗: {$path} => {$destPath}" . PHP_EOL );
                continue;
            }
            echo "[DONE] COPIED: {$path} => {$destPath}" . PHP_EOL;
        }
    }
}

function parseArgvForConfigPath( array $argv ): ?string
{
    foreach ( $argv as $a )
    {
        if ( str_starts_with( $a, '--config=' ) )
        {
            $val = substr( $a, 9 );
            return $val !== '' ? $val : null;
        }
    }
    return null;
}

function autoResolveConfigPath(): ?string
{
    $candidates = [ __DIR__ . DIRECTORY_SEPARATOR . 'config.jsonc', getcwd() . DIRECTORY_SEPARATOR . 'config.jsonc' ];
    foreach ( $candidates as $p )
    {
        if ( is_file( $p ) )
        {
            return $p;
        }
    }
    return null;
}

function runWithConfig( string $configPath ): void
{
    $cfg = loadJsonc( $configPath );
    $commonIncludeExtensions = $cfg['includeExtensions'] ?? [ 'txt', 'log' ];
    $commonPreserveStructure = ( bool )( $cfg['preserveStructure'] ?? true );
    $commonMaxDepth = ( int )( $cfg['maxDepth'] ?? -1 );
    $commonSkipHidden = ( bool )( $cfg['skipHidden'] ?? true );
    $commonDryRun = ( bool )( $cfg['dryRun'] ?? false );
    $commonIncludePatterns = $cfg['includePatterns'] ?? [];
    $commonExcludePatterns = $cfg['excludePatterns'] ?? [];

    if ( empty( $cfg['targets'] ) || !is_array( $cfg['targets'] ) )
    {
        throw new RuntimeException( "config.jsonc の 'targets' 配列が未定義または不正です。" );
    }

    foreach ( $cfg['targets'] as $i => $t )
    {
        if ( !is_array( $t ) )
        {
            fwrite( STDERR, "[WARN] targets[$i] はオブジェクトである必要があります。" . PHP_EOL );
            continue;
        }

        $enabled = array_key_exists( 'enabled', $t ) ? ( bool )$t['enabled'] : true;
        if ( !$enabled )
        {
            echo "[SKIP] Target #" . ( $i + 1 ) . " は enabled=false のためスキップします。" . PHP_EOL;
            continue;
        }

        $sourceDir = $t['sourceDir'] ?? null;
        $destinationDir = $t['destinationDir'] ?? null;
        if ( !$sourceDir || !$destinationDir )
        {
            fwrite( STDERR, "[WARN] targets[$i] に sourceDir / destinationDir がありません。" . PHP_EOL );
            continue;
        }

        $doMove = isset( $t['mode'] ) ? ( strtolower( ( string )$t['mode'] ) === 'move' ) : true;
        $includeExtensions = $t['includeExtensions'] ?? $commonIncludeExtensions;
        $preserveStructure = isset( $t['preserveStructure'] ) ? ( bool )$t['preserveStructure'] : $commonPreserveStructure;
        $maxDepth = isset( $t['maxDepth'] ) ? ( int )$t['maxDepth'] : $commonMaxDepth;
        $skipHidden = isset( $t['skipHidden'] ) ? ( bool )$t['skipHidden'] : $commonSkipHidden;
        $dryRun = isset( $t['dryRun'] ) ? ( bool )$t['dryRun'] : $commonDryRun;
        $includePatterns = $t['includePatterns'] ?? $commonIncludePatterns;
        $excludePatterns = $t['excludePatterns'] ?? $commonExcludePatterns;

        echo "=== Target #" . ( $i + 1 ) . " ===" . PHP_EOL;
        echo "enabled           : " . ( $enabled ? "true" : "false" ) . PHP_EOL;
        echo "sourceDir         : {$sourceDir}" . PHP_EOL;
        echo "destinationDir    : {$destinationDir}" . PHP_EOL;
        echo "mode              : " . ( $doMove ? "move" : "copy" ) . PHP_EOL;
        echo "includeExtensions : " . ( empty( $includeExtensions ) ? "(all)" : implode( ',', $includeExtensions ) ) . PHP_EOL;
        echo "preserveStructure : " . ( $preserveStructure ? "true" : "false" ) . PHP_EOL;
        echo "maxDepth          : {$maxDepth}" . PHP_EOL;
        echo "skipHidden        : " . ( $skipHidden ? "true" : "false" ) . PHP_EOL;
        echo "dryRun            : " . ( $dryRun ? "true" : "false" ) . PHP_EOL;
        echo "includePatterns   : " . ( empty( $includePatterns ) ? "(none)" : implode( ' | ', $includePatterns ) ) . PHP_EOL;
        echo "excludePatterns   : " . ( empty( $excludePatterns ) ? "(none)" : implode( ' | ', $excludePatterns ) ) . PHP_EOL;

        processOneTarget( $sourceDir, $destinationDir, $doMove, $includeExtensions, $preserveStructure, $maxDepth, $skipHidden, $dryRun, $includePatterns, $excludePatterns );
    }
}

function runLegacyFallback(): void
{
    fwrite( STDERR, "[WARN] config.jsonc が見つからないためレガシーモードで実行します（phpbat のみ）。" . PHP_EOL );
    $defaultSource = 'F:\\Documents\\VSCodeProjects\\phpbat';
    $defaultDest = 'E:\\Logs\\phpbat';
    $defaultMove = true;
    processOneTarget( $defaultSource, $defaultDest, $defaultMove, [ 'txt', 'log' ], true, -1, true, false, [], [] );
}

function main( array $argv ): void
{
    $configFromArg = parseArgvForConfigPath( $argv );
    if ( $configFromArg !== null )
    {
        runWithConfig( $configFromArg );
        return;
    }
    $auto = autoResolveConfigPath();
    if ( $auto !== null )
    {
        echo "[INFO] 自動検出した設定ファイルを使用します: {$auto}" . PHP_EOL;
        runWithConfig( $auto );
        return;
    }
    runLegacyFallback();
}

try
{
    main( $argv );
}
catch ( Throwable $e )
{
    fwrite( STDERR, "[FATAL] " . $e->getMessage() . PHP_EOL );
    exit( 1 );
}
