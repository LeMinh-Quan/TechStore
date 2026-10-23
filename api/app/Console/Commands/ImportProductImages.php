<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use FilesystemIterator;
use SplFileInfo;

class ImportProductImages extends Command
{
    // The scrape repeated this exact CellphoneS watermark as a placeholder image.
    private const REPEATED_SCRAPE_PLACEHOLDER_SHA256 = '3d594036466abf265f55d7227846c7dca1737f29f0432b8d365baa34ef01383e';

    protected $signature = 'catalog:import-images {directory : Folder containing product subfolders named by the product URL slug}';

    protected $description = 'Copy local product images to public storage and link them to MySQL products';

    public function handle(): int
    {
        $sourceDirectory = $this->argument('directory');
        if (! is_dir($sourceDirectory) || ! is_readable($sourceDirectory)) {
            $this->components->error("Image directory is not readable: {$sourceDirectory}");

            return self::FAILURE;
        }

        if (! DB::getSchemaBuilder()->hasTable('san_pham_hinh_anh')) {
            $this->components->error('Run php artisan migrate before importing product images.');

            return self::FAILURE;
        }

        $directories = [];
        foreach (new FilesystemIterator($sourceDirectory, FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isDir()) {
                $directories[Str::lower($entry->getFilename())] = $entry->getPathname();
            }
        }

        $products = DB::table('san_pham')
            ->whereNotNull('source_url')
            ->where('source_url', '!=', '')
            ->get(['ma_sp', 'ten_sp', 'source_url']);
        $matchedFolders = [];
        $linkedProducts = 0;
        $copiedImages = 0;
        $skippedPlaceholders = 0;
        $productsWithoutImages = 0;
        $missingFolders = 0;

        foreach ($products as $product) {
            $urlPath = parse_url($product->source_url, PHP_URL_PATH);
            if (! is_string($urlPath) || trim($urlPath, '/') === '') {
                $missingFolders++;
                $productsWithoutImages++;

                continue;
            }

            $slug = pathinfo(basename(rawurldecode($urlPath)), PATHINFO_FILENAME);
            $folderKey = Str::lower($slug);
            if (! isset($directories[$folderKey])) {
                $missingFolders++;
                $productsWithoutImages++;

                continue;
            }

            $matchedFolders[$folderKey] = true;
            $imageFiles = [];
            foreach (new FilesystemIterator($directories[$folderKey], FilesystemIterator::SKIP_DOTS) as $imageFile) {
                if ($imageFile->isFile() && in_array(Str::lower($imageFile->getExtension()), ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $imageFiles[] = $imageFile;
                }
            }
            usort($imageFiles, fn (SplFileInfo $left, SplFileInfo $right) => strnatcasecmp($left->getFilename(), $right->getFilename()));

            $imageOrder = 0;
            foreach ($imageFiles as $imageFile) {
                $storagePath = "catalog/{$product->ma_sp}/".$imageFile->getFilename();
                $imageHash = hash_file('sha256', $imageFile->getPathname());
                if ($imageHash === false) {
                    $this->components->error("Could not read image: {$imageFile->getPathname()}");

                    return self::FAILURE;
                }

                if ($imageHash === self::REPEATED_SCRAPE_PLACEHOLDER_SHA256) {
                    if (Storage::disk('public')->exists($storagePath)
                        && ! Storage::disk('public')->delete($storagePath)) {
                        $this->components->error("Could not remove repeated placeholder image: {$storagePath}");

                        return self::FAILURE;
                    }
                    DB::table('san_pham_hinh_anh')
                        ->where('ma_sp', $product->ma_sp)
                        ->where('duong_dan_anh', $storagePath)
                        ->delete();
                    $skippedPlaceholders++;

                    continue;
                }

                $storedPath = Storage::disk('public')->putFileAs(
                    "catalog/{$product->ma_sp}",
                    new File($imageFile->getPathname()),
                    $imageFile->getFilename(),
                );
                if ($storedPath === false) {
                    $this->components->error("Could not copy image: {$imageFile->getPathname()}");

                    return self::FAILURE;
                }

                DB::table('san_pham_hinh_anh')->updateOrInsert(
                    ['ma_sp' => $product->ma_sp, 'duong_dan_anh' => $storagePath],
                    [
                        'alt_text' => $product->ten_sp,
                        'thu_tu' => $imageOrder++,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
                $copiedImages++;
            }

            if ($imageOrder > 0) {
                $linkedProducts++;
            } else {
                $productsWithoutImages++;
            }
        }

        $this->components->info("Linked {$copiedImages} images to {$linkedProducts} MySQL products.");
        $this->line("Skipped repeated scrape placeholders: {$skippedPlaceholders}.");
        $this->line("Products without a matching image folder: {$missingFolders}.");
        $this->line("Products without a usable product image: {$productsWithoutImages}.");
        $this->line('Unmatched image folders: '.(count($directories) - count($matchedFolders)).'.');
        $this->line('Source files were copied; the original image folder was not modified.');

        return self::SUCCESS;
    }
}
