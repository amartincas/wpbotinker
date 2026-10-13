<?php

/*
 * B2 — knowledge ingestion commands. All files are written by the test into
 * a temporary folder (original text, no third-party content).
 */

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Tests\Support\Domains\FinanceTestDomain;
use Tests\Support\Domains\OracleTestDomain;

beforeEach(function () {
    config(['botinker.domains' => ['oracle' => OracleTestDomain::class, 'finance' => FinanceTestDomain::class]]);

    $this->store = makeStore(['conversation_mode' => Store::MODE_KNOWLEDGE]);
    $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'botinker-knowledge-'.uniqid();
    File::makeDirectory($this->dir);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
});

/** The default origin the command derives from a file path. */
function fileOrigin(string $path): string
{
    return 'file:'.str_replace('\\', '/', realpath($path));
}

function knowledgeFile(string $dir, string $relative, string $content): string
{
    $path = $dir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    File::ensureDirectoryExists(dirname($path));
    File::put($path, $content);

    return $path;
}

/** @return array{0: int, 1: string} exit code and console output */
function runCommand(string $command, array $parameters): array
{
    $code = Artisan::call($command, $parameters);

    return [$code, Artisan::output()];
}

describe('botinker:knowledge:ingest', function () {
    it('ingests a Markdown file with its metadata', function () {
        $path = knowledgeFile($this->dir, 'guia.md', "# Sección uno\n\nTexto de prueba uno.\n\n# Sección dos\n\nTexto de prueba dos.");

        [$code, $output] = runCommand('botinker:knowledge:ingest', [
            'store' => $this->store->id, 'domain' => 'oracle', 'path' => $path, '--license' => 'propio', '--title' => 'Guía',
        ]);

        $source = KnowledgeSource::sole();
        expect($code)->toBe(0)
            ->and($output)->toContain("guia.md: fuente #{$source->id} v1 (2 fragmentos)")
            ->and($source->only(['store_id', 'domain_key', 'product_id', 'title', 'origin', 'license', 'language', 'source_type', 'status']))
            ->toBe(['store_id' => $this->store->id, 'domain_key' => 'oracle', 'product_id' => null, 'title' => 'Guía', 'origin' => fileOrigin($path), 'license' => 'propio', 'language' => 'es', 'source_type' => 'markdown', 'status' => 'active']);
    });

    it('reports an unchanged file instead of storing it twice', function () {
        $path = knowledgeFile($this->dir, 'guia.md', "# Uno\n\nTexto.");

        runCommand('botinker:knowledge:ingest', ['store' => $this->store->id, 'domain' => 'oracle', 'path' => $path]);
        [$code, $output] = runCommand('botinker:knowledge:ingest', ['store' => $this->store->id, 'domain' => 'oracle', 'path' => $path]);

        expect($code)->toBe(0)
            ->and($output)->toContain('sin cambios (ya existe como fuente #')
            ->and(KnowledgeSource::count())->toBe(1);
    });

    it('creates a new version of a changed file and retires the previous one', function () {
        $path = knowledgeFile($this->dir, 'guia.md', "# Uno\n\nVersión antigua.");
        runCommand('botinker:knowledge:ingest', ['store' => $this->store->id, 'domain' => 'oracle', 'path' => $path]);

        File::put($path, "# Uno\n\nVersión nueva.");
        [$code, $output] = runCommand('botinker:knowledge:ingest', ['store' => $this->store->id, 'domain' => 'oracle', 'path' => $path]);

        expect($code)->toBe(0)
            ->and($output)->toContain('v2')
            ->and(KnowledgeSource::orderBy('version')->pluck('status')->all())->toBe(['retired', 'active']);
    });

    it('ingests a folder recursively, using each file real path as origin and skipping unsupported files', function () {
        $a = knowledgeFile($this->dir, 'a.md', "# A\n\nTexto A.");
        $b = knowledgeFile($this->dir, 'sub/b.html', '<h1>B</h1><p>Texto B.</p>');
        $c = knowledgeFile($this->dir, 'sub/c.txt', 'Texto C.');
        knowledgeFile($this->dir, 'd.pdf', '%PDF-1.4');

        [$code, $output] = runCommand('botinker:knowledge:ingest', ['store' => $this->store->id, 'domain' => 'oracle', 'path' => $this->dir]);

        expect($code)->toBe(0)
            ->and($output)->toContain('Omitido (extensión no soportada): d.pdf')
            ->and($output)->toContain('sub/b.html: fuente #')
            ->and(KnowledgeSource::orderBy('origin')->get(['origin', 'source_type'])->toArray())->toBe([
                ['origin' => fileOrigin($a), 'source_type' => 'markdown'],
                ['origin' => fileOrigin($b), 'source_type' => 'html'],
                ['origin' => fileOrigin($c), 'source_type' => 'text'],
            ]);
    });

    it('keeps re-ingesting the same folder idempotent and versions only what changed', function () {
        knowledgeFile($this->dir, 'a.md', "# A\n\nTexto A.");
        $b = knowledgeFile($this->dir, 'sub/b.md', "# B\n\nTexto B.");
        runCommand('botinker:knowledge:ingest', ['store' => $this->store->id, 'domain' => 'oracle', 'path' => $this->dir]);

        File::put($b, "# B\n\nTexto B cambiado.");
        [$code, $output] = runCommand('botinker:knowledge:ingest', ['store' => $this->store->id, 'domain' => 'oracle', 'path' => $this->dir]);

        expect($code)->toBe(0)
            ->and($output)->toContain('a.md: sin cambios')
            ->and($output)->toContain('sub/b.md: fuente #')->toContain('v2')
            ->and(KnowledgeSource::where('status', 'active')->count())->toBe(2)
            ->and(KnowledgeSource::where('status', 'retired')->sole()->origin)->toBe(fileOrigin($b));
    });

    it('never treats two different files with the same name as the same source', function () {
        $one = knowledgeFile($this->dir, 'uno/intro.md', "# Intro\n\nPrimera introducción.");
        $two = knowledgeFile($this->dir, 'dos/intro.md', "# Intro\n\nSegunda introducción.");

        runCommand('botinker:knowledge:ingest', ['store' => $this->store->id, 'domain' => 'oracle', 'path' => $one]);
        [$code, $output] = runCommand('botinker:knowledge:ingest', ['store' => $this->store->id, 'domain' => 'oracle', 'path' => $two]);

        expect($code)->toBe(0)
            ->and($output)->toContain('v1')
            ->and(KnowledgeSource::orderBy('id')->get(['origin', 'version', 'status'])->toArray())->toBe([
                ['origin' => fileOrigin($one), 'version' => 1, 'status' => 'active'],
                ['origin' => fileOrigin($two), 'version' => 1, 'status' => 'active'],
            ]);
    });

    it('gives a file the same origin whether ingested alone or within its folder', function () {
        $path = knowledgeFile($this->dir, 'sub/guia.md', "# Uno\n\nTexto.");
        runCommand('botinker:knowledge:ingest', ['store' => $this->store->id, 'domain' => 'oracle', 'path' => $this->dir]);

        File::put($path, "# Uno\n\nTexto actualizado.");
        runCommand('botinker:knowledge:ingest', ['store' => $this->store->id, 'domain' => 'oracle', 'path' => $path]);

        expect(KnowledgeSource::orderBy('version')->get(['origin', 'version', 'status'])->toArray())->toBe([
            ['origin' => fileOrigin($path), 'version' => 1, 'status' => 'retired'],
            ['origin' => fileOrigin($path), 'version' => 2, 'status' => 'active'],
        ]);
    });

    it('versions a logical source by --origin across different files', function () {
        $draft = knowledgeFile($this->dir, 'borrador/guia.md', "# Guía\n\nBorrador.");
        $final = knowledgeFile($this->dir, 'final/guia-v2.md', "# Guía\n\nVersión final.");

        runCommand('botinker:knowledge:ingest', ['store' => $this->store->id, 'domain' => 'oracle', 'path' => $draft, '--origin' => 'guia-oficial']);
        [$code] = runCommand('botinker:knowledge:ingest', ['store' => $this->store->id, 'domain' => 'oracle', 'path' => $final, '--origin' => 'guia-oficial']);

        expect($code)->toBe(0)
            ->and(KnowledgeSource::orderBy('version')->get(['origin', 'version', 'status'])->toArray())->toBe([
                ['origin' => 'guia-oficial', 'version' => 1, 'status' => 'retired'],
                ['origin' => 'guia-oficial', 'version' => 2, 'status' => 'active'],
            ]);
    });

    it('rejects an --origin that is empty or too long for the column', function () {
        $path = knowledgeFile($this->dir, 'guia.md', "# Uno\n\nTexto.");

        foreach (['   ', str_repeat('o', 192)] as $origin) {
            [$code, $output] = runCommand('botinker:knowledge:ingest', ['store' => $this->store->id, 'domain' => 'oracle', 'path' => $path, '--origin' => $origin]);
            expect($code)->toBe(1)->and($output)->toContain('--origin debe tener entre 1 y 191 caracteres');
        }

        expect(KnowledgeSource::count())->toBe(0);
    });

    it('shortens a default origin that would not fit the column, deterministically', function () {
        $deep = str_repeat('carpeta-muy-larga/', 12).'guia.md';
        $path = knowledgeFile($this->dir, $deep, "# Uno\n\nTexto.");

        runCommand('botinker:knowledge:ingest', ['store' => $this->store->id, 'domain' => 'oracle', 'path' => $path]);
        File::put($path, "# Uno\n\nTexto actualizado.");
        runCommand('botinker:knowledge:ingest', ['store' => $this->store->id, 'domain' => 'oracle', 'path' => $path]);

        $origins = KnowledgeSource::orderBy('version')->pluck('origin');
        expect(mb_strlen(fileOrigin($path)))->toBeGreaterThan(191)
            ->and(mb_strlen($origins[0]))->toBeLessThanOrEqual(191)
            ->and($origins[0])->toStartWith('file:…')->toEndWith(substr($origins[0], -17))
            ->and($origins[1])->toBe($origins[0])
            ->and(KnowledgeSource::where('status', 'active')->sole()->version)->toBe(2);
    });

    it('attaches a source to a product of the same store and domain', function () {
        $product = Product::create(['store_id' => $this->store->id, 'domain_key' => 'oracle', 'name' => 'P', 'description' => 'x', 'price' => 1, 'stock' => 1]);
        $path = knowledgeFile($this->dir, 'p.txt', 'Texto del producto.');

        [$code] = runCommand('botinker:knowledge:ingest', ['store' => $this->store->id, 'domain' => 'oracle', 'path' => $path, '--product' => $product->id]);

        expect($code)->toBe(0)
            ->and(KnowledgeSource::sole()->product_id)->toBe($product->id)
            ->and(KnowledgeChunk::sole()->product_id)->toBe($product->id);
    });

    it('rejects invalid input before ingesting anything', function (array $parameters, string $message) {
        $otherStore = makeStore();
        $foreignProduct = Product::create(['store_id' => $otherStore->id, 'domain_key' => 'oracle', 'name' => 'Ajeno', 'description' => 'x', 'price' => 1, 'stock' => 1]);
        $financeProduct = Product::create(['store_id' => $this->store->id, 'domain_key' => 'finance', 'name' => 'F', 'description' => 'x', 'price' => 1, 'stock' => 1]);
        $file = knowledgeFile($this->dir, 'ok.md', "# Uno\n\nTexto.");
        knowledgeFile($this->dir, 'notas.docx', 'x');

        $resolved = array_map(fn ($value) => match ($value) {
            ':store' => $this->store->id,
            ':file' => $file,
            ':dir' => $this->dir,
            ':docx' => $this->dir.DIRECTORY_SEPARATOR.'notas.docx',
            ':foreign' => $foreignProduct->id,
            ':finance' => $financeProduct->id,
            default => $value,
        }, $parameters);

        [$code, $output] = runCommand('botinker:knowledge:ingest', $resolved);

        expect($code)->toBe(1)
            ->and($output)->toContain($message)
            ->and(KnowledgeSource::count())->toBe(0);
    })->with([
        'unknown store' => [['store' => 999999, 'domain' => 'oracle', 'path' => ':file'], 'No existe la tienda [999999]'],
        'unregistered domain' => [['store' => ':store', 'domain' => 'astro', 'path' => ':file'], 'El dominio [astro] no está registrado'],
        'product of another store' => [['store' => ':store', 'domain' => 'oracle', 'path' => ':file', '--product' => ':foreign'], 'no existe en la tienda'],
        'product of another domain' => [['store' => ':store', 'domain' => 'oracle', 'path' => ':file', '--product' => ':finance'], 'no existe en la tienda'],
        'missing path' => [['store' => ':store', 'domain' => 'oracle', 'path' => 'Z:/no/existe.md'], 'La ruta no existe'],
        'unsupported type option' => [['store' => ':store', 'domain' => 'oracle', 'path' => ':file', '--type' => 'pdf'], 'Tipo [pdf] no soportado'],
        'unsupported extension' => [['store' => ':store', 'domain' => 'oracle', 'path' => ':docx'], 'Extensión no soportada'],
        'title with a folder' => [['store' => ':store', 'domain' => 'oracle', 'path' => ':dir', '--title' => 'X'], 'solo se pueden usar con un archivo'],
    ]);

    it('rejects empty, non UTF-8 and oversized files', function () {
        $empty = knowledgeFile($this->dir, 'vacio.md', "   \n");
        $latin1 = knowledgeFile($this->dir, 'latin1.txt', "Canci\xF3n");
        $huge = knowledgeFile($this->dir, 'grande.txt', str_repeat('a', 2 * 1024 * 1024 + 1));

        foreach ([[$empty, 'no text content'], [$latin1, 'UTF-8'], [$huge, 'tamaño máximo']] as [$path, $message]) {
            [$code, $output] = runCommand('botinker:knowledge:ingest', ['store' => $this->store->id, 'domain' => 'oracle', 'path' => $path]);
            expect($code)->toBe(1)->and($output)->toContain($message);
        }

        expect(KnowledgeSource::count())->toBe(0);
    });

    it('never prints or logs the content it ingests', function () {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message.' '.json_encode($event->context);
        });
        $path = knowledgeFile($this->dir, 'secreto.md', "# Encabezado\n\nCONTENIDO-PRIVADO-123 del documento.");

        [, $output] = runCommand('botinker:knowledge:ingest', ['store' => $this->store->id, 'domain' => 'oracle', 'path' => $path]);
        [, $listing] = runCommand('botinker:knowledge:list', ['store' => $this->store->id, 'domain' => 'oracle']);

        expect($output.$listing)->not->toContain('CONTENIDO-PRIVADO-123')
            ->and(implode("\n", $logged))->not->toContain('CONTENIDO-PRIVADO-123');
    });
});

