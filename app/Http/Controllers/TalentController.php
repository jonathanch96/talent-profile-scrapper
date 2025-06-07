<?php

namespace App\Http\Controllers;

use App\Services\TalentService;
use App\Http\Resources\TalentResource;
use App\Http\Resources\TalentCollection;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Exception;

class TalentController extends Controller
{
    private TalentService $talentService;

    public function __construct(TalentService $talentService)
    {
        $this->talentService = $talentService;
    }

    /**
     * Get all talents (paginated) with optional LLM-powered search
     * GET /api/talents
     *
     * Query Parameters:
     * - per_page: Number of results per page (default: 10)
     * - search_using_llm: Search query for LLM-powered semantic search
     *
     * Examples:
     * - GET /api/talents (regular paginated list)
     * - GET /api/talents?per_page=20 (20 results per page)
     * - GET /api/talents?search_using_llm=video editor with After Effects experience
     * - GET /api/talents?search_using_llm=content creator specializing in travel vlogs&per_page=15
     *
     * LLM Search Process:
     * 1. Converts search query to embedding vector
     * 2. Performs vector similarity search against talent database
     * 3. Ranks results using LLM (0-100 score based on relevance)
     * 4. Returns sorted results by ranking score
     */
    public function index(Request $request): JsonResponse
    {
                                try {
            $perPage = $request->get('per_page', 10);
            $searchUsingLlm = $request->get('search_using_llm');

            $talents = $this->talentService->getAllTalents($perPage, $searchUsingLlm);

            // For LLM search results, return the same structure as TalentCollection
            if ($searchUsingLlm && $talents->count() > 0) {
                $firstItem = $talents->first();
                if (is_array($firstItem)) {
                    // This is LLM search results - return same structure as TalentCollection
                    return response()->json([
                        'success' => true,
                        'data' => $talents->items(),
                        'pagination' => [
                            'page' => $talents->currentPage(),
                            'per_page' => $talents->perPage(),
                            'total' => $talents->total(),
                            'last_page' => $talents->lastPage(),
                        ],
                        'errors' => [],
                    ]);
                }
            }

            // For regular results, use TalentCollection
            return response()->json((new TalentCollection($talents))->toArray($request));
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve talents',
                'errors' => [$e->getMessage()],
            ], 500);
        }
    }

    /**
     * Get talent by username
     * GET /api/talents/{username}
     */
    public function show(string $username): JsonResponse
    {
        try {
            $talent = $this->talentService->getTalentByUsername($username);

            return response()->json([
                'success' => true,
                'data' => (new TalentResource($talent))->toArray(request()),
                'errors' => [],
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => ['Talent not found'],
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve talent',
                'errors' => [$e->getMessage()],
            ], 500);
        }
    }

    /**
     * Update talent by username
     * PUT /api/talents/{username}
     */
    public function update(Request $request, string $username): JsonResponse
    {
        try {
            // Validate the request
            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:255',
                'job_title' => 'sometimes|string|max:255',
                'description' => 'sometimes|string',
                'image' => 'sometimes|string',
                'location' => 'sometimes|string|max:255',
                'timezone' => 'sometimes|string|max:255',
                'talent_status' => 'sometimes|string|max:255',
                'availability' => 'sometimes|string|max:255',
                'experiences' => 'sometimes|array',
                'experiences.*.company' => 'required_with:experiences|string|max:255',
                'experiences.*.role' => 'required_with:experiences|string|max:255',
                'experiences.*.duration' => 'required_with:experiences|string|max:255',
                'experiences.*.client_sub_title' => 'sometimes|string|max:255',
                'experiences.*.client_logo' => 'sometimes|string',
                'experiences.*.description' => 'sometimes|string',
                'projects' => 'sometimes|array',
                'projects.*.title' => 'required_with:projects|string|max:255',
                'projects.*.description' => 'sometimes|string',
                'projects.*.image' => 'sometimes|string',
                'projects.*.link' => 'sometimes|string',
                'projects.*.views' => 'sometimes|integer|min:0',
                'projects.*.likes' => 'sometimes|integer|min:0',
                'projects.*.project_roles' => 'sometimes|array',
                'details' => 'sometimes|array',
                'details.*.name' => 'required_with:details|string|max:255',
                'details.*.values' => 'required_with:details|array',
                'details.*.values.*.title' => 'required|string|max:255',
                'details.*.values.*.icon' => 'sometimes|string',
                'website_url' => 'sometimes|string|max:255',
            ]);

            $talent = $this->talentService->updateTalentByUsername($username, $validatedData);

            return response()->json([
                'success' => true,
                'message' => 'Talent updated successfully',
                'data' => [
                    'username' => $talent->username,
                    'name' => $talent->name,
                ],
                'errors' => [],
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => ['Talent not found'],
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update talent',
                'errors' => [$e->getMessage()],
            ], 500);
        }
    }

    /**
     * Delete talent by username
     * DELETE /api/talents/{username}
     */
    public function destroy(string $username): JsonResponse
    {
        try {
            $this->talentService->deleteTalentByUsername($username);

            return response()->json([
                'success' => true,
                'message' => 'Talent deleted successfully',
                'errors' => [],
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => ['Talent not found'],
            ], 404);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete talent',
                'errors' => [$e->getMessage()],
            ], 500);
        }
    }
}
