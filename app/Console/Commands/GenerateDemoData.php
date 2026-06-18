<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Review;
use App\Models\User;
use Illuminate\Console\Command;

class GenerateDemoData extends Command
{
    protected $signature = 'demo:generate {--count=600 : Number of reviews to generate}';
    protected $description = 'Generate demo organization and reviews for testing';

    public function handle(): int
    {
        $user = User::first();
        if (!$user) {
            $this->error('No users found. Run "php artisan db:seed" first.');
            return self::FAILURE;
        }

        $count = (int) $this->option('count');

        $org = Organization::updateOrCreate(
            ['user_id' => $user->id],
            [
                'name' => 'Тестовый Ресторан "Вкусно"',
                'yandex_url' => 'https://yandex.ru/maps/org/test_demo/123456789',
                'org_id' => '123456789',
                'average_rating' => 4.2,
                'ratings_count' => $count,
                'reviews_count' => $count,
            ]
        );

        $ratings = [5, 4, 3, 2, 1];
        $weights = [40, 30, 15, 10, 5];
        $names = [
            'Алексей', 'Мария', 'Дмитрий', 'Елена', 'Сергей',
            'Анна', 'Иван', 'Ольга', 'Павел', 'Наталья',
            'Михаил', 'Екатерина', 'Андрей', 'Татьяна', 'Владимир',
        ];

        $positiveTexts = [
            'Отличное место! Очень вкусная еда и приятная атмосфера.',
            'Прекрасное обслуживание, всегда свежие продукты.',
            'Хожу сюда уже несколько лет, качество не подводит.',
            'Рекомендую всем! Лучшее соотношение цены и качества.',
            'Уютная обстановка, вежливый персонал, вкусная кухня.',
            'Отмечали день рождения, всё прошло замечательно.',
            'Быстрое обслуживание даже в часы пик.',
            'Очень понравилось оформление зала, особенно вечером.',
            'Широкий выбор блюд, есть на любой вкус.',
            'Доставка всегда вовремя, еда горячая и свежая.',
        ];

        $negativeTexts = [
            'Очень долгое обслуживание, ждали заказ больше часа.',
            'Еда была холодной, когда принесли. Испортили впечатление.',
            'Цены завышены, не соответствует качеству.',
            'Персонал грубый и некомпетентный.',
            'Не понравилось, больше не придём.',
            'Грязно в зале, туалет не убирают.',
            'В меню указаны одни цены, в чеке — другие.',
            'Очень шумно, невозможно разговаривать.',
            'Оформление устарело, давно нужен ремонт.',
            'Порции маленькие, не соответствует цене.',
        ];

        $allTexts = [...$positiveTexts, ...$negativeTexts];

        Review::where('organization_id', $org->id)->delete();

        $this->output->progressStart($count);

        $reviews = [];

        for ($i = 0; $i < $count; $i++) {
            $rating = $this->weightedRandom($ratings, $weights);
            $authorName = $names[array_rand($names)] . ' ' . chr(rand(1040, 1071)) . '.';

            $textPool = $rating >= 4 ? $positiveTexts : ($rating <= 2 ? $negativeTexts : $allTexts);

            $reviews[] = [
                'organization_id' => $org->id,
                'author' => $authorName,
                'rating' => $rating,
                'text' => $textPool[array_rand($textPool)],
                'date' => now()->subHours(rand(1, 8760))->format('Y-m-d'),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($reviews) >= 100) {
                Review::insert($reviews);
                $this->output->progressAdvance(count($reviews));
                $reviews = [];
            }
        }

        if (count($reviews) > 0) {
            Review::insert($reviews);
            $this->output->progressAdvance(count($reviews));
        }

        $this->output->progressFinish();

        $this->newLine();
        $this->info("Generated {$count} reviews for '{$org->name}' (avg rating: {$org->average_rating})");

        $counts = Review::where('organization_id', $org->id)
            ->selectRaw('rating, count(*) as cnt')
            ->groupBy('rating')
            ->orderBy('rating', 'desc')
            ->pluck('cnt', 'rating');

        $this->table(
            ['Rating', 'Count'],
            $counts->map(fn($c, $r) => [$r, $c])->values()->toArray()
        );

        return self::SUCCESS;
    }

    private function weightedRandom(array $items, array $weights): mixed
    {
        $total = array_sum($weights);
        $rand = mt_rand(1, $total * 100) / 100;
        $cumulative = 0;

        foreach ($items as $i => $item) {
            $cumulative += $weights[$i];
            if ($rand <= $cumulative) {
                return $item;
            }
        }

        return $items[0];
    }
}
