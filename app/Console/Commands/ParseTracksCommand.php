<?php

namespace App\Console\Commands;

use App\Services\Genius\GeniusCatalogSyncService;
use App\Services\TrackParsing\MuzofondTrackParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ParseTracksCommand extends Command
{
    protected $signature = 'tracks:parse
        {parser : Parser key, for now only "muzofond"}
        {url : Artist page URL or artists listing URL}
        {--artist-limit=0 : Limit how many artists should be parsed from listing pages}
        {--page-limit=0 : Limit pagination depth for parser pages, 0 = no limit}
        {--dry-run : Parse only, do not save anything}';

    protected $description = 'Parse tracks from a remote page and save artists, albums and tracks into the local catalog';

    public function handle(
        MuzofondTrackParser $muzofondParser,
        GeniusCatalogSyncService $geniusCatalogSyncService,
    ): int {
        $parserKey = Str::lower(trim((string) $this->argument('parser')));
        $url = trim((string) $this->argument('url'));
        $artistLimit = max((int) $this->option('artist-limit'), 0);
        $pageLimit = max((int) $this->option('page-limit'), 0);
        $dryRun = (bool) $this->option('dry-run');

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            $this->error('Передан некорректный URL.');

            return self::FAILURE;
        }

        $pages = match ($parserKey) {
            'muzofond' => $muzofondParser->parse($url, $artistLimit, $pageLimit),
            default => null,
        };

        if ($pages === null) {
            $this->error(sprintf('Парсер "%s" не поддерживается.', $parserKey));

            return self::FAILURE;
        }

        if ($pages === []) {
            $this->warn('Парсер не нашёл артистов или треков.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Найдено страниц артистов: %d', count($pages)));

        $savedArtists = 0;
        $savedAlbums = 0;
        $savedTracks = 0;
        $matchedTracks = 0;
        $unmatchedTracks = 0;

        foreach ($pages as $page) {
            $this->newLine();
            $this->line(sprintf('<info>%s</info> [%s]', $page->artistName, $page->artistSlug));
            $this->line(sprintf('Найдено треков на Muzofond: %d', count($page->tracks)));

            if ($dryRun) {
                continue;
            }

            try {
                $result = DB::transaction(fn () => $geniusCatalogSyncService->syncArtistPage($page));

                $savedArtists += $result['artists'];
                $savedAlbums += $result['albums'];
                $savedTracks += $result['tracks'];
                $matchedTracks += $result['matched_tracks'];
                $unmatchedTracks += $result['unmatched_tracks'];

                if ($result['genius_matched']) {
                    $this->info(sprintf(
                        'Genius: найдено совпадение, сохранено треков: %d, пропущено без уверенного матча: %d',
                        $result['matched_tracks'],
                        $result['unmatched_tracks'],
                    ));
                } else {
                    $this->warn('Genius-матч не найден, сохранение выполнено по текущему muzofond-фоллбеку.');
                }
            } catch (Throwable $exception) {
                $this->error(sprintf(
                    'Ошибка при сохранении артиста "%s": %s',
                    $page->artistName,
                    $exception->getMessage(),
                ));
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->info('Dry-run завершён, данные не сохранялись.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info(sprintf(
            'Готово. Новых артистов: %d, альбомов: %d, треков: %d',
            $savedArtists,
            $savedAlbums,
            $savedTracks,
        ));

        if ($matchedTracks > 0 || $unmatchedTracks > 0) {
            $this->line(sprintf(
                'Genius-матчинг: совпало %d, пропущено %d.',
                $matchedTracks,
                $unmatchedTracks,
            ));
        }

        return self::SUCCESS;
    }
}
