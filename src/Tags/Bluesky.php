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
            env("BLUESKY_ONLY_POSTS", false),
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
            "name" => $feed["name"],
            "description" => $feed["description"],
            "avatar" => $feed["avatar"],
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
        $boost =
            filter_var(env("BLUESKY_BOOST", false), FILTER_VALIDATE_BOOLEAN) ||
            filter_var(
                $this->params->get("boost", false),
                FILTER_VALIDATE_BOOLEAN,
            );

        $maxLimit = $boost ? min($limit * 50, 500) : min($limit * 10, 100);

        $fetchLimit = $allowReplies && !$onlyPosts ? $limit : $maxLimit;

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
                "name" => null,
                "description" => null,
                "avatar" => null,
                "follow_link" => "https://bsky.app/profile/{$handle}",
            ];
        }

        $feed = $feedResponse->json("feed", []);
        $profile = $profileResponse->successful()
            ? $profileResponse->json()
            : [];

        $verified =
            ($profile["verification"]["verifiedStatus"] ?? null) === "valid";

        $name = $profile["displayName"] ?? null;
        $description = $profile["description"] ?? null;
        $avatar = $profile["avatar"] ?? null;

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
                $text = isset($record["text"])
                    ? $this->applyFacetsToText(
                        $record["text"],
                        $record["facets"] ?? [],
                    )
                    : null;
                $originalPoster = null;
                $originalName = null;
                $originalText = null;
                $originalAvatar = null;
                $originalDate = null;
                $originalImage = null;
                $images = [];
                $external = null;

                // REPOST
                if (
                    ($reason['$type'] ?? null) ===
                    "app.bsky.feed.defs#reasonRepost"
                ) {
                    $type = "repost";
                    $originalPoster = $postView["author"]["handle"] ?? null;
                    $originalName = $postView["author"]["displayName"] ?? null;
                    $originalAvatar = $postView["author"]["avatar"] ?? null;
                    $originalText =
                        $embedView["record"]["value"]["text"] ?? null;
                    $originalDate =
                        $embedView["record"]["value"]["createdAt"] ?? null;

                    if (isset($embedView["record"]["embeds"][0]["images"][0])) {
                        $originalImage =
                            $embedView["record"]["embeds"][0]["images"][0][
                                "fullsize"
                            ] ?? null;
                    }
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
                    $originalName =
                        $embedView["record"]["author"]["displayName"] ?? null;
                    $originalAvatar =
                        $embedView["record"]["author"]["avatar"] ?? null;
                    $originalText =
                        $embedView["record"]["value"]["text"] ?? null;
                    $originalDate =
                        $embedView["record"]["value"]["createdAt"] ?? null;

                    if (isset($embedView["record"]["embeds"][0]["images"][0])) {
                        $originalImage =
                            $embedView["record"]["embeds"][0]["images"][0][
                                "fullsize"
                            ] ?? null;
                    }

                    $text = $record["text"] ?? ($originalText ?? $text);
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

                if (!$allowReplies && $type === "reply") {
                    return null;
                }

                if ($onlyPosts && $type !== "post") {
                    return null;
                }

                return [
                    "text" => $text,
                    "original_text" => $originalText,
                    "original_name" => $originalName,
                    "original_avatar" => $originalAvatar,
                    "original_date" => $originalDate,
                    "original_image" => $originalImage,
                    "url" => $this->uriToUrl($postView["uri"] ?? ""),
                    "date" => $record["createdAt"] ?? null,
                    "type" => $type,
                    "original_poster" => $originalPoster,
                    "original_handle" => $originalPoster,
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
            "name" => $name,
            "description" => $description,
            "avatar" => $avatar,
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

    protected function applyFacetsToText(string $text, array $facets): string
    {
        if (empty($facets)) {
            return $text;
        }

        $bytes = mb_convert_encoding($text, "UTF-8");
        $replacements = [];

        foreach ($facets as $facet) {
            if (
                empty($facet["index"]["byteStart"]) ||
                empty($facet["index"]["byteEnd"]) ||
                empty($facet["features"])
            ) {
                continue;
            }

            foreach ($facet["features"] as $feature) {
                if (
                    ($feature['$type'] ?? null) ===
                    "app.bsky.richtext.facet#link"
                ) {
                    $start = $facet["index"]["byteStart"];
                    $end = $facet["index"]["byteEnd"];

                    $label = mb_strcut($bytes, $start, $end - $start, "UTF-8");
                    $url = $feature["uri"] ?? null;

                    if ($label && $url) {
                        $replacements[] = [
                            "start" => $start,
                            "end" => $end,
                            "replacement" => "[{$label}]({$url})",
                        ];
                    }
                }
            }
        }

        usort($replacements, fn($a, $b) => $b["start"] <=> $a["start"]);

        foreach ($replacements as $r) {
            $bytes =
                mb_strcut($bytes, 0, $r["start"], "UTF-8") .
                $r["replacement"] .
                mb_strcut($bytes, $r["end"], null, "UTF-8");
        }

        return $bytes;
    }
}
