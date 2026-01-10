<?php

namespace Vhbelvadi\BlueskyFeed\Tags;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Statamic\Tags\Tags;

class Bluesky extends Tags
{
    public function index()
    {
        // Defaults from .env
        $defaultHandle = env("BLUESKY_HANDLE", "bsky.app");
        $defaultLimit = (int) env("BLUESKY_LIMIT", 7);
        $defaultCache = filter_var(
            env("BLUESKY_CACHE", false),
            FILTER_VALIDATE_BOOLEAN,
        );
        $defaultAllowReplies = filter_var(
            env("BLUESKY_REPLIES", true),
            FILTER_VALIDATE_BOOLEAN,
        );
        $defaultImages = filter_var(
            env("BLUESKY_IMAGES", true),
            FILTER_VALIDATE_BOOLEAN,
        );
        $defaultExternal = filter_var(
            env("BLUESKY_EXTERNAL", true),
            FILTER_VALIDATE_BOOLEAN,
        );
        $defaultOnlyPosts = filter_var(
            env("BLUESKY_POSTS", false),
            FILTER_VALIDATE_BOOLEAN,
        );

        // Tag params override
        $handle = $this->params->get("handle", $defaultHandle);
        $limit = (int) $this->params->get("limit", $defaultLimit);
        $cacheEnabled = filter_var(
            $this->params->get("cache", $defaultCache),
            FILTER_VALIDATE_BOOLEAN,
        );
        $allowReplies = filter_var(
            $this->params->get("replies", $defaultAllowReplies),
            FILTER_VALIDATE_BOOLEAN,
        );
        $allowImages = filter_var(
            $this->params->get("images", $defaultImages),
            FILTER_VALIDATE_BOOLEAN,
        );
        $allowExternal = filter_var(
            $this->params->get("external", $defaultExternal),
            FILTER_VALIDATE_BOOLEAN,
        );
        $onlyPosts = filter_var(
            $this->params->get("only_posts", $defaultOnlyPosts),
            FILTER_VALIDATE_BOOLEAN,
        );

        $feed = $cacheEnabled
            ? Cache::remember(
                "bluesky-feed-{$handle}-{$limit}-" .
                    ($onlyPosts ? "post" : "all"),
                300,
                fn() => $this->fetchFeed(
                    $handle,
                    $limit,
                    $allowReplies,
                    $allowImages,
                    $allowExternal,
                    $onlyPosts,
                ),
            )
            : $this->fetchFeed(
                $handle,
                $limit,
                $allowReplies,
                $allowImages,
                $allowExternal,
                $onlyPosts,
            );

        return [
            "items" => $feed["items"],
            "handle" => $handle,
            "count" => count($feed["items"]),
            "verified" => $feed["verified"],
            "follow_url" => "https://bsky.app/profile/{$handle}",
        ];
    }

