<?php

use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Cms\Site;

/**
 * Kirby Content Normalizer
 *
 * Provides a CLI command that normalizes content files by re-saving
 * site content, all pages/drafts, and their files with current stored values.
 *
 * @revision 2026-02-13 Initial implementation
 * @revision 2026-02-19 Also normalizes file sidecar metadata for page files
 * @revision 2026-02-24 Also normalizes top-level site content
 */

Kirby::plugin('lemmon/content-normalizer', [
    'commands' => [
        'content:normalize' => [
            'description' => 'Normalize site content, pages/drafts, and file metadata',
            'args' => [],
            'command' => function ($cli): void {
                $kirby = kirby();

                $hashesByFiles = static function (array $files): array {
                    sort($files);
                    $hashes = [];

                    foreach ($files as $file) {
                        $hash = md5_file($file);
                        if ($hash !== false) {
                            $hashes[basename($file)] = $hash;
                        }
                    }

                    return $hashes;
                };

                // Snapshot page txt files so we can report whether
                // serialization changed on disk.
                $pageContentFileHashes = static function (Page $page) use ($hashesByFiles): array {
                    if ($page->exists() === false) {
                        return [];
                    }

                    $files = glob($page->root() . DIRECTORY_SEPARATOR . '*.txt') ?: [];
                    return $hashesByFiles($files);
                };

                // Snapshot top-level site content txt files.
                $siteContentFileHashes = static function (Site $site) use ($hashesByFiles): array {
                    $files = glob($site->root() . DIRECTORY_SEPARATOR . 'site*.txt') ?: [];
                    return $hashesByFiles($files);
                };

                // Snapshot file sidecar metadata txt files for one file.
                $fileMetadataHashes = static function (File $file) use ($hashesByFiles): array {
                    if ($file->exists() === false) {
                        return [];
                    }

                    $sidecars = glob($file->root() . '*.txt') ?: [];
                    return $hashesByFiles($sidecars);
                };

                $result = $kirby->impersonate('kirby', function () use ($kirby, $cli, $siteContentFileHashes, $pageContentFileHashes, $fileMetadataHashes): array {
                    $processedSites = 1;
                    $savedSites = 0;
                    $normalizedSites = 0;
                    $unchangedSites = 0;

                    $processedPages = 0;
                    $savedPages = 0;
                    $normalizedPages = 0;
                    $unchangedPages = 0;

                    $processedFiles = 0;
                    $savedFiles = 0;
                    $normalizedFiles = 0;
                    $unchangedFiles = 0;

                    $failed = [];

                    $site = $kirby->site();

                    try {
                        $beforeSite = $siteContentFileHashes($site);

                        // Re-save current stored site values to normalize file format
                        // without dropping fields.
                        if ($kirby->multilang() === true) {
                            foreach ($kirby->languages() as $language) {
                                $languageCode = $language->code();
                                $data = $site->content($languageCode)->toArray();
                                $site = $site->save($data, $languageCode, true);
                            }
                        } else {
                            $data = $site->content()->toArray();
                            $site = $site->save($data, null, true);
                        }

                        $afterSite = $siteContentFileHashes($site);
                        $siteStatus = '<dim>(unchanged)</dim>';
                        if ($beforeSite !== $afterSite) {
                            $siteStatus = '<cyan>(normalized)</cyan>';
                            $normalizedSites++;
                        } else {
                            $unchangedSites++;
                        }

                        $savedSites++;
                        $cli->out('Saved site: ' . $site->id() . ' ' . $siteStatus);
                    } catch (\Throwable $e) {
                        $failed[] = [
                            'type' => 'site',
                            'id' => 'site',
                            'error' => $e->getMessage(),
                        ];

                        $cli->error('Failed site: site (' . $e->getMessage() . ')');
                    }

                    foreach ($kirby->site()->index(true) as $page) {
                        $processedPages++;

                        try {
                            /** @var Page $page */
                            $before = $pageContentFileHashes($page);

                            // Ensure UUID is initialized before writing content.
                            $page->uuid()->id();

                            // Re-save current stored values to normalize file format
                            // without dropping fields.
                            if ($kirby->multilang() === true) {
                                foreach ($kirby->languages() as $language) {
                                    $languageCode = $language->code();
                                    $data = $page->content($languageCode)->toArray();
                                    $page = $page->save($data, $languageCode, true);
                                }
                            } else {
                                $data = $page->content()->toArray();
                                $page = $page->save($data, null, true);
                            }

                            $after = $pageContentFileHashes($page);
                            $status = '<dim>(unchanged)</dim>';
                            if ($before !== $after) {
                                $status = '<cyan>(normalized)</cyan>';
                                $normalizedPages++;
                            } else {
                                $unchangedPages++;
                            }

                            $savedPages++;
                            $cli->out('Saved page: ' . $page->id() . ' ' . $status);

                            foreach ($page->files() as $file) {
                                $processedFiles++;

                                try {
                                    /** @var File $file */
                                    $beforeFile = $fileMetadataHashes($file);

                                    // Ensure file UUID is initialized before writing metadata.
                                    $file->uuid()?->id();

                                    // Re-save existing file metadata so Kirby creates/migrates
                                    // sidecar files with canonical formatting.
                                    if ($kirby->multilang() === true) {
                                        foreach ($kirby->languages() as $language) {
                                            $languageCode = $language->code();
                                            $fileData = $file->content($languageCode)->toArray();
                                            $file = $file->save($fileData, $languageCode, true);
                                        }
                                    } else {
                                        $fileData = $file->content()->toArray();
                                        $file = $file->save($fileData, null, true);
                                    }

                                    $afterFile = $fileMetadataHashes($file);
                                    $fileStatus = '<dim>(unchanged)</dim>';
                                    if ($beforeFile !== $afterFile) {
                                        $fileStatus = '<cyan>(normalized)</cyan>';
                                        $normalizedFiles++;
                                    } else {
                                        $unchangedFiles++;
                                    }

                                    $savedFiles++;
                                    $cli->out('Saved file: ' . $file->id() . ' ' . $fileStatus);
                                } catch (\Throwable $e) {
                                    $failed[] = [
                                        'type' => 'file',
                                        'id' => $file->id(),
                                        'error' => $e->getMessage(),
                                    ];

                                    $cli->error('Failed file: ' . $file->id() . ' (' . $e->getMessage() . ')');
                                }
                            }
                        } catch (\Throwable $e) {
                            $failed[] = [
                                'type' => 'page',
                                'id' => $page->id(),
                                'error' => $e->getMessage(),
                            ];

                            $cli->error('Failed page: ' . $page->id() . ' (' . $e->getMessage() . ')');
                        }
                    }

                    return [
                        'processedSites' => $processedSites,
                        'savedSites' => $savedSites,
                        'normalizedSites' => $normalizedSites,
                        'unchangedSites' => $unchangedSites,
                        'processedPages' => $processedPages,
                        'savedPages' => $savedPages,
                        'normalizedPages' => $normalizedPages,
                        'unchangedPages' => $unchangedPages,
                        'processedFiles' => $processedFiles,
                        'savedFiles' => $savedFiles,
                        'normalizedFiles' => $normalizedFiles,
                        'unchangedFiles' => $unchangedFiles,
                        'failed' => $failed,
                    ];
                });

                $cli->out('');
                $cli->out('Processed sites: ' . $result['processedSites']);
                $cli->out('Saved sites: ' . $result['savedSites']);
                $cli->out('Normalized sites: <cyan>' . $result['normalizedSites'] . '</cyan>');
                $cli->out('Unchanged sites: ' . $result['unchangedSites']);
                $cli->out('Processed pages: ' . $result['processedPages']);
                $cli->out('Saved pages: ' . $result['savedPages']);
                $cli->out('Normalized pages: <cyan>' . $result['normalizedPages'] . '</cyan>');
                $cli->out('Unchanged pages: ' . $result['unchangedPages']);
                $cli->out('Processed files: ' . $result['processedFiles']);
                $cli->out('Saved files: ' . $result['savedFiles']);
                $cli->out('Normalized files: <cyan>' . $result['normalizedFiles'] . '</cyan>');
                $cli->out('Unchanged files: ' . $result['unchangedFiles']);
                $cli->out('Failed items: ' . count($result['failed']));

                if (empty($result['failed']) === true) {
                    $cli->success('Content normalization completed');
                    return;
                }

                $cli->out('');
                $cli->out('Failures:');

                foreach ($result['failed'] as $failure) {
                    $cli->out('- ' . $failure['type'] . ' ' . $failure['id'] . ': ' . $failure['error']);
                }

                $cli->error('Content normalization completed with errors');
            },
        ],
    ],
]);
