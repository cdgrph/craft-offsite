<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

final class ArchiveBuilder
{
    public function zipFile(string $sourcePath, string $targetZipPath, string $entryName): ArchiveResult
    {
        if (!is_file($sourcePath)) {
            throw new \RuntimeException("Source file not found: {$sourcePath}");
        }
        $zip = new \ZipArchive();
        if ($zip->open($targetZipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create zip: {$targetZipPath}");
        }
        // addFile streams the file, so even a large dump never loads into memory
        $zip->addFile($sourcePath, $entryName);
        if (!$zip->close()) {
            throw new \RuntimeException("Failed to finalize zip: {$targetZipPath}");
        }
        $sha256 = hash_file('sha256', $targetZipPath);
        $size = filesize($targetZipPath);
        if ($sha256 === false || $size === false) {
            throw new \RuntimeException("Cannot hash/stat zip: {$targetZipPath}");
        }
        return new ArchiveResult($targetZipPath, $sha256, $size);
    }
}
