<?php

namespace LiveNetworks\LnStarter\View;

use Illuminate\View\View;

abstract class LNViewComposer
{
    /**
     * Compose the view.
     *
     * Extracts $response['content'] from the view data,
     * passes it to enrich() by reference, and writes it back.
     */
    public function compose(View $view): void
    {
        $response = $view->getData()['response'] ?? ['content' => []];

        if (!is_array($response['content'])) {
            $response['content'] = [];
        }

        $this->enrich($response['content'], $view);

        $view->with('response', $response);
    }

    /**
     * Enrich the response content with additional data.
     *
     * Add secondary data (dropdown options, related records, cached lookups)
     * to the $content array. The controller sets primary data via respondWith();
     * the composer adds everything else the view needs.
     *
     * @param array $content Reference to $response['content']
     * @param View  $view    The Blade view instance
     */
    abstract public function enrich(array &$content, View $view): void;
}