describe('botinker:knowledge:list', function () {
    it('lists only the sources of the given store and domain, with their status', function () {
        $path = knowledgeFile($this->dir, 'guia.md', "# Uno\n\nAntigua.");
        runCommand('botinker:knowledge:ingest', ['store' => $this->store->id, 'domain' => 'oracle', 'path' => $path]);
        File::put($path, "# Uno\n\nNueva.");
        runCommand('botinker:knowledge:ingest', ['store' => $this->store->id, 'domain' => 'oracle', 'path' => $path]);
        runCommand('botinker:knowledge:ingest', ['store' => $this->store->id, 'domain' => 'finance', 'path' => knowledgeFile($this->dir, 'fin.md', '# F')]);
        $other = makeStore();
        runCommand('botinker:knowledge:ingest', ['store' => $other->id, 'domain' => 'oracle', 'path' => knowledgeFile($this->dir, 'ajena.md', '# Ajena')]);

        [$code, $all] = runCommand('botinker:knowledge:list', ['store' => $this->store->id, 'domain' => 'oracle']);
        [, $active] = runCommand('botinker:knowledge:list', ['store' => $this->store->id, 'domain' => 'oracle', '--active' => true]);

        expect($code)->toBe(0)
            ->and($all)->toContain('guia.md')->toContain('retired')->toContain('active')
            ->not->toContain('fin.md')->not->toContain('ajena.md')
            ->and($active)->not->toContain('retired');
    });

    it('fails for an unknown store and reports an empty list', function () {
        expect(runCommand('botinker:knowledge:list', ['store' => 999999, 'domain' => 'oracle'])[0])->toBe(1)
            ->and(runCommand('botinker:knowledge:list', ['store' => $this->store->id, 'domain' => 'oracle'])[1])->toContain('No hay fuentes.');
    });
});

