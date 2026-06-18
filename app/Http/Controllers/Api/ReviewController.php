<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organizationId = $request->input('organization_id');

        if (!$organizationId) {
            return response()->json(['message' => 'organization_id is required.'], 422);
        }

        $organization = Organization::find($organizationId);

        if (!$organization || $organization->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Organization not found.'], 404);
        }

        $perPage = 50;
        $page = $request->integer('page', 1);

        $paginator = $organization->reviews()
            ->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json($paginator);
    }
}