    protected function fetchFeed(
        string $handle,
        int $limit,
        bool $allowReplies,
        bool $allowImages,
        bool $allowExternal,
        bool $onlyPosts = false,
    ): array {
        // Increase fetch limit if replies are disabled or only_posts is true
        $fetchLimit =
            $allowReplies && !$onlyPosts ? $limit : min($limit * 10, 100);

        // Fetch feed and profile together for caching
        $feedResponse = Http::get(
            "https://public.api.bsky.app/xrpc/app.bsky.feed.getAuthorFeed",
            ["actor" => $handle, "limit" => $fetchLimit],
        );

        $profileResponse = Http::get(
            "https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile",
            ["actor" => $handle],
        );

        if (!$feedResponse->successful()) {
            return [
                "items" => [],
                "verified" => false,
                "follow_link" => "https://bsky.app/profile/{$handle}",
            ];
        }

        $feed = $feedResponse->json("feed", []);
        $profile = $profileResponse->successful()
            ? $profileResponse->json()
            : [];

        // VERIFIED badge detection (profile-level)
        $verified =
            ($profile["verification"]["verifiedStatus"] ?? null) === "valid";

        $items = collect($feed)
            ->map(function ($item) use (
                $allowReplies,
                $allowImages,
                $allowExternal,
                $onlyPosts,
                $handle,
            ) {
                $postView = $item["post"] ?? [];
                $record = $postView["record"] ?? [];
                $embedView = $postView["embed"] ?? null;
                $replyView = $item["reply"] ?? null;
                $reason = $item["reason"] ?? null;

                $type = "post";
                $text = $record["text"] ?? null;
                $originalPoster = null;
                $images = [];
                $external = null;

                // REPOST
                if (
                    ($reason['$type'] ?? null) ===
                    "app.bsky.feed.defs#reasonRepost"
                ) {
                    $type = "repost";
                    $originalPoster = $postView["author"]["handle"] ?? null;
                }
                // REPLY
                elseif (isset($replyView["parent"]["author"]["handle"])) {
                    $type = "reply";
                    $originalPoster = $replyView["parent"]["author"]["handle"];
                }
                // QUOTE
                elseif (
                    isset($embedView['$type']) &&
                    str_starts_with(
                        $embedView['$type'],
                        "app.bsky.embed.record",
                    )
                ) {
                    $type = "quote";
                    $originalPoster =
                        $embedView["record"]["author"]["handle"] ?? null;
                    $text =
                        $record["text"] ??
                        ($embedView["record"]["value"]["text"] ?? $text);
                }

                // IMAGES
                if ($allowImages) {
                    $imageSource = $embedView;
                    if (
                        isset($embedView["record"]["embeds"]) &&
                        is_array($embedView["record"]["embeds"])
                    ) {
                        foreach (
                            $embedView["record"]["embeds"]
                            as $quotedEmbed
                        ) {
                            if (
                                ($quotedEmbed['$type'] ?? null) ===
                                "app.bsky.embed.images#view"
                            ) {
                                $imageSource = $quotedEmbed;
                                break;
                            }
                        }
                    }
                    if (
                        isset($imageSource['$type']) &&
                        $imageSource['$type'] === "app.bsky.embed.images#view"
                    ) {
                        foreach ($imageSource["images"] ?? [] as $img) {
                            $images[] = [
                                "thumb" => $img["thumb"] ?? null,
                                "full" => $img["fullsize"] ?? null,
                                "alt" => $img["alt"] ?? null,
                                "width" => $img["aspectRatio"]["width"] ?? null,
                                "height" =>
                                    $img["aspectRatio"]["height"] ?? null,
                            ];
                        }
                    }
                }

                // EXTERNAL LINKS
                if (
                    $allowExternal &&
                    isset($embedView['$type']) &&
                    str_starts_with(
                        $embedView['$type'],
                        "app.bsky.embed.external",
                    )
                ) {
                    $external = [
                        "url" => $embedView["external"]["uri"] ?? null,
                        "title" => $embedView["external"]["title"] ?? null,
                        "description" =>
                            $embedView["external"]["description"] ?? null,
                        "thumb" => $embedView["external"]["thumb"] ?? null,
                    ];
                }

                // Filter out replies if not allowed
                if (!$allowReplies && $type === "reply") {
                    return null;
                }

                // Filter out anything that isn’t a post if only_posts is true
                if ($onlyPosts && $type !== "post") {
                    return null;
                }

                return [
                    "text" => $text,
                    "url" => $this->uriToUrl($postView["uri"] ?? ""),
                    "date" => $record["createdAt"] ?? null,
                    "type" => $type,
                    "original_poster" => $originalPoster,
                    "images" => $images,
                    "external" => $external,
                    "follow_link" => "https://bsky.app/profile/{$handle}",
                ];
            })
            ->filter()
            ->values()
            ->take($limit)
            ->all();

        return [
            "items" => $items,
            "verified" => $verified,
        ];
    }

    protected function uriToUrl(string $uri): string
    {
        $parts = explode("/", $uri);

        return sprintf(
            "https://bsky.app/profile/%s/post/%s",
            $parts[2] ?? "",
            end($parts),
        );
    }
}
