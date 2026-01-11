# Bluesky feed for Statamic websites

Bluesky is a social media website built on the Authenticated Transfer Protocol (Atproto), a decentralized protocol for large-scale social web applications. In the spirit of an open web where users keep control of their data, Atproto-based apps like Bluesky lets users have permanent decentralized identifiers (DIDs) for their accounts. Users can also own their data by [hosting their own PDS](https://vhbelvadi.com/bluesky-atproto-pds).

This add-on lets you fetch the latest posts from a Bluesky account – the Bluesky feed of that account – and display it on your Statamic-powered website.

***
**Jump to:** [Features](https://github.com/vhbelvadi/bluesky-feed#features) • [Installation and removal](https://github.com/vhbelvadi/bluesky-feed#installation-and-features) • [Set-up and customisation](https://github.com/vhbelvadi/bluesky-feed#set-u-and-customisation) • [Customisation options](https://github.com/vhbelvadi/bluesky-feed#customisation--options) • [Customisation tags](https://github.com/vhbelvadi/bluesky-feed#customisation--tags) • [Edge cases](https://github.com/vhbelvadi/bluesky-feed#edge-cases) • [Examples](https://github.com/vhbelvadi/bluesky-feed#examples) • [Up next](https://github.com/vhbelvadi/bluesky-feed#up-next)
***

## Features

- Fetch from one Bluesky account site-wide or from multiple Bluesky accounts across different instances of this add-on
- Limit to the last *n* posts (7 by default)
- Disregard replies and only show posts, quotes and reposts (everything is shown by default)
- Disregard everything except original posts
- Show images in posts using a custom tag
- Show details of external links in posts (title and link using custom tags)
- Show verified status (or not)
- Link to the profile in lieu of a follow button (which the Bluesky API currently does not support)
- Cache fetched posts (or not) and for any specified period of time (or 5min by default)

## Installation and removal

Install this add-on via Composer:

``` bash
composer require vhbelvadi/bluesky-feed
```
To remove it, use Composer again:

``` bash
composer remove vhbelvadi/bluesky-feed
```

For more, please consult the [Statamic docs](https://statamic.dev/addons).

### Set-up and customisation

#### Minimal set-up

Once this add-on is installed, you can use it right away in a template:

```
{{ bluesky }}
	{{ items }}
		{{ text }}
	{{ /items }}
{{ /bluesky }}
```

Of course this will not *look* good, and the styling is always going to be up to you, but what this template does is display seven of the latest posts from the official @bsky.app account.

Nobody really wants that, so to set it up with your own account @myaccount.bsky.social you can either supply the handle in the template or via the `.env` file. In your template specify the `handle` and `limit` parameters:

```
{{ bluesky handle="myaccount.bsky.social" limit="4" }}
	{{ items }}
		{{ text }}
	{{ /items }}
{{ /bluesky }}
```

Alternatively, you can specify these in your `.env` file found at the root of your Statamic installation:

```
BLUESKY_HANDLE=myaccount.bluesky.com
BLUESKY_LIMIT=4
```

This can be especially helpful if you want to display the the same bluesky feed at multiple different places across your site. It can also be helpful as your own fallback. **The parameters specified in the template take precedence over those set in the `.env` file** which means you can set up multiple feeds for multiple accounts with different limimts (and other options) across your site with ease while also having your own sensible fallbacks.

#### Customisation – options

This add-on provides a number of useful options best presented in a glanceable table:

| **Option** | **Default** | **Values**           | **Description**                                                |
|:-----------|:------------|:---------------------|:---------------------------------------------------------------|
| handle     | bsky.app    | Various              | Provide a handle without the ‘@’                               |
| limit      | 7           | Any number           | —                                                              |
| cache      | `false`     | `true` `false` `###` | Set `true` for a 5 min cache, or provide the number of seconds |
| replies    | `true`      | `true` `false`       | Include replies in the feed?                                   |
| images     | `true`      | `true` `false`       | Fetch and prepare images? (see tags below)                     |
| external   | `true`      | `true` `false`       | Fetch and prepare external links? (see tags below)             |
| only_posts | `false`	   | `true` `false`       | Only show original posts, no reposts, replies or quotes        |
| boost      | `false`     | `true` `false`       | See [edge cases](https://github.com/vhbelvadi/bluesky-feed#edge-cases) |

The options displayed above are to be set within templates. But in each case an option `foo` can also be set in the `.env` file as `BLUESKY_FOO` as well. It is recommended, unless you prefer to set up multiple different feeds, that you set your options in your `.env` file to make both options and templates simpler.

#### Customisation – tags

As in the above minimal set-up template, this add-on provides the two scopes `{{ bluesky }} ... {{ /bluesky }}` and `{{ items }} ... {{ /items }}` to make possible the use of a couple of tags:

| **Tag**                      | **Scope**        | **Description**                                                               |
|:-----------------------------|:-----------------|:------------------------------------------------------------------------------|
| `{{ handle }}`               | `{{ bluesky }}`  | Displays the handle                                                           |
| `{{ count }}`                | `{{ bluesky }}`  | Displays the limit                                                            |
| `{{ follow_url }}`           | `{{ bluesky }}`  | Displays a link to the Bluesky profile<sup>*</sup>                            |
| `{{ verified }}`             | `{{ bluesky }}`  | Is `true` for verified accounts                                               |
| `{{ name }}`                 | `{{ bluesky }}`  | Displays the account name                                                     |
| `{{ description }}`          | `{{ bluesky }}`  | Displays the profile bio                                                      |
| `{{ text }}`                 | `{{ items }}`    | Displays the original or reposted post content                                |
| `{{ type }}`                 | `{{ items }}`    | Returns `post`, `repost`, `reply` or `quote` type                             |
| `{{ url }}`                  | `{{ items }}`    | Link to the post                                                              |
| `{{ date }}`                 | `{{ items }}`    | Date and time when an original or reposted post was made                      |
| `{{ original_poster }}`      | `{{ items }}`    | The handle of the poster to whom this post was in reply, quoting or reposting |
| `{{ images }}`               | `{{ items }}`    | Block/scope for displaying images (no images are shown by default)            |
| `{{ thumb }}`                | `{{ images }}`   | URL of the image                                                              |
| `{{ alt }}`                  | `{{ images }}`   | Alt text of the image                                                         |
| `{{ external }}`             | `{{ items }}`    | Block/scope for displaying external links                                     |
| `{{ external:url }}`         | `{{ external }}` | URL to the external page                                                      |
| `{{ external:title }}`       | `{{ external }}` | Title of the external page                                                    |
| `{{ external:description }}` | `{{ external }}` | Description of the external page                                              |
| `{{ original_text }}`        | `{{ items }}`    | Displays the content belonging to a quoted post                               |
| `{{ original_avatar }}`      | `{{ items }}`    | Displays the avatar belonging to a quoted post                                |
| `{{ original_date }}`        | `{{ items }}`    | Displays the date of a quoted post                                            |
| `{{ original_image }}`       | `{{ items }}`    | Displays the image belonging to a quoted post                                 |

<sup>*</sup> Since Bluesky does not allow API-led follow buttons (yet), this is the next best thing.

For more about these tags, please see the example templates in the next section.

### Edge cases

Sometimes specifying `replies="false"` or `only_posts="true"` (or their equivalent `.env` options) will fetch fewer posts than is specified in the `limit` or `BLUESKY_LIMIT` option.

In v1.1 a new `boost` option and its equivalent `.env` option `BLUESKY_BOOST` were introduced to fix this edge case. Set `boost="true"` in your template or `BLUESKY_BOOST="true"` in your `.env` file (it is `false` by default) to activate this automatic edge case fix.

## Examples

#### Simple example template

Assuming you have no parameters in your `.env` file, or that you wish to customise this specific template separately, consider the following template:

```
{{ bluesky handle="vhbelvadi.com" limit="10" cache="180" replies="false" }}
	<h4>The {{ count }} most recent posts from @{{ handle }}</h4>
	<div class="bsky-feed">
		{{ items }}
			<div class="bsky-{{ type }}">
				<p><strong>{{ type }}:</strong> {{ text }}</p>
				<time>{{ date }}</time>
				<a href="{{ url }}">Link to post</a>
			</div>
		{{ /items }}
	</div>
{{ /bluesky }}
```

**Observations.** This template introduces the Bluesky account with a simple `h4` title followed by a div with the `bsky-feed` class for styling. It fetches 7 posts, excluding replies, from @vhbelvadi.com on Bluesky and caches the feed for 3min. Each item is given a `bsky-{{ type }}` class to further style post types separately. This ensures unique classes not least because the `quote` type might be already in use for, well, quotes in the main text. The rest of the template shows the `type` of post in bold with the post itself, the date and time timew without any formatting, and a simple link to the post to be viewed on Bluesky. While images are not shown at all, external links may still appear truncated. This is just how the Bluesky API provides this data.

#### Maximal example template

The following template assumes you have options set in your `.env` file and shows images, external links and the handle of the ‘original’ post if one exists, ie, if the current Bluesky post was made in reply to someone, quotes someone or reposts someone.

```
{{ bluesky }}
{{ handle }}
{{ count }}
{{ follow_url }}
{{ if verified }}
  <span class="verified">✔</span>
{{ /if }}
  {{ items }}
    <article class="bsky-{{ type }}">
      <p>{{ text }}</p>
      <a href="{{ url }}" target="_blank">View on Bluesky</a>
      <small>{{ date }}</small>
      {{ type }}

      {{ if original_poster }}
        <small class="block">
            {{ type }} to {{ original_poster }}
        </small>
      {{ /if }}

      {{ if images }}
        {{ images }}
          <img class="block" src="{{ thumb }}" alt="{{ alt }}">
        {{ /images }}
      {{ /if }}

      {{ if external }}
        <a class="block" href="{{ external:url }}">
          <strong>{{ external:title }}</strong>
          <p>{{ external:description }}</p>
        </a>
      {{ /if }}
    </article>
  {{ /items }}
{{ /bluesky }}
```

**Observations.** Showing a tick for a verified post, this template shows images if they exist and external link details if those exist, along with a few small tweaks. Note that copying and pasting this template might not order the information in the best possible manner, but is a good start to customising things in a way you might want them to look.

## Up next

None of the example templates above show any styles. This is intentional, so the templating itself is clear. A minimally styled example can be found [on my website](http://vhbelvadi.com.test/bluesky-feed).

If you have ideas or wish to propose features for this add-on, *please submit a pull request.*

I cannot promise support for this add-on but will try to respond to questions over e-mail if possible. *Please feel free to [send me an e-mail](mailto:hello@vhbelvadi.com).*

Spot errors, odd behaviours etc.? *Please open a new issue.*
