<?php

namespace App\Platform\Knowledge;

use InvalidArgumentException;

/**
 * The file rules of knowledge ingestion, shared by every way a file comes
 * in (console command, panel upload): which extensions are accepted, the
 * size limit and the encoding. Messages never include the file content.
 */
final class KnowledgeFile
{
    public const TYPES_BY_EXTENSION = [
        'md' => 'markdown',
        'markdown' => 'markdown',
        'html' => 'html',
        'htm' => 'html',
        'txt' => 'text',
    ];

    public const MAX_BYTES = 2 * 1024 * 1024;

    /** The source type for a file name, or null if its extension is not supported. */
    public static function typeFor(string $fileName): ?string
    {
        return self::TYPES_BY_EXTENSION[strtolower(pathinfo($fileName, PATHINFO_EXTENSION))] ?? null;
    }

    /**
     * The file's text, after checking its size and encoding.
     *
     * @throws InvalidArgumentException with a message fit for the operator
     */
    public static function read(string $path): string
    {
        $size = @filesize($path);

        if ($size !== false && $size > self::MAX_BYTES) {
            throw new InvalidArgumentException('supera el tamaño máximo de '.(self::MAX_BYTES / 1024 / 1024).' MB.');
        }

        $content = @file_get_contents($path);

        if ($content === false || ! mb_check_encoding($content, 'UTF-8')) {
            throw new InvalidArgumentException('no se pudo leer como texto UTF-8.');
        }

        return $content;
    }
}