describe('botinker:knowledge:retire', function () {
    beforeEach(function () {
        runCommand('botinker:knowledge:ingest', ['store' => $this->store->id, 'domain' => 'oracle', 'path' => knowledgeFile($this->dir, 'guia.md', "# Uno\n\nTexto.")]);
        $this->source = KnowledgeSource::sole();
    });

    it('retires a source and keeps it and its chunks as history', function () {
        [$code, $output] = runCommand('botinker:knowledge:retire', ['store' => $this->store->id, 'source' => $this->source->id]);

        expect($code)->toBe(0)
            ->and($output)->toContain('retirada; se conserva su historial')
            ->and($this->source->fresh()->status)->toBe('retired')
            ->and($this->source->fresh()->retired_at)->not->toBeNull()
            ->and(KnowledgeChunk::where('knowledge_source_id', $this->source->id)->count())->toBe(1);
    });

    it('never retires a source through another store', function () {
        $other = makeStore();

        [$code, $output] = runCommand('botinker:knowledge:retire', ['store' => $other->id, 'source' => $this->source->id]);

        expect($code)->toBe(1)
            ->and($output)->toContain('no existe en la tienda')
            ->and($this->source->fresh()->status)->toBe('active');
    });

    it('reports a source that is already retired', function () {
        runCommand('botinker:knowledge:retire', ['store' => $this->store->id, 'source' => $this->source->id]);
        [$code, $output] = runCommand('botinker:knowledge:retire', ['store' => $this->store->id, 'source' => $this->source->id]);

        expect($code)->toBe(0)->and($output)->toContain('ya estaba retirada');
    });
});
