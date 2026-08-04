<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FilterPreset\StoreFilterPresetRequest;
use App\Models\TaskFilterPreset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class FilterPresetController extends Controller
{
    #[OA\Get(
        path: '/api/filter-presets',
        tags: ['Filter presets'],
        summary: "List the current user's saved task-list filter presets",
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Filter preset list, newest first')]
    )]
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $request->user()->filterPresets()->latest()->get()
        );
    }

    #[OA\Post(
        path: '/api/filter-presets',
        tags: ['Filter presets'],
        summary: 'Save a task-list filter combination',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(required: ['name', 'filters'], properties: [
                new OA\Property(property: 'name', type: 'string'),
                new OA\Property(
                    property: 'filters',
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'team_id', type: 'integer', nullable: true),
                        new OA\Property(property: 'status', type: 'string', enum: ['pending', 'in_progress', 'completed', 'cancelled'], nullable: true),
                        new OA\Property(property: 'priority', type: 'string', enum: ['low', 'medium', 'high'], nullable: true),
                        new OA\Property(property: 'assigned_to', type: 'integer', nullable: true),
                    ]
                ),
            ])
        ),
        responses: [
            new OA\Response(response: 201, description: 'Preset created'),
            new OA\Response(response: 422, description: 'Validation error (including a duplicate name for this user)'),
        ]
    )]
    public function store(StoreFilterPresetRequest $request): JsonResponse
    {
        $preset = $request->user()->filterPresets()->create($request->validated());

        return response()->json($preset, 201);
    }

    #[OA\Delete(
        path: '/api/filter-presets/{filterPreset}',
        tags: ['Filter presets'],
        summary: 'Delete a saved filter preset (owner only)',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'filterPreset', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 403, description: 'Forbidden'),
        ]
    )]
    public function destroy(Request $request, TaskFilterPreset $filterPreset): JsonResponse
    {
        if ($filterPreset->user_id !== $request->user()->id) {
            abort(403, 'You may only delete your own filter presets.');
        }

        $filterPreset->delete();

        return response()->json(null, 204);
    }
}
