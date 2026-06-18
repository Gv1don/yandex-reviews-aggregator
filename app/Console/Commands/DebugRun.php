<?php

namespace App\Console\Commands;

use App\Services\YandexMapsParser;
use Illuminate\Console\Command;

class DebugRun extends Command
{
    protected $signature = 'debug:run';
    protected $description = 'Test parser with a hardcoded Yandex Maps URL';

    public function handle(): int
    {
        $this->info('Fetching data from Yandex Maps...');

        $parser = new YandexMapsParser();
        $result = $parser->fetchAll('https://yandex.ru/maps/org/ruki_vverkh_/199397811937');

        $this->line("Name: {$result['name']}");
        $this->line("Rating: {$result['average_rating']} / {$result['ratings_count']} ratings");
        $this->line("Reviews: {$result['reviews_count']}");

        $this->newLine();
        $this->table(
            ['#', 'Author', 'Date', 'Rating', 'Text'],
            array_map(fn(array $r, int $i) => [
                $i + 1,
                $r['author'],
                $r['date'] ?? '—',
                str_repeat('★', $r['rating']) . str_repeat('☆', 5 - $r['rating']),
                mb_substr($r['text'], 0, 60) . (mb_strlen($r['text']) > 60 ? '…' : ''),
            ], $result['reviews'], array_keys($result['reviews']))
        );

        return self::SUCCESS;
    }
}
