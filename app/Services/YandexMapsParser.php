<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class YandexMapsParser
{
    public const USER_AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36';

    public function extractOrgIdFromUrl(string $url): string
    {
        $parts = parse_url($url);
        $path = $parts['path'] ?? '';

        if (preg_match('/\/org\/[^\/]+\/(\d+)/', $path, $matches)) {
            return $matches[1];
        }

        if (preg_match('/org\/(\d+)/', $path, $matches)) {
            return $matches[1];
        }

        throw new \InvalidArgumentException('Could not extract organization ID from URL');
    }

    public function fetchAll(string $url): array
    {
        $orgId = $this->extractOrgIdFromUrl($url);

        $data = $this->tryHtmlScraping($orgId);

        if ($data === null) {
            $data = $this->tryHeadlessBrowser($url);
            if ($data === null) {
                throw new \RuntimeException(
                    'Could not fetch data from Yandex Maps. ' .
                    'The service may have changed its API or requires additional authentication. ' .
                    'See README for troubleshooting.'
                );
            }

            return $data;
        }

        $headless = $this->tryHeadlessBrowser($url);
        if ($headless !== null) {
            $data['name'] = $headless['name'] ?? $data['name'];
            $data['address'] = $headless['address'] ?? $data['address'];

            if (!empty($headless['reviews'])) {
                $data['reviews'] = $headless['reviews'];
                $data['reviews_count'] = $headless['reviews_count'];
            }

            $data['average_rating'] = $headless['average_rating'] ?? $data['average_rating'];
            $data['ratings_count'] = $headless['ratings_count'] ?? $data['ratings_count'];
        }

        return $data;
    }

    public function tryHtmlScraping(string $orgId): ?array
    {
        try {
            $response = Http::withOptions(['allow_redirects' => true])
                ->withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'Accept-Language' => 'ru-RU,ru;q=0.9',
                ])->get("https://yandex.ru/maps/org/{$orgId}/");

            if ($response->failed()) {
                return null;
            }

            $html = $response->body();

            $data = $this->parseJsonLd($html);
            $name = $data['name'] ?? '';
            $address = $data['address'] ?? null;
            $averageRating = $data['average_rating'] ?? null;
            $ratingsCount = $data['ratings_count'] ?? null;
            $reviews = $this->extractHtmlReviews($html);

            if (empty($reviews)) {
                $reviews = $data['reviews'] ?? [];
            }

            if ($averageRating === null || $ratingsCount === null) {
                $ratingData = $this->extractRatingData($html);
                $averageRating ??= $ratingData['average_rating'];
                $ratingsCount ??= $ratingData['ratings_count'];
            }

            if (empty($address)) {
                $address = $this->extractAddress($html);
            }

            if (empty($name)) {
                if (preg_match('/<meta[^>]+property="og:title"[^>]+content="([^"]+)"/', $html, $m)) {
                    $name = $m[1];
                }
            }

            if (empty($name)) {
                return null;
            }

            if (preg_match('/Yandex\s*Maps/', $name)) {
                return null;
            }

            $name = preg_replace('/\s*[—–-]\s*Яндекс\s*Карты.*$/iu', '', $name);
            $name = trim($name);

            if ($address) {
                $address = preg_replace('/\s*на\s+карте\s+.*$/iu', '', $address);
                $address = trim($address);
            }

            return [
                'org_id' => $orgId,
                'name' => $name,
                'address' => $address,
                'average_rating' => $averageRating,
                'ratings_count' => $ratingsCount,
                'reviews_count' => count($reviews),
                'reviews' => $reviews,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    private function parseJsonLd(string $html): array
    {
        $result = [];

        preg_match_all('/<script[^>]+type="application\/ld+json"[^>]*>(.*?)<\/script>/s', $html, $matches);

        foreach ($matches[1] as $json) {
            $decoded = json_decode(trim($json), true);
            if (!$decoded) {
                continue;
            }

            $items = isset($decoded['@graph']) ? $decoded['@graph'] : [$decoded];

            foreach ($items as $item) {
                $type = $item['@type'] ?? '';
                if (!in_array($type, ['Organization', 'LocalBusiness', 'FoodEstablishment', 'Restaurant', 'Cafe'], true)) {
                    continue;
                }

                $result['name'] = $item['name'] ?? $result['name'] ?? '';

                if (!empty($item['address'])) {
                    if (is_string($item['address'])) {
                        $result['address'] = $item['address'];
                    } else {
                        $addr = $item['address'];
                        $street = $addr['streetAddress'] ?? '';
                        $locality = $addr['addressLocality'] ?? '';
                        $result['address'] = implode(', ', array_filter([$street, $locality]));
                    }
                }

                if (!empty($item['aggregateRating'])) {
                    $rating = $item['aggregateRating'];
                    if (!empty($rating['ratingValue'])) {
                        $result['average_rating'] = (float) $rating['ratingValue'];
                    }
                    if (!empty($rating['ratingCount'])) {
                        $result['ratings_count'] = (int) $rating['ratingCount'];
                    }
                }

                if (!empty($item['review'])) {
                    $reviews = is_array($item['review']) ? $item['review'] : [];
                    if (isset($reviews['@type'])) {
                        $reviews = [$reviews];
                    }
                    $result['reviews'] = array_map(fn ($r) => [
                        'author' => $r['author']['name'] ?? $r['author'] ?? 'Unknown',
                        'date' => $r['datePublished'] ?? null,
                        'text' => $r['description'] ?? '',
                        'rating' => (int) ($r['reviewRating']['ratingValue'] ?? 0),
                    ], $reviews);
                }
            }
        }

        return $result;
    }

    private function extractHtmlReviews(string $html): array
    {
        $reviews = [];

        preg_match_all('/<div[^>]*class="[^"]*business-review-view[^"]*"[^>]*itemProp="review"[^>]*>.*?<div[^>]*class="[^"]*business-review-view__body[^"]*"[^>]*>(.*?)<\/div>/s', $html, $blocks);

        foreach ($blocks[0] as $i => $fullBlock) {
            $text = trim(strip_tags($blocks[1][$i]));
            if (mb_strlen($text) <= 10) {
                continue;
            }

            $author = '';
            if (preg_match('/<span[^>]*itemProp="name"[^>]*>(.*?)<\/span>/s', $fullBlock, $m)) {
                $author = trim(strip_tags($m[1]));
            }

            $rating = 0;
            if (preg_match('/aria-label="(?:Rating|Рейтинг|Оценка)\s*(\d+)\s*(?:Out\s+of|из)\s*\d+/si', $fullBlock, $m)) {
                $rating = (int) $m[1];
            }

            $date = null;
            if (preg_match('/<meta[^>]+itemProp="datePublished"[^>]+content="([^"]+)"/s', $fullBlock, $m)) {
                $parsed = date_parse($m[1]);
                if ($parsed && $parsed['year']) {
                    $date = sprintf('%04d-%02d-%02d', $parsed['year'], $parsed['month'], $parsed['day']);
                }
            }

            $reviews[] = [
                'author' => $author ?: 'Anonymous',
                'date' => $date ?: null,
                'text' => $text,
                'rating' => $rating,
            ];
        }

        return $reviews;
    }

    private function extractAddress(string $html): ?string
    {
        if (preg_match('/<a[^>]*class="[^"]*orgpage-header-view__address[^"]*"[^>]*title="([^"]+)"/', $html, $m)) {
            return $m[1];
        }
        return null;
    }

    private function extractRatingData(string $html): array
    {
        $ratingValue = null;
        $ratingCount = null;

        if (preg_match('/"ratingData"\s*:\s*\{[^}]*"ratingValue"\s*:\s*([\d.]+)/s', $html, $m)) {
            $ratingValue = (float) $m[1];
        }
        if (preg_match('/"ratingData"\s*:\s*\{[^}]*"ratingCount"\s*:\s*(\d+)/s', $html, $m)) {
            $ratingCount = (int) $m[1];
        }

        if ($ratingValue !== null && $ratingCount !== null) {
            return [
                'average_rating' => $ratingValue,
                'ratings_count' => $ratingCount,
            ];
        }

        if (preg_match('/<span[^>]*class="[^"]*business-rating-badge-view__rating-text[^"]*"[^>]*>([\d.,]+)<\/span>/', $html, $m)) {
            return ['average_rating' => (float) str_replace(',', '.', $m[1]), 'ratings_count' => null];
        }

        if (preg_match('/<meta[^>]+itemProp="ratingValue"[^>]+content="([\d.]+)"/', $html, $m)) {
            return ['average_rating' => (float) $m[1], 'ratings_count' => null];
        }

        return ['average_rating' => null, 'ratings_count' => null];
    }

    private function tryHeadlessBrowser(string $url): ?array
    {
        $scraperUrl = env('SCRAPER_BASE_URL', 'http://scraper:3099');

        try {
            $response = Http::timeout(120)
                ->post("{$scraperUrl}/scrape", ['url' => $url]);

            if ($response->failed()) {
                return null;
            }

            $data = $response->json();
            if (!$data || isset($data['error'])) {
                return null;
            }

            $orgId = $this->extractOrgIdFromUrl($url);

            $reviews = array_map(function (array $r) {
                $date = $r['date'] ?? null;
                if ($date && preg_match('/^\d{4}-\d{2}-\d{2}/', $date, $m)) {
                    $date = $m[0];
                }
                return [
                    'author' => $r['author'] ?? 'Anonymous',
                    'date' => $date,
                    'text' => $r['text'] ?? '',
                    'rating' => (int) ($r['rating'] ?? 0),
                    'external_id' => $r['external_id'] ?? null,
                ];
            }, $data['reviews'] ?? []);

            $name = $data['org']['title'] ?? $orgId;
            $name = preg_replace('/\s*[—–-]\s*Яндекс\s*Карты.*/iu', '', $name);
            $name = trim($name);

            $address = $data['org']['address'] ?? null;
            if ($address) {
                $address = preg_replace('/\s*на\s+карте\s+.*/iu', '', $address);
                $address = trim($address);
            }

            return [
                'org_id' => $orgId,
                'name' => $name,
                'address' => $address,
                'average_rating' => $data['rating'] ?? null,
                'ratings_count' => $data['ratings_count'] ?? null,
                'reviews_count' => count($reviews),
                'reviews' => $reviews,
                'yandex_url' => $url,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }
}
