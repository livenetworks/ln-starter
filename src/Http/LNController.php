<?php

namespace LiveNetworks\LnStarter\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use LiveNetworks\LnStarter\DTOs\Message;

class LNController extends Controller
{
    protected string $view;

    /**
     * Get the current authenticated user
     */
    protected function user()
    {
        return auth()->user();
    }

    /**
     * Authorize an action using Gate
     */
    protected function authorize(string $ability, $arguments = []): void
    {
        Gate::authorize($ability, $arguments);
    }

    /**
     * Check if user can perform action
     */
    protected function can(string $ability, $arguments = []): bool
    {
        return Gate::allows($ability, $arguments);
    }

    /**
     * Set the Blade view for this response
     */
    protected function view(string $view): static
    {
        $this->view = $view;
        return $this;
    }

    /**
     * Respond with content based on request type.
     *
     * - Pure JSON API (Accept: application/json, not XHR): returns JSON
     * - AJAX (XMLHttpRequest): renders Blade through _ajax layout → JSON with sections
     * - Regular browser request: renders full Blade view
     */
    protected function respondWith($content, ?Message $message = null)
    {
        $request = request();

        $response = [
            'message' => $message,
            'content' => $content,
        ];

        // Pure JSON API request
        if ($request->wantsJson() && !$request->ajax()) {
            return response()->json($response);
        }

        // Regular request or UI AJAX: return Blade view
        return view($this->view, [
            'response' => $response,
            'message'  => $message,
        ]);
    }
}
