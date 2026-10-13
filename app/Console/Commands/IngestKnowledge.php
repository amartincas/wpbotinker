<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Store;
use App\Platform\Domains\DomainRegistry;
use App\Platform\Knowledge\KnowledgeIngestor;
use FilesystemIterator;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Loads knowledge files (Markdown, HTML, text) for one store and domain
 * through KnowledgeIngestor: same dedup, versioning by origin and
 * retirement as any other ingestion. Never prints file contents.
 */
class IngestKnowledge extends Command
{
    protected $signature = 'botinker:knowledge:ingest
        {store : ID de la tienda}
        {domain : Clave del dominio registrado}
        {path : Archivo o carpeta (.md, .markdown, .html, .htm, .txt)}
        {--product= : ID del producto (por defecto, conocimiento de todo el dominio)}
        {--title= : Título de la fuente (solo para un archivo; por defecto, el nombre del archivo)}
        {--origin= : Identificador estable de la fuente (solo para un archivo; por defecto, su ruta relativa)}
        {--license= : Licencia o procedencia del contenido, p. ej. "propio"}
        {--language=es : Idioma del contenido}
        {--type= : Forzar el tipo: markdown, html o text}';

    protected $description = 'Ingesta archivos de conocimiento para una tienda y dominio (Markdown, HTML o texto)';

    private const TYPES_BY_EXTENSION = [
        'md' => 'markdown',
        'markdown' => 'markdown',
        'html' => 'html',
        'htm' => 'html',
        'txt' => 'text',
    ];

    private const MAX_FILE_BYTES = 2 * 1024 * 1024;

    public function handle(KnowledgeIngestor $ingestor, DomainRegistry $domains): int
    {
        $store = Store::find($this->argument('store'));

        if ($store === null) {
            $this->error("No existe la tienda [{$this->argument('store')}].");

            return self::FAILURE;
        }

        $domainKey = (string) $this->argument('domain');

        if (! $domains->has($domainKey)) {
            $this->error("El dominio [{$domainKey}] no está registrado en config/botinker.php.");

            return self::FAILURE;
        }

        $product = null;

        if ($this->option('product') !== null) {
            $product = Product::where('store_id', $store->id)->where('domain_key', $domainKey)->find($this->option('product'));

            if ($product === null) {
                $this->error("El producto [{$this->option('product')}] no existe en la tienda [{$store->id}] y el dominio [{$domainKey}].");

                return self::FAILURE;
            }
        }

        $type = $this->option('type');

        if ($type !== null && ! in_array($type, self::TYPES_BY_EXTENSION, true)) {
            $this->error("Tipo [{$type}] no soportado. Usa markdown, html o text.");

            return self::FAILURE;
        }

        $path = (string) $this->argument('path');

        if (! file_exists($path) || ! is_readable($path)) {
            $this->error('La ruta no existe o no se puede leer.');

            return self::FAILURE;
        }

        $isFolder = is_dir($path);

        if ($isFolder && ($this->option('title') !== null || $this->option('origin') !== null)) {
            $this->error('--title y --origin solo se pueden usar con un archivo, no con una carpeta.');

            return self::FAILURE;
        }

        $files = $isFolder ? $this->filesIn($path) : [basename($path) => new SplFileInfo($path)];

        if (! $isFolder && $type === null && $this->typeOf($files[basename($path)]) === null) {
            $this->error('Extensión no soportada. Usa .md, .markdown, .html, .htm o .txt, o indica --type.');

            return self::FAILURE;
        }

        $failed = 0;

        foreach ($files as $relativePath => $file) {
            $fileType = $type ?? $this->typeOf($file);

            if ($fileType === null) {
                $this->warn("Omitido (extensión no soportada): {$relativePath}");

                continue;
            }

            $content = $this->readFile($file, $relativePath);

            if ($content === null) {
                $failed++;

                continue;
            }

            try {
                $source = $ingestor->ingest(
                    $store,
                    $domainKey,
                    $product,
                    $this->option('title') ?? pathinfo($relativePath, PATHINFO_FILENAME),
                    $fileType,
                    $content,
                    $this->option('origin') ?? $relativePath,
                    $this->option('license'),
                    (string) $this->option('language'),
                );
            } catch (InvalidArgumentException $e) {
                $this->error("{$relativePath}: {$e->getMessage()}");
                $failed++;

                continue;
            }

            $this->line($source->wasRecentlyCreated
                ? "{$relativePath}: fuente #{$source->id} v{$source->version} ({$source->chunks()->count()} fragmentos)"
                : "{$relativePath}: sin cambios (ya existe como fuente #{$source->id} v{$source->version})");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Files of a folder and its subfolders (symlinks are not followed),
     * keyed by path relative to the folder, in a stable order.
     *
     * @return array<string, SplFileInfo>
     */
    private function filesIn(string $folder): array
    {
        $root = rtrim(realpath($folder), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && ! $file->isLink()) {
                $files[str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root)))] = $file;
            }
        }

        ksort($files);

        return $files;
    }

    private function typeOf(SplFileInfo $file): ?string
    {
        return self::TYPES_BY_EXTENSION[strtolower($file->getExtension())] ?? null;
    }

    private function readFile(SplFileInfo $file, string $relativePath): ?string
    {
        if ($file->getSize() > self::MAX_FILE_BYTES) {
            $this->error("{$relativePath}: supera el tamaño máximo de ".(self::MAX_FILE_BYTES / 1024 / 1024).' MB.');

            return null;
        }

        $content = file_get_contents($file->getPathname());

        if ($content === false || ! mb_check_encoding($content, 'UTF-8')) {
            $this->error("{$relativePath}: no se pudo leer como texto UTF-8.");

            return null;
        }

        return $content;
    }
}
