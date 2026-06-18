<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\YandexMapsParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class OrganizationController extends Controller
{
    public function __construct(
        private readonly YandexMapsParser $parser,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $organizations = $request->user()->organizations()
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($organizations);
    }

    public function show(Request $request, Organization $organization): JsonResponse
    {
        if ($organization->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $organization->loadCount('reviews');

        return response()->json($organization);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'yandex_url' => ['required', 'url', 'regex:/^https?:\/\/(www\.)?yandex\.(ru|com|by|kz|uz)\/maps\//'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $url = $request->input('yandex_url');

        try {
            $data = $this->parser->fetchAll($url);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'yandex_url' => ['Could not parse the organization ID from the provided URL.'],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => 'Failed to fetch data from Yandex Maps. The parser encountered an error.',
            ], 502);
        }

        $existing = $request->user()->organizations()
            ->where('org_id', $data['org_id'])
            ->first();

        if ($existing) {
            $existing->update([
                'yandex_url' => $url,
                'name' => $data['name'],
                'address' => $data['address'],
                'average_rating' => $data['average_rating'],
                'ratings_count' => $data['ratings_count'] ?? 0,
                'reviews_count' => $data['reviews_count'] ?? 0,
                'parsed_at' => now(),
            ]);

            if (!empty($data['reviews'])) {
                $existing->reviews()->delete();

                $batchSize = 100;
                foreach (array_chunk($data['reviews'], $batchSize) as $chunk) {
                    $reviews = array_map(fn (array $review) => [
                        'organization_id' => $existing->id,
                        'author' => $review['author'] ?? 'Unknown',
                        'date' => $review['date'] ?? now()->format('Y-m-d'),
                        'text' => $review['text'] ?? '',
                        'rating' => $review['rating'] ?? 0,
                        'external_id' => $review['external_id'] ?? null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ], $chunk);

                    $existing->reviews()->insert($reviews);
                }
            }

            $existing->loadCount('reviews');

            return response()->json($existing);
        }

        $organization = $request->user()->organizations()->create([
            'yandex_url' => $url,
            'org_id' => $data['org_id'],
            'name' => $data['name'],
            'address' => $data['address'],
            'average_rating' => $data['average_rating'],
            'ratings_count' => $data['ratings_count'] ?? 0,
            'reviews_count' => $data['reviews_count'] ?? 0,
            'parsed_at' => now(),
        ]);

        if (!empty($data['reviews'])) {
            $batchSize = 100;
            foreach (array_chunk($data['reviews'], $batchSize) as $chunk) {
                $reviews = array_map(fn (array $review) => [
                    'organization_id' => $organization->id,
                    'author' => $review['author'] ?? 'Unknown',
                    'date' => $review['date'] ?? now()->format('Y-m-d'),
                    'text' => $review['text'] ?? '',
                    'rating' => $review['rating'] ?? 0,
                    'external_id' => $review['external_id'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $chunk);

                $organization->reviews()->insert($reviews);
            }
        }

        $organization->loadCount('reviews');

        return response()->json($organization, 201);
    }

    public function destroy(Request $request, Organization $organization): JsonResponse
    {
        if ($organization->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $organization->reviews()->delete();
        $organization->delete();

        return response()->json(['message' => 'Organization deleted']);
    }
}
